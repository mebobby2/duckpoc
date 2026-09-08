<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashFlow\CashFlowScaleSeeder;
use Illuminate\Console\Command;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Phase 1, Step 5: seed synthetic volume so partition pruning and row-group
 * pruning have something to act on.
 *
 * Leaves the Cash Flow oracle farm alone — it shares a cohort with the hero
 * farm on purpose, so `duckdb:cashflow:run` afterwards becomes a
 * find-4-rows-among-800K test rather than a trivial one.
 */
class DuckDbCashFlowSeedScaleCommand extends Command
{
    protected $signature = 'duckdb:cashflow:seed-scale';

    protected $description = 'Seed ~880K synthetic transaction lines across three cohorts for the scale test';

    public function handle(DuckDB $db): int
    {
        $alias = config('duckdb.attached_alias');

        $total = CashFlowScaleSeeder::totalRows();

        $this->info('Seeding '.number_format($total).' transaction lines across '
            .count(CashFlowScaleSeeder::farms()).' farms...');
        $this->line('');

        foreach (CashFlowScaleSeeder::farms() as $farmId => [$farmType, $region, $rows]) {
            $this->line(sprintf(
                '    %-18s %-8s %-12s %10s rows',
                $farmId,
                $farmType,
                $region,
                number_format($rows),
            ));
        }

        $this->line('');
        $this->line('Rows are generated inside DuckDB, so this is mostly Parquet write time.');
        $this->line('');

        $startedAt = microtime(true);

        try {
            (new CashFlowScaleSeeder($db, $alias))->seed(
                onFarmSeeded: function (string $farmId, int $rows) use ($startedAt): void {
                    $this->line(sprintf(
                        '    ✔ %-18s %10s rows   (%s elapsed)',
                        $farmId,
                        number_format($rows),
                        $this->duration(microtime(true) - $startedAt),
                    ));
                },
            );
        } catch (Throwable $e) {
            $this->error('Seed failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $elapsed = microtime(true) - $startedAt;

        $this->line('');
        $this->info('✔ Seeded in '.$this->duration($elapsed).'.');

        $this->reportState($db, $alias);

        $this->line('');
        $this->line('Next:');
        $this->line('    duckdb:cashflow:flush   — force anything still inlined out to Parquet');
        $this->line('    duckdb:cashflow:run     — oracle parity, now against a partition holding ~800K rows');

        return self::SUCCESS;
    }

    private function reportState(DuckDB $db, string $alias): void
    {
        $this->line('');
        $this->info('Rows per cohort partition:');

        $rows = $db->query(<<<SQL
            SELECT farm_type, region, count(*) AS n, count(DISTINCT farm_id) AS farms
            FROM {$alias}.transaction_lines
            GROUP BY farm_type, region
            ORDER BY farm_type, region
            SQL);

        foreach ($rows->rows(true) as $row) {
            $this->line(sprintf(
                '    farm_type=%-8s region=%-12s %12s rows across %d farm(s)',
                $row['farm_type'],
                $row['region'],
                number_format((int) $row['n']),
                (int) $row['farms'],
            ));
        }

        $split = $db->query(<<<SQL
            SELECT type, count(*) AS n
            FROM {$alias}.transaction_lines
            WHERE farm_id LIKE 'scale-%'
            GROUP BY type
            ORDER BY type
            SQL);

        $this->line('');
        $this->info('Actuals / forecast split (scale farms):');
        foreach ($split->rows(true) as $row) {
            $this->line(sprintf('    %-10s %12s rows', $row['type'], number_format((int) $row['n'])));
        }
    }

    private function duration(float $seconds): string
    {
        return $seconds < 60
            ? number_format($seconds, 1).'s'
            : sprintf('%dm %ds', (int) ($seconds / 60), (int) $seconds % 60);
    }
}
