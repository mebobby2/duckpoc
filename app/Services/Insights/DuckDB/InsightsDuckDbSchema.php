<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\PracticeShape;
use Saturio\DuckDB\DuckDB;

/**
 * The DuckDB Insights layout: Figured's MySQL tables in MySQL, journal lines
 * in DuckLake.
 *
 * The MySQL tables are the AlloyDB PoC's `insights` schema column for column,
 * minus `transactions` and `transaction_lines`. Lines are the lake's one
 * table; the Mongo document header has no home here, because no report reads
 * it and each line already carries the header fields a report needs (farm,
 * type, GST-settlement tag).
 *
 * The lake table is partitioned by (basis, year): every report reads one
 * basis over a date range, so both prune whole files. Not by farm: at 5,000
 * farms that is tens of thousands of partitions of small files. Writers
 * instead sort each batch by farm, month and account, so a portfolio's farms
 * sit together in row groups whose min/max statistics let the scan skip the
 * rest.
 */
final class InsightsDuckDbSchema
{
    public function createMySql(): void
    {
        $db = InsightsDuckDb::mysql();

        foreach (['practices', 'farms', 'farm_practice', 'farm_types', 'farms_operation_types', 'xero_accounts', 'categories',
            'category_xero_account', 'milk_trackers', 'milk_productions', 'milk_tracker_prices', 'stock_types', 'stock_classes',
            'trackers', 'stock_transactions', 'stock_class_valuations', 'active_plans', 'plan_drifts', 'plan_notes', 'plan_scenarios',
            'forecast_edit_lines', 'forecast_snapshots', 'farm_enterprises', 'bank_facilities'] as $table) {
            $db->statement("DROP TABLE IF EXISTS {$table}");
        }

        $db->statement(<<<'SQL'
            CREATE TABLE practices (
                id INT PRIMARY KEY, uuid CHAR(36) NOT NULL, name VARCHAR(255) NOT NULL,
                org_type VARCHAR(32) NOT NULL DEFAULT 'accountant', region VARCHAR(64) NOT NULL,
                timezone VARCHAR(64) NOT NULL DEFAULT 'Pacific/Auckland'
            )
            SQL);

        $db->statement(<<<'SQL'
            CREATE TABLE farms (
                id INT NOT NULL, _valid_from BIGINT NOT NULL, _valid_to BIGINT NULL, uuid CHAR(36) NOT NULL,
                name VARCHAR(255) NOT NULL, practice_id INT NULL, country_code VARCHAR(2) NOT NULL DEFAULT 'NZ',
                region_primary VARCHAR(64) NOT NULL, region_secondary VARCHAR(64) NULL,
                timezone VARCHAR(64) NOT NULL DEFAULT 'Pacific/Auckland',
                financial_year_end_day TINYINT NOT NULL, financial_year_end_month TINYINT NOT NULL,
                sales_tax_basis VARCHAR(16) NOT NULL DEFAULT 'PAYMENTS', sales_tax_period VARCHAR(16) NOT NULL DEFAULT 'TWOMONTHS',
                product VARCHAR(8) NOT NULL DEFAULT 'ff', area_ha DECIMAL(10, 2) NULL,
                PRIMARY KEY (id, _valid_from)
            )
            SQL);

        $db->statement(<<<'SQL'
            CREATE TABLE farm_practice (
                id BIGINT AUTO_INCREMENT PRIMARY KEY, farm_id INT NOT NULL, practice_id INT NOT NULL,
                view TINYINT(1) NOT NULL DEFAULT 1, edit TINYINT(1) NOT NULL DEFAULT 1,
                adviser TINYINT(1) NOT NULL DEFAULT 1, benchmarking TINYINT(1) NOT NULL DEFAULT 0,
                UNIQUE KEY (farm_id, practice_id), KEY (practice_id)
            )
            SQL);

        $db->statement('CREATE TABLE farm_types (uuid CHAR(36) PRIMARY KEY, name VARCHAR(64) NOT NULL)');

        $db->statement(<<<'SQL'
            CREATE TABLE farms_operation_types (
                id BIGINT AUTO_INCREMENT, _valid_from BIGINT NOT NULL, _valid_to BIGINT NULL,
                farm_id INT NOT NULL, farm_type_uuid CHAR(36) NOT NULL,
                PRIMARY KEY (id, _valid_from), KEY (farm_id)
            )
            SQL);

        $db->statement(<<<'SQL'
            CREATE TABLE xero_accounts (
                id BIGINT AUTO_INCREMENT, _valid_from BIGINT NOT NULL, _valid_to BIGINT NULL, farm_id INT NOT NULL,
                accountid VARCHAR(64) NOT NULL, code VARCHAR(16) NOT NULL, name VARCHAR(255) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE', class VARCHAR(16) NOT NULL, type VARCHAR(32) NOT NULL,
                system_account VARCHAR(32) NULL, source VARCHAR(16) NOT NULL DEFAULT 'xero', `virtual` TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id, _valid_from), KEY (farm_id, accountid)
            )
            SQL);

        $db->statement(<<<'SQL'
            CREATE TABLE categories (
                id BIGINT AUTO_INCREMENT, _valid_from BIGINT NOT NULL, _valid_to BIGINT NULL, farm_id INT NOT NULL,
                name VARCHAR(255) NOT NULL, `group` VARCHAR(64) NOT NULL, non_operating TINYINT(1) NOT NULL DEFAULT 0,
                `order` SMALLINT NOT NULL DEFAULT 0, system_category_name VARCHAR(255) NULL, milk_tracker_id INT NULL,
                PRIMARY KEY (id, _valid_from), KEY (farm_id)
            )
            SQL);

        $db->statement(<<<'SQL'
            CREATE TABLE category_xero_account (
                id BIGINT AUTO_INCREMENT PRIMARY KEY, category_id BIGINT NOT NULL, xero_account_id VARCHAR(64) NOT NULL,
                farm_id INT NOT NULL, category_set VARCHAR(16) NOT NULL DEFAULT 'STANDARD', KEY (farm_id)
            )
            SQL);

        $db->statement(<<<'SQL'
            CREATE TABLE milk_trackers (
                id INT NOT NULL, _valid_from BIGINT NOT NULL, _valid_to BIGINT NULL, farm_id INT NOT NULL,
                name VARCHAR(255) NOT NULL, company VARCHAR(32) NOT NULL, production_measure VARCHAR(16) NOT NULL DEFAULT 'kgms',
                payment_method VARCHAR(16) NOT NULL DEFAULT 'deferred', season_start_month TINYINT NOT NULL DEFAULT 6,
                income_accountid VARCHAR(64) NOT NULL, enterprise VARCHAR(32) NULL,
                PRIMARY KEY (id, _valid_from), KEY (farm_id)
            )
            SQL);

        $db->statement(<<<'SQL'
            CREATE TABLE milk_productions (
                id BIGINT AUTO_INCREMENT, _valid_from BIGINT NOT NULL, _valid_to BIGINT NULL, farm_id INT NOT NULL,
                milk_tracker_id INT NOT NULL, transaction_date DATE NOT NULL, type VARCHAR(16) NOT NULL,
                budget_id INT NOT NULL DEFAULT 0, production INT NOT NULL, peak_cows_milked INT NULL,
                milking_platform_area DECIMAL(11, 2) NULL,
                PRIMARY KEY (id, _valid_from), KEY (farm_id, transaction_date)
            )
            SQL);

        $db->statement('CREATE TABLE milk_tracker_prices (milk_tracker_id INT NOT NULL, month DATE NOT NULL, price BIGINT NOT NULL, PRIMARY KEY (milk_tracker_id, month))');
        $db->statement("CREATE TABLE stock_types (uuid CHAR(36) PRIMARY KEY, name VARCHAR(64) NOT NULL, tracker_type VARCHAR(16) NOT NULL DEFAULT 'stock')");
        $db->statement('CREATE TABLE stock_classes (uuid CHAR(36) PRIMARY KEY, stock_type_uuid CHAR(36) NOT NULL, name VARCHAR(64) NOT NULL)');

        $db->statement(<<<'SQL'
            CREATE TABLE trackers (
                id INT NOT NULL, _valid_from BIGINT NOT NULL, _valid_to BIGINT NULL, uuid CHAR(36) NOT NULL,
                farm_id INT NOT NULL, name VARCHAR(255) NOT NULL, tracker_number INT NOT NULL, stock_type_uuid CHAR(36) NOT NULL,
                enterprise VARCHAR(32) NULL,
                PRIMARY KEY (id, _valid_from), KEY (farm_id)
            )
            SQL);

        $db->statement(<<<'SQL'
            CREATE TABLE stock_transactions (
                id BIGINT AUTO_INCREMENT, _valid_from BIGINT NOT NULL, _valid_to BIGINT NULL, farm_id INT NOT NULL,
                tracker_id INT NOT NULL, stock_class_uuid CHAR(36) NOT NULL, quantity DECIMAL(19, 4) NOT NULL,
                type VARCHAR(16) NOT NULL, budget_id INT NOT NULL DEFAULT 0, transition VARCHAR(16) NOT NULL,
                transaction_date DATE NOT NULL,
                PRIMARY KEY (id, _valid_from), KEY (farm_id, transaction_date)
            )
            SQL);

        $db->statement(<<<'SQL'
            CREATE TABLE stock_class_valuations (
                farm_id INT NOT NULL, tracker_id INT NOT NULL, stock_class_uuid CHAR(36) NOT NULL,
                season INT NOT NULL, value_per_head BIGINT NOT NULL,
                PRIMARY KEY (farm_id, tracker_id, stock_class_uuid, season)
            )
            SQL);

        $this->createPlanTables();
    }

    /**
     * Each farm's active plan: the season it covers, the horizon its forecast
     * was agreed at, and how far actuals have come in since. Seeded farms
     * have no row until actuals are advanced past the plan.
     *
     * `plan_drifts` is the seeder's ground truth, what it did to each farm's
     * actuals, so a variance check can be scored against it. Figured has no
     * such table.
     *
     * `plan_notes` holds what the farmer or advisor has said matters, or
     * explains, for plan status to weigh when judging the signals.
     *
     * `plan_scenarios` holds the what-ifs saved for a farm, as the changes
     * the farmer described; an adopted one's journal lines are in
     * `forecast_edit_lines`, the PoC's stand-in for writing them to Figured's
     * forecast, which current-side reports read beside the lake's forecast.
     * Adopted and withdrawn times make the edits bitemporal: a version of the
     * forecast as known at a time reads the edits adopted by then and not yet
     * withdrawn.
     *
     * `forecast_snapshots` are those versions, named: the forecast as it
     * stood at a time, with actuals to a date. Each active plan's budget is
     * one, kind `budget`.
     *
     * `farm_enterprises` lists a farm's enterprises and the hectares each
     * farms; `bank_facilities` its overdraft limit and rate.
     */
    public function createPlanTables(): void
    {
        $db = InsightsDuckDb::mysql();

        $db->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS active_plans (
                farm_id INT PRIMARY KEY, name VARCHAR(64) NOT NULL, season INT NOT NULL,
                plan_horizon DATE NOT NULL, actuals_to DATE NOT NULL
            )
            SQL);

        $db->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS plan_drifts (
                farm_id INT PRIMARY KEY, kind VARCHAR(32) NOT NULL, description VARCHAR(255) NOT NULL
            )
            SQL);

        $db->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS plan_notes (
                id BIGINT AUTO_INCREMENT PRIMARY KEY, farm_id INT NOT NULL, author VARCHAR(16) NOT NULL,
                note TEXT NOT NULL, created_at DATETIME NOT NULL, KEY (farm_id)
            )
            SQL);

        $db->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS plan_scenarios (
                id BIGINT AUTO_INCREMENT PRIMARY KEY, farm_id INT NOT NULL, name VARCHAR(255) NOT NULL,
                changes JSON NOT NULL, status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL,
                adopted_at DATETIME(6) NULL, withdrawn_at DATETIME(6) NULL, KEY (farm_id)
            )
            SQL);

        $db->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forecast_edit_lines (
                id BIGINT AUTO_INCREMENT PRIMARY KEY, scenario_id BIGINT NOT NULL, farm_id INT NOT NULL,
                change_no INT NULL, kind VARCHAR(16) NULL, enterprise VARCHAR(32) NULL,
                basis VARCHAR(8) NOT NULL, date DATE NOT NULL, account_id VARCHAR(64) NOT NULL,
                net_amount BIGINT NOT NULL, tax_amount BIGINT NOT NULL, KEY (farm_id), KEY (scenario_id)
            )
            SQL);

        $db->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forecast_snapshots (
                id BIGINT AUTO_INCREMENT PRIMARY KEY, farm_id INT NOT NULL, name VARCHAR(255) NOT NULL,
                kind VARCHAR(16) NOT NULL, actuals_to DATE NOT NULL, taken_at DATETIME(6) NOT NULL, KEY (farm_id)
            )
            SQL);

        $db->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS farm_enterprises (
                farm_id INT NOT NULL, enterprise VARCHAR(32) NOT NULL, area_ha DECIMAL(10, 2) NOT NULL,
                PRIMARY KEY (farm_id, enterprise)
            )
            SQL);

        $db->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS bank_facilities (
                farm_id INT PRIMARY KEY, overdraft_limit BIGINT NOT NULL, rate_pct DECIMAL(6, 3) NOT NULL
            )
            SQL);
    }

    /**
     * Brings a database set up before the reporting tools for the head of
     * product's scenarios up to their shape, without touching any row:
     * columns added where missing, new tables created, every active plan
     * given its budget snapshot. Safe to re-run.
     */
    public function upgradeMySql(): void
    {
        $db = InsightsDuckDb::mysql();
        $this->createPlanTables();

        $columns = [
            ['farms', 'area_ha', 'DECIMAL(10, 2) NULL'],
            ['trackers', 'enterprise', 'VARCHAR(32) NULL'],
            ['milk_trackers', 'enterprise', 'VARCHAR(32) NULL'],
            ['plan_scenarios', 'withdrawn_at', 'DATETIME(6) NULL'],
            ['forecast_edit_lines', 'change_no', 'INT NULL'],
            ['forecast_edit_lines', 'kind', 'VARCHAR(16) NULL'],
            ['forecast_edit_lines', 'enterprise', 'VARCHAR(32) NULL'],
        ];
        foreach ($columns as [$table, $column, $definition]) {
            $exists = $db->selectOne(
                'SELECT count(*) AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column],
            )->n;
            if ((int) $exists === 0) {
                $db->statement("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        }

        // Adopting, withdrawing and snapshotting can fall in the same second;
        // their order decides what a snapshot holds, so they keep microseconds.
        foreach ([['plan_scenarios', 'adopted_at', 'NULL'], ['plan_scenarios', 'withdrawn_at', 'NULL'], ['forecast_snapshots', 'taken_at', 'NOT NULL']] as [$table, $column, $null]) {
            $precision = $db->selectOne(
                'SELECT datetime_precision AS p FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column],
            )->p;
            if ((int) $precision !== 6) {
                $db->statement("ALTER TABLE {$table} MODIFY COLUMN {$column} DATETIME(6) {$null}");
            }
        }

        $this->addBudgetSnapshots();
    }

    /** Each active plan's budget: the forecast as agreed, actuals to the plan horizon, before any change was adopted. */
    public function addBudgetSnapshots(): void
    {
        InsightsDuckDb::mysql()->statement(
            "INSERT INTO forecast_snapshots (farm_id, name, kind, actuals_to, taken_at)
             SELECT ap.farm_id, 'Budget', 'budget', ap.plan_horizon, ap.plan_horizon + INTERVAL 1 DAY
             FROM active_plans ap
             WHERE NOT EXISTS (SELECT 1 FROM forecast_snapshots fs WHERE fs.farm_id = ap.farm_id AND fs.kind = 'budget')",
        );
    }

    /** Adds the lake columns later phases need; the lake must be attached writable. */
    public function upgradeLake(DuckDB $db): void
    {
        $lake = config('duckdb.attached_alias');
        $s = InsightsDuckDb::LAKE_SCHEMA;
        $has = iterator_to_array($db->query(
            "SELECT count(*) FROM duckdb_columns() WHERE database_name = '{$lake}' AND schema_name = '{$s}'
               AND table_name = 'transaction_lines' AND column_name = 'enterprise'",
        )->rows())[0][0] ?? 0;
        if ((int) (string) $has === 0) {
            $db->query("ALTER TABLE {$lake}.{$s}.transaction_lines ADD COLUMN enterprise VARCHAR");
        }
    }

    /**
     * Gives every farm any system account its chart is missing, the way the
     * seeder writes them (same ids), for charts seeded before the account
     * was added to PracticeShape.
     *
     * @return int accounts added
     */
    public function addMissingSystemAccounts(): int
    {
        $db = InsightsDuckDb::mysql();
        $validFrom = InsightsDuckDbPracticeSeeder::VALID_FROM;
        $hash = "MD5(CONCAT(f.id, '-', ?))";
        $seedUuid = "CONCAT(SUBSTR({$hash}, 1, 8), '-', SUBSTR({$hash}, 9, 4), '-', SUBSTR({$hash}, 13, 4), '-', SUBSTR({$hash}, 17, 4), '-', SUBSTR({$hash}, 21, 12))";

        $added = 0;
        foreach (PracticeShape::SYSTEM_ACCOUNTS as [$code, $name, $class, $type, $system, $group]) {
            $added += $db->affectingStatement(
                "INSERT INTO xero_accounts (_valid_from, farm_id, accountid, code, name, class, type, system_account)
                 SELECT ?, f.id, {$seedUuid}, ?, ?, ?, ?, ?
                 FROM farms f
                 WHERE f._valid_to IS NULL
                   AND NOT EXISTS (SELECT 1 FROM xero_accounts xa WHERE xa.farm_id = f.id AND xa.code = ? AND xa._valid_to IS NULL)",
                [$validFrom, ...array_fill(0, 5, $code), $code, $name, $class, $type, $system, $code],
            );
            $db->statement(
                "INSERT INTO categories (_valid_from, farm_id, name, `group`, non_operating, `order`, system_category_name)
                 SELECT ?, f.id, ?, ?, ?, 0, ?
                 FROM farms f
                 WHERE f._valid_to IS NULL
                   AND NOT EXISTS (SELECT 1 FROM categories c WHERE c.farm_id = f.id AND c.name = ? AND c.milk_tracker_id IS NULL AND c._valid_to IS NULL)",
                [$validFrom, $name, $group, (int) str_starts_with($group, 'non_operating'), $name, $name],
            );
            $db->statement(
                'INSERT INTO category_xero_account (category_id, xero_account_id, farm_id)
                 SELECT c.id, xa.accountid, xa.farm_id
                 FROM xero_accounts xa
                 JOIN categories c ON c.farm_id = xa.farm_id AND c.name = ? AND c.milk_tracker_id IS NULL AND c._valid_to IS NULL
                 WHERE xa.code = ? AND xa._valid_to IS NULL
                   AND NOT EXISTS (SELECT 1 FROM category_xero_account cxa WHERE cxa.farm_id = xa.farm_id AND cxa.xero_account_id = xa.accountid)',
                [$name, $code],
            );
        }

        return $added;
    }

    /**
     * The lake table. Row-group size is the lake's catalog-wide option, set by
     * the earlier phases (CashFlowSchema) and left alone here.
     */
    public function createLake(DuckDB $db): void
    {
        $lake = config('duckdb.attached_alias');
        $s = InsightsDuckDb::LAKE_SCHEMA;

        $db->query("CREATE SCHEMA IF NOT EXISTS {$lake}.{$s}");
        $db->query("DROP TABLE IF EXISTS {$lake}.{$s}.transaction_lines");
        $db->query(<<<SQL
            CREATE TABLE {$lake}.{$s}.transaction_lines (
                transaction_id BIGINT,
                farm_id        INTEGER,
                type           VARCHAR,
                basis          VARCHAR,
                date           DATE,
                month          DATE,
                account_id     VARCHAR,
                net_amount     BIGINT,
                tax_amount     BIGINT,
                tag            VARCHAR,
                enterprise     VARCHAR
            )
            SQL);
        $db->query("ALTER TABLE {$lake}.{$s}.transaction_lines SET PARTITIONED BY (basis, year(date))");
    }

    /** Session settings a writer needs so row groups reach the catalog's target size. */
    public function applyWriteTuning(DuckDB $db): void
    {
        $db->query("SET write_buffer_row_group_memory_limit='1GB'");
        $db->query('SET write_buffer_row_group_count=1');
    }
}
