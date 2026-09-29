<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use Saturio\DuckDB\DuckDB;

/**
 * Copies the AlloyDB PoC's practices into the DuckDB layout, row for row, so
 * the two engines can be compared on identical data: dimensions into MySQL
 * with their ids intact (categories and accounts are joined by id), journal
 * lines into the lake.
 *
 * One DuckDB process reads AlloyDB through the postgres extension and writes
 * both destinations, so nothing is exported to files in between.
 */
final class AlloyDbInsightsImporter
{
    private const string PG = 'pg';

    /** Farms per lake insert: each is sorted in memory before it is written. */
    private const int FARMS_PER_BATCH = 25;

    /**
     * MySQL table => columns, copied as they are. Booleans become integers,
     * UUIDs text, for MySQL.
     */
    private const array DIMENSIONS = [
        'practices' => 'id, CAST(uuid AS VARCHAR), name, org_type, region, timezone',
        'farms' => 'id, _valid_from, _valid_to, CAST(uuid AS VARCHAR), name, practice_id, country_code, region_primary, region_secondary, timezone, financial_year_end_day, financial_year_end_month, sales_tax_basis, sales_tax_period, product',
        'farm_practice' => 'id, farm_id, practice_id, CAST(view AS INTEGER), CAST(edit AS INTEGER), CAST(adviser AS INTEGER), CAST(benchmarking AS INTEGER)',
        'farm_types' => 'CAST(uuid AS VARCHAR), name',
        'farms_operation_types' => 'id, _valid_from, _valid_to, farm_id, CAST(farm_type_uuid AS VARCHAR)',
        'xero_accounts' => 'id, _valid_from, _valid_to, farm_id, accountid, code, name, status, class, type, system_account, source, CAST(virtual AS INTEGER)',
        'categories' => 'id, _valid_from, _valid_to, farm_id, name, "group", CAST(non_operating AS INTEGER), "order", system_category_name, milk_tracker_id',
        'category_xero_account' => 'id, category_id, xero_account_id, farm_id, category_set',
        'milk_trackers' => 'id, _valid_from, _valid_to, farm_id, name, company, production_measure, payment_method, season_start_month, income_accountid',
        'milk_productions' => 'id, _valid_from, _valid_to, farm_id, milk_tracker_id, transaction_date, type, budget_id, production, peak_cows_milked, milking_platform_area',
        'milk_tracker_prices' => 'milk_tracker_id, month, price',
        'stock_types' => 'CAST(uuid AS VARCHAR), name, tracker_type',
        'stock_classes' => 'CAST(uuid AS VARCHAR), CAST(stock_type_uuid AS VARCHAR), name',
        'trackers' => 'id, _valid_from, _valid_to, CAST(uuid AS VARCHAR), farm_id, name, tracker_number, CAST(stock_type_uuid AS VARCHAR)',
        'stock_transactions' => 'id, _valid_from, _valid_to, farm_id, tracker_id, CAST(stock_class_uuid AS VARCHAR), quantity, type, budget_id, transition, transaction_date',
        'stock_class_valuations' => 'farm_id, tracker_id, CAST(stock_class_uuid AS VARCHAR), season, value_per_head',
    ];

    public function __construct(
        private readonly DuckDB $db,
    ) {
    }

    /**
     * @param callable(string): void $progress
     * @return array<string, int>
     */
    public function import(callable $progress): array
    {
        $this->attachAlloyDb();
        $counts = [];

        foreach (self::DIMENSIONS as $table => $columns) {
            $target = InsightsDuckDb::table($table);
            $this->db->query("INSERT INTO {$target} SELECT {$columns} FROM ".self::PG.".insights.{$table}");
            $counts[$table] = $this->count("SELECT count(*) FROM {$target}");
            $progress(sprintf('%-24s %12s rows', $table, number_format($counts[$table])));
        }

        $lines = InsightsDuckDb::lines();
        $farmIds = array_map(
            static fn (array $r): int => (int) (string) $r['farm_id'],
            iterator_to_array($this->db->query('SELECT farm_id FROM '.self::PG.'.insights.farm_practice ORDER BY farm_id')->rows(true)),
        );

        foreach (array_chunk($farmIds, self::FARMS_PER_BATCH) as $batch) {
            $lo = $batch[0];
            $hi = end($batch);
            $this->db->query(<<<SQL
                INSERT INTO {$lines}
                SELECT transaction_id, farm_id, type, basis, date, month, account_id, net_amount, tax_amount, tag
                FROM {$this->pgLines()}
                WHERE farm_id BETWEEN {$lo} AND {$hi}
                ORDER BY farm_id, month, account_id
                SQL);
            $progress(sprintf('lines for farms %d-%d', $lo, $hi));
        }

        $counts['transaction_lines'] = $this->count("SELECT count(*) FROM {$lines}");

        return $counts;
    }

    private function pgLines(): string
    {
        return self::PG.'.insights.transaction_lines';
    }

    private function attachAlloyDb(): void
    {
        $this->db->query('INSTALL postgres');
        $this->db->query('LOAD postgres');

        $c = config('database.connections.alloydb');
        $dsn = sprintf('host=%s port=%s dbname=%s user=%s password=%s', $c['host'], $c['port'], $c['database'], $c['username'], $c['password']);
        $this->db->query(sprintf("ATTACH IF NOT EXISTS '%s' AS %s (TYPE postgres, READ_ONLY)", str_replace("'", "''", $dsn), self::PG));
    }

    private function count(string $sql): int
    {
        $row = iterator_to_array($this->db->query($sql)->rows())[0] ?? [0];

        return (int) (string) $row[0];
    }
}
