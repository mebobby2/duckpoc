<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

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
            'trackers', 'stock_transactions', 'stock_class_valuations'] as $table) {
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
                product VARCHAR(8) NOT NULL DEFAULT 'ff',
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
                income_accountid VARCHAR(64) NOT NULL,
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
                tag            VARCHAR
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
