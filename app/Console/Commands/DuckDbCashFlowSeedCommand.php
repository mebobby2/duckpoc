<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashFlow\CashFlowOracleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

        $appAlias = config('duckdb.app_database.alias');

        $this->line(sprintf('    %-28s %d row(s)', 'farms (mysql)', DB::table('farms')->count()));
        $this->line(sprintf('    %-28s %d row(s)', 'accounts (mysql)', DB::table('accounts')->count()));

        $lakeRows = iterator_to_array($db->query("SELECT count(*) AS n FROM {$alias}.transaction_lines")->rows(true));
        $this->line(sprintf('    %-28s %d row(s)', 'transaction_lines (ducklake)', $lakeRows[0]['n'] ?? 0));

        $this->line('');
        $this->info('Seeded transaction lines (Parquet fact rows joined to MySQL dimensions):');

        // A federated join: fact data from the lake, dimension data from the
        // attached MySQL database, resolved in one DuckDB statement.
        $lines = $db->query(<<<SQL
            SELECT tl.date, a.account_name, a.account_class, tl.type, tl.amount
            FROM {$alias}.transaction_lines tl
            JOIN {$appAlias}.accounts a ON a.account_id = tl.account_id
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
