<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Saturio\DuckDB\DuckDB;

/**
 * Creates the one DuckLake table Cash Flow needs: `transaction_lines`, the
 * financial line data.
 *
 * The `accounts` and `farms` dimensions live in MySQL instead — see
 * `database/migrations/..._create_cashflow_dimension_tables.php` for why. Only
 * fact data belongs in the lake, mirroring how Figured places its own data.
 *
 * Partitioning strategy: `(farm_id, basis, year(date))`.
 *
 * Chosen by measurement, not principle. Every key is a filter that every
 * customer report applies, and the cost difference between a partition key and
 * an ordinary column is large: a partition-key predicate is resolved from the
 * catalog for ~1 request, while an ordinary-column predicate costs roughly one
 * request per row group per file. On a 2-month single-farm report the four
 * candidate layouts measured:
 *
 *     (farm_type, region, year)            22 requests   3,885 ms
 *     (farm_type, region, farm_id, year)   11 requests   1,454 ms
 *     (farm_id, basis, year)                7 requests   1,378 ms   <- this
 *     (farm_id, basis, type, year)         14 requests   1,280 ms
 *
 * `basis` is safe as a key because a report reads exactly one basis —
 * Figured's `ReportStructure::getBasis(): string` returns a single value, and
 * `OverdraftLimitsStructureBuilder` even hard-codes `Basis::CASH`.
 *
 * `type` (actuals/forecast) was rejected despite looking attractive: it
 * produced 1,665 files for 160 partitions, because the report's horizon
 * predicate spans both values so neither partition can be pruned, and the
 * extra key just fragments writes.
 *
 * WHY NOT `(farm_type, region, ...)` ANY MORE: that layout existed to serve
 * BigQuery's cross-farm queries from the same physical files. That is no longer
 * the plan — BigQuery is served by a separate dbt-derived layer, because
 * DuckLake writes row deletions to sidecar `-delete.parquet` files that only
 * DuckDB resolves. A raw Parquet reader saw 100,000 rows where the table held
 * 60,000, which makes a shared layout not merely suboptimal but incorrect.
 * Freed of that constraint, this layout optimises purely for single-farm
 * reporting.
 *
 * `farm_type` and `region` REMAIN as columns, deliberately. They are no longer
 * partition keys and no report filters on them, but the BigQuery export layer
 * wants them for clustering, and they cost nothing while unread.
 *
 * On sorting: DuckLake has no sort-key concept, so any ordering must come from
 * insert order and is lost on compaction. It also interacts badly with
 * partitioning — an `ORDER BY date` against a farm-leading partition key
 * scatters each partition's rows through the sorted stream and fragments the
 * write (the 1,665-file result above). If a sort is used, lead with the
 * partition keys. In practice it barely matters: a real farm holds ~800K rows
 * over 10 years, so a farm-basis-year partition is ~40K rows and row groups
 * hold 2,621,440 — an entire partition is one row group, leaving nothing for
 * row-group statistics to prune.
 *
 * IMPORTANT: DuckLake partitioning only applies to data written AFTER
 * `SET PARTITIONED BY` runs — existing rows are not retroactively
 * repartitioned (https://ducklake.select/docs/stable/duckdb/advanced_features/partitioning).
 * That's why partitioning is applied here immediately after CREATE TABLE,
 * before any seed command has a chance to insert rows.
 */
final class CashFlowSchema
{
    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
    ) {
    }

    /**
     * Drops and recreates all Cash Flow PoC tables. Safe to re-run —
     * deliberately destructive so schema iteration during development starts
     * from a known-clean state each time.
     */
    public function recreate(): void
    {
        $this->db->query("DROP TABLE IF EXISTS {$this->alias}.transaction_lines");

        // Dimension tables moved to MySQL (see the migration). Dropped here so
        // a lake left over from before the move doesn't keep serving stale
        // copies that shadow the real ones.
        $this->db->query("DROP TABLE IF EXISTS {$this->alias}.accounts");
        $this->db->query("DROP TABLE IF EXISTS {$this->alias}.farms");

        $this->createTransactionLines();
    }

    /**
     * Rows per Parquet row group for everything this lake writes.
     *
     * DuckDB's default is 122,880, which is tuned for local disk where a read
     * per row group is nearly free. Over object storage it is the dominant
     * cost, because DuckDB issues one HTTP range request per
     * (row group x column): the tracker report on the billion-row farm made
     * **4,247 GETs to transfer 26.5 MiB**, and at ~12.5 ms per request that
     * was 53 of its 55 seconds — against 1.7 s of actual SQL.
     *
     * 5,000,000 — the top of DuckDB's recommended 1-5M range for remote
     * storage. Larger row groups mean fewer, fatter requests, so per-request
     * latency is amortised over far more rows.
     *
     * Raised from 1,000,000 after measuring the request arithmetic directly:
     * requests = files x row_groups_per_file x columns_read. On the 500M-row
     * farm a 2-month window issued 2,379 requests, which is exactly
     * 12 files x 19 row groups x 10 columns. Row group COUNT is the driver, so
     * merging files into bigger ones changes nothing on its own — compaction
     * rewrites at the same row group size and leaves the total unchanged.
     *
     * The setting alone is not enough. At 1,000,000 the files actually came
     * out at 917,504 rows per group, because `write_buffer_row_group_memory_limit`
     * (250 MiB by default) fills before the row count target is reached and
     * flushes early. `applyWriteTuning()` raises it so the requested size can
     * actually be achieved.
     */
    private const int PARQUET_ROW_GROUP_SIZE = 5_000_000;

    /**
     * Session settings that let `parquet_row_group_size` actually be reached.
     *
     * Session `SET`s, not catalog options, so unlike `ensureWriteOptions()`
     * they do not persist — every writing process has to apply them. Kept
     * beside the row group size because they are meaningless apart from it:
     * without the raised buffer the size target is silently capped, which is
     * how a requested 1,000,000 came out as 917,504.
     *
     * Buffers one large row group rather than five smaller ones, since only
     * the size matters here and a wider buffer costs memory for no benefit.
     */
    public function applyWriteTuning(): void
    {
        $this->db->query("SET write_buffer_row_group_memory_limit='1GB'");
        $this->db->query('SET write_buffer_row_group_count=1');
    }

    /**
     * Persists the lake's write options in the catalog.
     *
     * A DuckLake option set this way is stored in the catalog and applies to
     * every subsequent write, so this only needs to run when the lake is
     * created or a setting changes — not per connection, which would make
     * every read a catalog write.
     *
     * It does NOT rewrite existing files. Parquet row groups are fixed when a
     * file is written, so data already in the lake keeps whatever row group
     * size was in force at the time. Benefiting from a change means rewriting
     * that data.
     */
    public function ensureWriteOptions(): void
    {
        $this->db->query(sprintf(
            "CALL %s.set_option('parquet_row_group_size', '%d')",
            $this->alias,
            self::PARQUET_ROW_GROUP_SIZE,
        ));
    }

    /**
     * Adds `tracker_id` to an existing table without dropping it.
     *
     * Journal lines carry a tracker tag so per-tracker report sections can be
     * resolved by grouping one scan, rather than by a query per tracker the
     * way `LivestockQuantities` does today. Nullable because most lines —
     * operating expenses, GST, equity movements — belong to no tracker.
     *
     * Kept separate from `recreate()` so adopting the column costs nothing:
     * re-seeding ~880K rows is quick, but there is no reason to make a purely
     * additive schema change destructive. Existing rows read back NULL.
     * Idempotent, so it is safe on every deploy.
     *
     * `tracker_id` is deliberately NOT a partition key. It is high-cardinality
     * and partitioning on it would multiply file count — and per-file round
     * trips are the measured dominant cost here (see the README's pruning
     * section). Row-group statistics handle the filtering instead.
     */
    public function addTrackerColumn(): void
    {
        $this->db->query(<<<SQL
            ALTER TABLE {$this->alias}.transaction_lines
            ADD COLUMN IF NOT EXISTS tracker_id VARCHAR
            SQL);
    }

    private function createTransactionLines(): void
    {
        $this->db->query(<<<SQL
            CREATE TABLE {$this->alias}.transaction_lines (
                farm_id VARCHAR NOT NULL,
                farm_type VARCHAR NOT NULL,
                region VARCHAR NOT NULL,
                line_id VARCHAR NOT NULL,
                account_id VARCHAR NOT NULL,
                type VARCHAR NOT NULL,
                basis VARCHAR NOT NULL,
                date DATE NOT NULL,
                amount BIGINT NOT NULL,
                tracker_id VARCHAR
            )
            SQL);

        // See class docblock for why (farm_type, region, year) instead of
        // farm_id.
        $this->db->query(<<<SQL
            ALTER TABLE {$this->alias}.transaction_lines
            SET PARTITIONED BY (farm_id, basis, year(date))
            SQL);
    }
}
