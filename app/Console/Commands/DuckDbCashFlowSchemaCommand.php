<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashFlow\CashFlowSchema;
use Illuminate\Console\Command;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Phase 1, Step 1: create the DuckLake schema Cash Flow needs
 * (transaction_lines, accounts, farms), partitioned by
 * (farm_type, region, year) — see CashFlowSchema docblock for why not
 * farm_id. Destructive — drops and recreates the tables every run,
 * deliberately, so schema iteration during development always starts clean.
 */
class DuckDbCashFlowSchemaCommand extends Command
{
    protected $signature = 'duckdb:cashflow:schema';

    protected $description = 'Create (or recreate) the Cash Flow PoC schema in DuckLake';

    public function handle(DuckDB $db): int
    {
        $alias = config('duckdb.attached_alias');

        $this->info('Recreating Cash Flow schema (transaction_lines, accounts, farms)...');

        try {
            (new CashFlowSchema($db, $alias))->recreate();
        } catch (Throwable $e) {
            $this->error('Failed to create schema: '.$e->getMessage());

            return self::FAILURE;
        }

        $tables = iterator_to_array($db->query(
            "SELECT table_name FROM duckdb_tables() WHERE database_name = '{$alias}' ORDER BY table_name"
        )->rows(true));

        $this->info('✔ Schema created. Tables in '.$alias.':');
        foreach ($tables as $table) {
            $this->line("    {$table['table_name']}");
        }

        $partitions = iterator_to_array($db->query(
            "SELECT * FROM ducklake_table_info('{$alias}') WHERE table_name = 'transaction_lines'"
        )->rows(true));

        if (!empty($partitions)) {
            $this->line('');
            $this->info('transaction_lines is registered in the catalog — partitioning takes');
            $this->info('effect on the next write, not retroactively (see CashFlowSchema docblock).');
        }

        return self::SUCCESS;
    }
}
