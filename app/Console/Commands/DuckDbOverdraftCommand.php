<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashFlow\OverdraftOracleSeeder;
use App\Services\CashFlow\OverdraftQuery;
use Illuminate\Console\Command;
use Saturio\DuckDB\DuckDB;

class DuckDbOverdraftCommand extends Command
{
    protected $signature = 'duckdb:overdraft
        {--term=interest_only_monthly : Repayment term to seed}
        {--all-terms : Check every repayment term instead of one}';

    protected $description = 'Seed the overdraft oracle farm and check the interest against Figured';

    public function handle(DuckDB $db): int
    {
        $alias = config('duckdb.attached_alias');
        $appAlias = config('duckdb.app_database.alias');

        $seeder = new OverdraftOracleSeeder($db, $alias, $appAlias);
        $query = new OverdraftQuery($db, $alias, $appAlias);

        return $this->option('all-terms')
            ? $this->checkAllTerms($seeder, $query)
            : $this->checkAccrual($seeder, $query, (string) $this->option('term'));
    }

    /**
     * The accrual, month by month, against Figured's pinned series.
     */
    private function checkAccrual(OverdraftOracleSeeder $seeder, OverdraftQuery $query, string $term): int
    {
        $seeder->seed($term);
        $rows = $query->run(OverdraftOracleSeeder::FARM_ID, '2024-01-01', '2024-12-31', '2026-08-31');
        $expected = OverdraftOracleSeeder::expectedMonthly();

        $this->info(sprintf('Overdraft oracle — 5%% annual, %s', $term));
        $this->line('');
        $this->line(sprintf('  %-9s %14s %14s %14s', 'month', 'closing', 'accrued', 'posted'));

        $failed = 0;

        foreach ($rows as $i => $row) {
            $accrued = (int) round(((float) (string) $row['interest_accrued']) * 10000);
            $expect = $expected[$i] ?? null;

            if ($expect !== null && $accrued !== $expect) {
                $failed++;
            }

            $this->line(sprintf('  %-9s %14s %14s %14s%s',
                (string) $row['month'],
                number_format((float) (string) $row['closing_before_interest'], 2),
                number_format($accrued),
                $row['interest_posted'] === null ? '-' : number_format((float) (string) $row['interest_posted'], 2),
                $expect !== null && $accrued !== $expect ? '   expected '.number_format($expect) : '',
            ));
        }

        $this->line('');

        if ($failed > 0) {
            $this->error(sprintf('✘ %d month(s) diverge from Figured.', $failed));

            return self::FAILURE;
        }

        $this->info('✔ Accrual matches Figured cell for cell.');

        return self::SUCCESS;
    }

    /**
     * Distribution across every term.
     *
     * The assertion that matters is conservation: interest accrues every month
     * regardless of term, so whatever the calendar does, the posted amounts
     * must still sum to the accrued amounts. A dropped bucket is otherwise
     * invisible — every month it does post looks correct.
     */
    private function checkAllTerms(OverdraftOracleSeeder $seeder, OverdraftQuery $query): int
    {
        $accrued = array_sum(OverdraftOracleSeeder::expectedMonthly());
        $failed = 0;

        $this->info('Repayment distribution — posted must always sum to accrued');
        $this->line('');

        foreach ([
            'interest_only_monthly',
            'interest_only_bi_monthly',
            'interest_only_quarterly',
            'interest_only_semi_annually',
            'interest_only_annually',
        ] as $term) {
            $seeder->seed($term);
            $rows = $query->run(OverdraftOracleSeeder::FARM_ID, '2024-01-01', '2024-12-31', '2026-08-31');

            $months = [];
            $total = 0;

            foreach ($rows as $i => $row) {
                if ($row['interest_posted'] === null) {
                    continue;
                }

                $months[] = $i + 1;
                $total += (int) round(((float) (string) $row['interest_posted']) * 10000);
            }

            $conserved = $total === $accrued;
            $failed += $conserved ? 0 : 1;

            $this->line(sprintf('  %-30s posts in %-24s %s',
                $term, implode(',', $months),
                $conserved ? 'conserved' : sprintf('LOST %s', number_format($accrued - $total))));
        }

        $this->line('');

        if ($failed > 0) {
            $this->error(sprintf('✘ %d term(s) lose interest.', $failed));

            return self::FAILURE;
        }

        $this->info('✔ Every term conserves the full accrual.');

        return self::SUCCESS;
    }
}
