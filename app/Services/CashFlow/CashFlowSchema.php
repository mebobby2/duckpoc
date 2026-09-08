<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Saturio\DuckDB\DuckDB;

/**
 * Creates the DuckLake tables Cash Flow needs: transaction lines (the bulk of
 * report data), an accounts dimension (drives section assignment), and a
 * farms dimension (cohort attributes + per-farm opening balance config).
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
        $this->db->query("DROP TABLE IF EXISTS {$this->alias}.accounts");
        $this->db->query("DROP TABLE IF EXISTS {$this->alias}.farms");

        $this->createAccounts();
        $this->createFarms();
        $this->createTransactionLines();
    }

    private function createAccounts(): void
    {
        // No PRIMARY KEY — DuckLake does not support PRIMARY KEY/UNIQUE
        // constraints at all (confirmed by actually running this: "Not
        // implemented Error: PRIMARY KEY/UNIQUE constraints are not
        // supported in DuckLake"). Consistent with other lakehouse table
        // formats (Iceberg/Delta) — uniqueness isn't server-enforced;
        // seed/query code is responsible for not producing duplicate ids.
        // account_class mirrors Figured's Xero account class. It exists
        // separately from account_category because it drives a different
        // decision: `XeroAccount::isAccountInversedForUser()` returns true for
        // REVENUE and only REVENUE, and that is what makes the report flip
        // revenue's stored credit (negative) into the positive figure a reader
        // expects. account_category drives which report SECTION a line lands
        // in; account_class drives the display sign.
        $this->db->query(<<<SQL
            CREATE TABLE {$this->alias}.accounts (
                account_id VARCHAR NOT NULL,
                account_name VARCHAR NOT NULL,
                account_class VARCHAR NOT NULL,
                account_category VARCHAR NOT NULL,
                is_gst_account BOOLEAN NOT NULL DEFAULT false,
                is_default_bank_account BOOLEAN NOT NULL DEFAULT false
            )
            SQL);
    }

    private function createFarms(): void
    {
        // farm_type/region here are the source of truth the application
        // layer resolves farm_id -> cohort from, before building a
        // DuckDB query with explicit farm_type/region/farm_id predicates
        // that let partition pruning engage. Also denormalized onto every
        // transaction_lines row (see createTransactionLines) since that's
        // what's actually partitioned on.
        $this->db->query(<<<SQL
            CREATE TABLE {$this->alias}.farms (
                farm_id VARCHAR NOT NULL,
                farm_type VARCHAR NOT NULL,
                region VARCHAR NOT NULL,
                opening_balance BIGINT NOT NULL
            )
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
                amount BIGINT NOT NULL
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
