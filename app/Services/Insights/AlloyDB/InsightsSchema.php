<?php

declare(strict_types=1);

namespace App\Services\Insights\AlloyDB;

use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * The end state the Insights PoC assumes: Figured's MySQL tables and its Mongo
 * journals collapsed into one AlloyDB, so FIP can read the same rows the
 * webapp does instead of a synced copy of the webapp's report output.
 *
 * Tables keep their MySQL names and columns (farms, farm_practice,
 * xero_accounts, categories, category_xero_account, milk_trackers,
 * milk_productions), including the `_valid_from` / `_valid_to` pair, so a
 * query that works here is a query against the production shape. Every row
 * seeded is current (`_valid_to IS NULL`).
 *
 * Three deliberate departures, each because the MySQL/Mongo shape cannot be
 * queried set-wise and the real migration would have to make the same change:
 *
 *  - Journals. Mongo's `transactions` documents become `transactions` plus
 *    `transaction_lines`, one row per element of `lines[]`. The header's
 *    `farm_id`, `type` and GST-settlement tag are copied onto each line,
 *    because the column store can only aggregate inside a scan over columns
 *    of the table it is scanning.
 *  - Milk prices. Figured keeps them in `variables` as serialised blobs keyed
 *    by a concatenated name; here they are `milk_tracker_prices` rows.
 *  - A milk tracker's income account, which Figured resolves in code, is a
 *    column on `milk_trackers`.
 *  - Livestock values per head. Figured keeps valuations in Mongo and
 *    resolves them per scheme in PHP; here they are `stock_class_valuations`
 *    rows, one per class per season.
 *
 * Lives in its own `insights` schema so it cannot collide with the earlier
 * phases' simplified tables of the same names in `public`.
 */
final class InsightsSchema
{
    public const string SCHEMA = 'insights';

    /**
     * Every column the portfolio statement reads from the fact table. The
     * engine only aggregates inside the scan when all of them are in the
     * store, so this list is the memory budget.
     */
    public const array COLUMNAR_COLUMNS = ['farm_id', 'basis', 'type', 'date', 'month', 'account_id', 'net_amount', 'tag'];

    private const int QUERY_WORKERS = 15;

    private const int POPULATION_WORKERS = 4;

    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
    }

    public function create(): void
    {
        $s = self::SCHEMA;

        $this->db->statement("DROP SCHEMA IF EXISTS {$s} CASCADE");
        $this->db->statement("CREATE SCHEMA {$s}");

        $this->db->statement(<<<SQL
            CREATE TABLE {$s}.practices (
                id        INTEGER PRIMARY KEY,
                uuid      UUID NOT NULL UNIQUE,
                name      TEXT NOT NULL,
                org_type  TEXT NOT NULL DEFAULT 'accountant',
                region    TEXT NOT NULL,
                timezone  TEXT NOT NULL DEFAULT 'Pacific/Auckland'
            )
            SQL);

        $this->db->statement(<<<SQL
            CREATE TABLE {$s}.farms (
                id                       INTEGER NOT NULL,
                _valid_from              BIGINT NOT NULL,
                _valid_to                BIGINT,
                uuid                     UUID NOT NULL,
                name                     TEXT NOT NULL,
                practice_id              INTEGER,
                country_code             TEXT NOT NULL DEFAULT 'NZ',
                region_primary           TEXT NOT NULL,
                region_secondary         TEXT,
                timezone                 TEXT NOT NULL DEFAULT 'Pacific/Auckland',
                financial_year_end_day   SMALLINT NOT NULL,
                financial_year_end_month SMALLINT NOT NULL,
                sales_tax_basis          TEXT NOT NULL DEFAULT 'PAYMENTS',
                sales_tax_period         TEXT NOT NULL DEFAULT 'TWOMONTHS',
                product                  TEXT NOT NULL DEFAULT 'ff',
                PRIMARY KEY (id, _valid_from)
            )
            SQL);

        $this->db->statement(<<<SQL
            CREATE TABLE {$s}.farm_practice (
                id           BIGSERIAL PRIMARY KEY,
                farm_id      INTEGER NOT NULL,
                practice_id  INTEGER NOT NULL,
                view         BOOLEAN NOT NULL DEFAULT true,
                edit         BOOLEAN NOT NULL DEFAULT true,
                adviser      BOOLEAN NOT NULL DEFAULT true,
                benchmarking BOOLEAN NOT NULL DEFAULT false,
                UNIQUE (farm_id, practice_id)
            )
            SQL);
        $this->db->statement("CREATE INDEX farm_practice_practice_idx ON {$s}.farm_practice (practice_id)");

        $this->db->statement(<<<SQL
            CREATE TABLE {$s}.farm_types (
                uuid UUID PRIMARY KEY,
                name TEXT NOT NULL
            )
            SQL);

        $this->db->statement(<<<SQL
            CREATE TABLE {$s}.farms_operation_types (
                id            BIGSERIAL,
                _valid_from   BIGINT NOT NULL,
                _valid_to     BIGINT,
                farm_id       INTEGER NOT NULL,
                farm_type_uuid UUID NOT NULL,
                PRIMARY KEY (id, _valid_from)
            )
            SQL);

        $this->db->statement(<<<SQL
            CREATE TABLE {$s}.xero_accounts (
                id             BIGSERIAL,
                _valid_from    BIGINT NOT NULL,
                _valid_to      BIGINT,
                farm_id        INTEGER NOT NULL,
                accountid      TEXT NOT NULL,
                code           TEXT NOT NULL,
                name           TEXT NOT NULL,
                status         TEXT NOT NULL DEFAULT 'ACTIVE',
                class          TEXT NOT NULL,
                type           TEXT NOT NULL,
                system_account TEXT,
                source         TEXT NOT NULL DEFAULT 'xero',
                virtual        BOOLEAN NOT NULL DEFAULT false,
                PRIMARY KEY (id, _valid_from)
            )
            SQL);
        $this->db->statement("CREATE INDEX xero_accounts_farm_idx ON {$s}.xero_accounts (farm_id, accountid)");

        $this->db->statement(<<<SQL
            CREATE TABLE {$s}.categories (
                id                   BIGSERIAL,
                _valid_from          BIGINT NOT NULL,
                _valid_to            BIGINT,
                farm_id              INTEGER NOT NULL,
                name                 TEXT NOT NULL,
                "group"              TEXT NOT NULL,
                non_operating        BOOLEAN NOT NULL DEFAULT false,
                "order"              SMALLINT NOT NULL DEFAULT 0,
                system_category_name TEXT,
                milk_tracker_id      INTEGER,
                PRIMARY KEY (id, _valid_from)
            )
            SQL);

        $this->db->statement(<<<SQL
            CREATE TABLE {$s}.category_xero_account (
                id              BIGSERIAL PRIMARY KEY,
                category_id     BIGINT NOT NULL,
                xero_account_id TEXT NOT NULL,
                farm_id         INTEGER NOT NULL,
                category_set    TEXT NOT NULL DEFAULT 'STANDARD'
            )
            SQL);
        $this->db->statement("CREATE INDEX category_xero_account_farm_idx ON {$s}.category_xero_account (farm_id)");

        $this->db->statement(<<<SQL
            CREATE TABLE {$s}.milk_trackers (
                id                 INTEGER NOT NULL,
                _valid_from        BIGINT NOT NULL,
                _valid_to          BIGINT,
                farm_id            INTEGER NOT NULL,
                name               TEXT NOT NULL,
                company            TEXT NOT NULL,
                production_measure TEXT NOT NULL DEFAULT 'kgms',
                payment_method     TEXT NOT NULL DEFAULT 'deferred',
                season_start_month SMALLINT NOT NULL DEFAULT 6,
                income_accountid   TEXT NOT NULL,
                PRIMARY KEY (id, _valid_from)
            )
            SQL);
        $this->db->statement("CREATE INDEX milk_trackers_farm_idx ON {$s}.milk_trackers (farm_id)");

        $this->db->statement(<<<SQL
            CREATE TABLE {$s}.milk_productions (
                id                    BIGSERIAL,
                _valid_from           BIGINT NOT NULL,
                _valid_to             BIGINT,
                farm_id               INTEGER NOT NULL,
                milk_tracker_id       INTEGER NOT NULL,
                transaction_date      DATE NOT NULL,
                type                  TEXT NOT NULL,
                budget_id             INTEGER NOT NULL DEFAULT 0,
                production            INTEGER NOT NULL,
                peak_cows_milked      INTEGER,
                milking_platform_area NUMERIC(11, 2),
                PRIMARY KEY (id, _valid_from)
            )
            SQL);
        $this->db->statement("CREATE INDEX milk_productions_farm_idx ON {$s}.milk_productions (farm_id, transaction_date)");

        $this->db->statement(<<<SQL
            CREATE TABLE {$s}.milk_tracker_prices (
                milk_tracker_id INTEGER NOT NULL,
                month           DATE NOT NULL,
                price           BIGINT NOT NULL,
                PRIMARY KEY (milk_tracker_id, month)
            )
            SQL);

        // Livestock, as Figured's MySQL holds it: a tracker per mob, its
        // stock type, the classes within it, and every movement as a
        // `stock_transactions` row whose transition says which way it went.
        $this->db->statement(<<<SQL
            CREATE TABLE {$s}.stock_types (
                uuid         UUID PRIMARY KEY,
                name         TEXT NOT NULL,
                tracker_type TEXT NOT NULL DEFAULT 'stock'
            )
            SQL);

        $this->db->statement(<<<SQL
            CREATE TABLE {$s}.stock_classes (
                uuid            UUID PRIMARY KEY,
                stock_type_uuid UUID NOT NULL,
                name            TEXT NOT NULL
            )
            SQL);

        $this->db->statement(<<<SQL
            CREATE TABLE {$s}.trackers (
                id              INTEGER NOT NULL,
                _valid_from     BIGINT NOT NULL,
                _valid_to       BIGINT,
                uuid            UUID NOT NULL,
                farm_id         INTEGER NOT NULL,
                name            TEXT NOT NULL,
                tracker_number  INTEGER NOT NULL,
                stock_type_uuid UUID NOT NULL,
                PRIMARY KEY (id, _valid_from)
            )
            SQL);

        $this->db->statement(<<<SQL
            CREATE TABLE {$s}.stock_transactions (
                id               BIGSERIAL,
                _valid_from      BIGINT NOT NULL,
                _valid_to        BIGINT,
                farm_id          INTEGER NOT NULL,
                tracker_id       INTEGER NOT NULL,
                stock_class_uuid UUID NOT NULL,
                quantity         NUMERIC(19, 4) NOT NULL,
                type             TEXT NOT NULL,
                budget_id        INTEGER NOT NULL DEFAULT 0,
                transition       TEXT NOT NULL,
                transaction_date DATE NOT NULL,
                PRIMARY KEY (id, _valid_from)
            )
            SQL);
        $this->db->statement("CREATE INDEX stock_transactions_farm_idx ON {$s}.stock_transactions (farm_id, transaction_date)");

        // Changed: Figured keeps valuations in Mongo `figured_valuations`,
        // resolved per scheme in PHP. Here each class has a value per head
        // per season, which is what the scheme resolves to.
        $this->db->statement(<<<SQL
            CREATE TABLE {$s}.stock_class_valuations (
                farm_id          INTEGER NOT NULL,
                tracker_id       INTEGER NOT NULL,
                stock_class_uuid UUID NOT NULL,
                season           INTEGER NOT NULL,
                value_per_head   BIGINT NOT NULL,
                PRIMARY KEY (farm_id, tracker_id, stock_class_uuid, season)
            )
            SQL);

        // Mongo's `transactions` header. No foreign keys anywhere below: at
        // tens of millions of rows the checks would dominate the load, and
        // Mongo enforces none either.
        $this->db->statement(<<<SQL
            CREATE TABLE {$s}.transactions (
                id                 BIGINT PRIMARY KEY,
                transaction_number TEXT NOT NULL,
                farm_id            INTEGER NOT NULL,
                type               TEXT NOT NULL,
                budget_id          INTEGER NOT NULL DEFAULT 0,
                actuals_basis      TEXT,
                date               DATE NOT NULL,
                cash_date          DATE,
                accrual_date       DATE,
                tags               TEXT[] NOT NULL DEFAULT '{}'
            )
            SQL);

        $this->db->statement(<<<SQL
            CREATE TABLE {$s}.transaction_lines (
                transaction_id BIGINT NOT NULL,
                farm_id        INTEGER NOT NULL,
                type           TEXT NOT NULL,
                basis          TEXT NOT NULL,
                date           DATE NOT NULL,
                account_id     TEXT NOT NULL,
                net_amount     BIGINT NOT NULL,
                tax_amount     BIGINT NOT NULL DEFAULT 0,
                tag            TEXT,
                -- Derived, not stored in Mongo. Every report buckets by month,
                -- and the column store only aggregates inside its scan when
                -- the grouping keys are real columns: grouping on
                -- date_trunc(date) sent all 21M cash lines of the 250-farm
                -- practice up to one process to be grouped.
                month          DATE GENERATED ALWAYS AS (date_trunc('month', date::timestamp)::date) STORED
            )
            SQL);

        // Groups on (date, account) inside each farm are far from independent;
        // without this the planner overestimated the scan's groups by 8,000x
        // in the cash flow phase and gathered raw rows into one aggregate.
        $this->db->statement("CREATE STATISTICS {$s}.transaction_lines_scan_groups (ndistinct) ON farm_id, month, account_id FROM {$s}.transaction_lines");
    }

    /**
     * Built after loading: maintaining a btree through a bulk insert costs far
     * more than one sorted build at the end.
     */
    public function index(): void
    {
        $s = self::SCHEMA;

        $this->db->statement("SET maintenance_work_mem = '2GB'");
        $this->db->statement('SET max_parallel_maintenance_workers = 4');
        $this->db->statement("CREATE INDEX IF NOT EXISTS transaction_lines_scope_idx ON {$s}.transaction_lines (farm_id, basis, date)");

        // Sized from the table, the planner gave the portfolio scan 7 workers
        // on a 16-core host; all 15 took 1.63 s to 1.44 s.
        $this->db->statement("ALTER TABLE {$s}.transaction_lines SET (parallel_workers = ".self::QUERY_WORKERS.')');

        foreach (['transaction_lines', 'transactions', 'xero_accounts', 'categories', 'category_xero_account', 'milk_productions', 'farms', 'farm_practice', 'stock_transactions', 'stock_class_valuations', 'trackers'] as $table) {
            $this->db->statement("ANALYZE {$s}.{$table}");
        }
    }

    /**
     * Loads the portfolio statement's columns into the column store and
     * reports what it holds.
     *
     * @return list<array<string, mixed>>
     */
    public function columnarize(): array
    {
        $relation = self::SCHEMA.'.transaction_lines';

        // Population runs as many workers as the table's parallel_workers,
        // each allocating a 250 MB shared-memory segment: at the 15 the
        // queries want, that is 3.75 GB against the container's 2 GB
        // /dev/shm, and population fails "No space left on device". Four
        // while populating, then back.
        $this->db->statement("ALTER TABLE {$relation} SET (parallel_workers = ".self::POPULATION_WORKERS.')');

        try {
            foreach (self::COLUMNAR_COLUMNS as $column) {
                $this->db->statement('SELECT google_columnar_engine_add(?, ?)', [$relation, $column]);
            }

            $this->db->statement('SELECT google_columnar_engine_refresh(?)', [$relation]);
        } finally {
            $this->db->statement("ALTER TABLE {$relation} SET (parallel_workers = ".self::QUERY_WORKERS.')');
        }

        return $this->columnStore();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function columnStore(): array
    {
        try {
            return array_map(
                static fn (object $row): array => (array) $row,
                $this->db->select(
                    "SELECT relation_name, status, block_count_in_cc, total_block_count
                     FROM g_columnar_relations
                     WHERE schema_name = ? AND relation_name = 'transaction_lines'",
                    [self::SCHEMA],
                ),
            );
        } catch (Throwable) {
            return [];
        }
    }
}
