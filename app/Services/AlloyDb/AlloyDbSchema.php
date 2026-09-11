<?php

declare(strict_types=1);

namespace App\Services\AlloyDb;

use Illuminate\Database\ConnectionInterface;

/**
 * The AlloyDB side of the comparison: the same facts and dimensions the DuckDB
 * path splits across DuckLake and MySQL, held in one PostgreSQL database.
 *
 * That single-database shape is the whole point of testing AlloyDB. The DuckDB
 * path needs a lake, a catalog, and a federated join to MySQL for every report;
 * here `transaction_lines` and `accounts` are tables in the same instance, so
 * the join is ordinary.
 *
 * Deliberately NOT partitioned, despite the DuckLake side being partitioned by
 * (farm_id, basis, year). Two reasons: Postgres partitioning is a different
 * mechanism with different trade-offs, so mirroring DuckLake's layout would
 * measure a translation rather than AlloyDB's own idiom; and the columnar
 * engine populates per leaf partition, which adds a variable before the
 * baseline exists. Add it as a later experiment if the flat table hits a wall.
 */
final class AlloyDbSchema
{
    /**
     * Columns the Gross Margin report scans. Used to populate the columnar
     * engine, which holds its store in memory — so this list is a memory
     * budget, not just an access list. `line_id` is excluded because nothing
     * reads it, and on the DuckLake side it was measured at ~56% of file bytes.
     */
    public const array COLUMNAR_COLUMNS = ['farm_id', 'basis', 'date', 'type', 'account_id', 'amount'];

    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
    }

    public function create(): void
    {
        $this->db->statement('DROP TABLE IF EXISTS transaction_lines');
        $this->db->statement('DROP TABLE IF EXISTS tracker_stock_movements');
        $this->db->statement('DROP TABLE IF EXISTS tracker_milk_production');
        $this->db->statement('DROP TABLE IF EXISTS trackers');
        $this->db->statement('DROP TABLE IF EXISTS accounts');
        $this->db->statement('DROP TABLE IF EXISTS farms');

        $this->db->statement(<<<'SQL'
            CREATE TABLE farms (
                farm_id   TEXT PRIMARY KEY,
                farm_type TEXT NOT NULL,
                region    TEXT NOT NULL
            )
            SQL);

        $this->db->statement(<<<'SQL'
            CREATE TABLE accounts (
                account_id         TEXT PRIMARY KEY,
                account_name       TEXT NOT NULL,
                account_class      TEXT NOT NULL,
                account_category   TEXT NOT NULL,
                report_group       TEXT,
                report_group_label TEXT,
                report_group_order SMALLINT NOT NULL DEFAULT 0,
                line_order         SMALLINT NOT NULL DEFAULT 0
            )
            SQL);

        $this->db->statement(<<<'SQL'
            CREATE TABLE trackers (
                tracker_id    TEXT PRIMARY KEY,
                farm_id       TEXT NOT NULL REFERENCES farms(farm_id),
                tracker_name  TEXT NOT NULL,
                tracker_type  TEXT NOT NULL,
                stock_type    TEXT NOT NULL,
                opening_stock INTEGER NOT NULL DEFAULT 0,
                display_order SMALLINT NOT NULL DEFAULT 0
            )
            SQL);

        $this->db->statement(<<<'SQL'
            CREATE TABLE tracker_milk_production (
                id              BIGSERIAL PRIMARY KEY,
                tracker_id      TEXT NOT NULL REFERENCES trackers(tracker_id),
                month           DATE NOT NULL,
                kg_ms_current   INTEGER NOT NULL DEFAULT 0,
                kg_ms_deferred  INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        $this->db->statement(<<<'SQL'
            CREATE TABLE tracker_stock_movements (
                id         BIGSERIAL PRIMARY KEY,
                tracker_id TEXT NOT NULL REFERENCES trackers(tracker_id),
                month      DATE NOT NULL,
                purchases  INTEGER NOT NULL DEFAULT 0,
                births     INTEGER NOT NULL DEFAULT 0,
                sales      INTEGER NOT NULL DEFAULT 0,
                deaths     INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        // No foreign key on farm_id or account_id: at 500M rows the constraint
        // check would dominate load time, and the DuckLake side has no such
        // check either. Keeping the write paths comparable matters more than
        // referential integrity in a throwaway comparison.
        $this->db->statement(<<<'SQL'
            CREATE TABLE transaction_lines (
                farm_id    TEXT NOT NULL,
                farm_type  TEXT NOT NULL,
                region     TEXT NOT NULL,
                line_id    TEXT NOT NULL,
                account_id TEXT NOT NULL,
                type       TEXT NOT NULL,
                basis      TEXT NOT NULL,
                date       DATE NOT NULL,
                amount     BIGINT NOT NULL,
                tracker_id TEXT
            )
            SQL);
    }

    /**
     * Built after loading, not before: maintaining a btree during a 500M-row
     * insert costs far more than one sorted build at the end.
     */
    public function index(): void
    {
        $this->db->statement(
            'CREATE INDEX IF NOT EXISTS transaction_lines_scope_idx
             ON transaction_lines (farm_id, basis, date)'
        );
        $this->db->statement('ANALYZE transaction_lines');
        $this->db->statement('ANALYZE accounts');
    }

    /**
     * Loads the report's columns into the in-memory column store.
     *
     * Returns the reported size so a caller can compare it against
     * `google_columnar_engine.memory_size_in_mb` — the ratio is what decides
     * where this approach stops scaling, and it is the number the ceiling test
     * is really hunting.
     *
     * @return list<array<string, mixed>>
     */
    public function columnarize(): array
    {
        foreach (self::COLUMNAR_COLUMNS as $column) {
            $this->db->statement(
                "SELECT google_columnar_engine_add('transaction_lines', ?)",
                [$column]
            );
        }

        // `_add` is a no-op on a column already registered, so after a reload it
        // leaves the previous snapshot in memory — the store reported 1.3 KB for
        // a million rows because it still held the 620-row version. Refreshing
        // rebuilds it against current heap contents, and without this every
        // capacity and bytes-per-row figure on the report page is a fiction.
        $this->db->statement("SELECT google_columnar_engine_refresh('transaction_lines')");

        return array_map(
            static fn (object $row): array => (array) $row,
            $this->db->select(
                "SELECT column_name,
                        pg_size_pretty(size_in_bytes) AS in_memory,
                        size_in_bytes,
                        status
                 FROM g_columnar_columns
                 WHERE relation_name = 'transaction_lines'
                 ORDER BY size_in_bytes DESC"
            )
        );
    }
}
