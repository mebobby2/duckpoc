<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashFlow\CashFlowOracleSeeder;
use Illuminate\Console\Command;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Phase 1, Step 2: seed the Cash Flow parity oracle into DuckLake.
 */
class DuckDbCashFlowSeedCommand extends Command
{
    protected $signature = 'duckdb:cashflow:seed';

    protected $description = 'Seed the Cash Flow parity oracle scenario into DuckLake';

    public function handle(DuckDB $db): int
    {
        $alias = config('duckdb.attached_alias');

        $this->info('Seeding the oracle scenario (1 farm, 2 accounts, 4 transaction lines)...');

        try {
            (new CashFlowOracleSeeder($db, $alias))->seed();
        } catch (Throwable $e) {
            $this->error('Seed failed: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach (['farms', 'accounts', 'transaction_lines'] as $table) {
            $rows = iterator_to_array($db->query("SELECT count(*) AS n FROM {$alias}.{$table}")->rows(true));
            $this->line(sprintf('    %-18s %d row(s)', $table, $rows[0]['n'] ?? 0));
        }

        $this->line('');
        $this->info('Seeded transaction lines:');
        $lines = $db->query(<<<SQL
            SELECT tl.date, a.account_name, a.account_class, tl.type, tl.amount
            FROM {$alias}.transaction_lines tl
            JOIN {$alias}.accounts a ON a.account_id = tl.account_id
            ORDER BY tl.date
            SQL);

        foreach ($lines->rows(true) as $row) {
            $this->line(sprintf(
                '    %s  %-14s %-8s %-9s %12s  (%s)',
                $row['date'],
                $row['account_name'],
                $row['account_class'],
                $row['type'],
                number_format((int) $row['amount']),
                number_format((int) $row['amount'] / 10000, 2),
            ));
        }

        return self::SUCCESS;
    }
}
