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
 * Partitioning strategy: `(farm_type, region, year(date))` — NOT `farm_id`.
 * This is a deliberate choice to serve both consumers of this same physical
 * data with one layout, rather than maintaining two:
 *
 * - BigQuery's cross-farm benchmarking/practice-wide queries filter by
 *   cohort attributes (farm_type, region), never by an individual farm_id —
 *   partitioning on farm_id would give those queries zero pruning benefit
 *   and force scanning data scattered across thousands of unrelated
 *   directories. Partitioning on (farm_type, region, year) gives BigQuery
 *   real, direct pruning on exactly what it filters by.
 * - DuckDB's single-farm report queries lose farm-level partition pruning as
 *   a result — a query for one farm now has to scan its whole cohort
 *   partition (~20-30 farms), not just its own directory. This is a
 *   deliberately cheap trade: real per-farm data (Figured's largest farms
 *   run ~180K-800K total journal rows) means even a full cohort partition is
 *   only single-digit millions of rows — trivial for DuckDB to scan and
 *   filter, regardless.
 * - To recover *some* of DuckDB's lost precision, farm_id is used as a
 *   physical SORT key (not a partition key) within each partition's file(s):
 *   seed/insert code is expected to ORDER BY farm_id, so that Parquet
 *   row-group min/max statistics let DuckDB's reader skip row groups outside
 *   the target farm_id's range even without a farm_id partition. DuckLake
 *   has no separate "cluster by"/sort-key concept distinct from
 *   PARTITIONED BY — this has to be enforced by insert order, and verified
 *   empirically (see DuckDbCashFlowPartitionCheckCommand), not assumed.
 *
 * IMPORTANT CAVEAT (not yet solved, flagged honestly): this sort-order
 * benefit only holds as long as data is written in farm_id order. Real
 * incremental writes (new transactions arriving farm-by-farm, not in bulk
 * sorted batches) will NOT naturally stay farm_id-sorted over time — a real
 * system would need periodic compaction with an explicit re-sort to
 * maintain this. Not designed here; noted as a known gap for Phase 5+.
 *
 * DuckLake can only partition a table by ITS OWN columns, not a joined
 * dimension table's — that's why farm_type/region are denormalized directly
 * onto transaction_lines, not just left in the `farms` table.
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
            SET PARTITIONED BY (farm_type, region, year(date))
            SQL);
    }
}
