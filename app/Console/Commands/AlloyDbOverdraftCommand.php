<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AlloyDb\OverdraftPgQuery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AlloyDbOverdraftCommand extends Command
{
    protected $signature = 'alloydb:overdraft
        {--term=interest_only_monthly : Repayment term to seed}
        {--all-terms : Check every repayment term instead of one}';

    protected $description = 'Seed the overdraft oracle into AlloyDB and check it against Figured';

    public function handle(): int
    {
        $query = new OverdraftPgQuery(DB::connection('alloydb'));

        return $this->option('all-terms')
            ? $this->checkAllTerms($query)
            : $this->checkAccrual($query, (string) $this->option('term'));
    }

    private function checkAccrual(OverdraftPgQuery $query, string $term): int
    {
        $query->seedOracle($term);
        $rows = $query->run(OverdraftPgQuery::ORACLE_FARM_ID, '2024-01-01', '2024-12-31', '2026-08-31');
        $expected = OverdraftPgQuery::expectedMonthly();

        $this->info(sprintf('AlloyDB overdraft oracle — 5%% annual, %s', $term));
        $this->line('');
        $this->line(sprintf('  %-9s %14s %14s %14s', 'month', 'closing', 'accrued', 'posted'));

        $failed = 0;

        foreach ($rows as $i => $row) {
            $accrued = (int) round(((float) $row['interest_accrued']) * 10000);
            $expect = $expected[$i] ?? null;

            if ($expect !== null && $accrued !== $expect) {
                $failed++;
            }

            $this->line(sprintf('  %-9s %14s %14s %14s%s',
                (string) $row['month'],
                number_format((float) $row['closing_balance'], 2),
                number_format($accrued),
                $row['interest_posted'] === null ? '-' : number_format((float) $row['interest_posted'], 2),
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
     * Conservation across every term. Interest accrues monthly whatever the
     * calendar does, so the posted amounts must always sum to the accrued
     * amounts — the assertion that caught a dropped first-month bucket on the
     * lake, which per-month checks had missed entirely.
     */
    private function checkAllTerms(OverdraftPgQuery $query): int
    {
        $accrued = array_sum(OverdraftPgQuery::expectedMonthly());
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
            $query->seedOracle($term);
            $rows = $query->run(OverdraftPgQuery::ORACLE_FARM_ID, '2024-01-01', '2024-12-31', '2026-08-31');

            $months = [];
            $total = 0;

            foreach ($rows as $i => $row) {
                if ($row['interest_posted'] === null) {
                    continue;
                }

                $months[] = $i + 1;
                $total += (int) round(((float) $row['interest_posted']) * 10000);
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
