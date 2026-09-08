<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashFlow\HeroTrackerSeeder;
use App\Services\CashFlow\TrackerCashFlowQuery;
use Illuminate\Console\Command;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Benchmarks the per-tracker Cash Flow report on the hero farm across
 * widening period windows.
 *
 * The point is the *shape* of the curve, not any single number. Widening the
 * period widens both the month spine and the rows in scope, so this shows how
 * report time responds to rows actually scanned — with tracker count held at
 * 50 throughout, since `duckdb:tracker:curve` already covers that axis.
 *
 * Every window uses a horizon that puts both actuals and forecast in scope
 * where the window spans the boundary, so no window is quietly measuring less
 * data than its row count implies.
 */
class DuckDbHeroBenchCommand extends Command
{
    protected $signature = 'duckdb:hero:bench {--runs=3 : Runs per window; the median is reported}';

    protected $description = 'Benchmark the tracker Cash Flow report on the hero farm across widening periods';

    /** label => [period_from, period_to] */
    private const array WINDOWS = [
        '1 month' => ['2021-01-01', '2021-01-31'],
        '1 year' => ['2021-01-01', '2021-12-31'],
        '2 years' => ['2021-01-01', '2022-12-31'],
        '5 years' => ['2018-01-01', '2022-12-31'],
        '10 years (all)' => ['2016-01-01', '2025-12-31'],
    ];

    /** On the actuals/forecast boundary, so nothing falls in the horizon gap. */
    private const string HORIZON = '2021-12-31';

    public function handle(DuckDB $db): int
    {
        $runs = max(1, (int) $this->option('runs'));
        $alias = config('duckdb.attached_alias');
        $query = new TrackerCashFlowQuery($db, $alias);

        $this->info(sprintf(
            'Hero farm benchmark — %s, %d trackers, %d run(s) per window',
            HeroTrackerSeeder::FARM_ID,
            HeroTrackerSeeder::TRACKER_COUNT,
            $runs,
        ));
        $this->line('');
        $this->line(sprintf(
            '  %-16s %16s %10s %10s %10s %14s',
            'window', 'rows in scope', 'median', 'min', 'max', 'rows/s'
        ));
        $this->line('  '.str_repeat('-', 82));

        foreach (self::WINDOWS as $label => [$from, $to]) {
            try {
                $summary = $query->sourceRowSummary(
                    HeroTrackerSeeder::FARM_ID,
                    HeroTrackerSeeder::FARM_TYPE,
                    HeroTrackerSeeder::REGION,
                    $from,
                    $to,
                    self::HORIZON,
                );

                $timings = [];

                for ($i = 0; $i < $runs; $i++) {
                    $startedAt = microtime(true);

                    $query->run(
                        HeroTrackerSeeder::FARM_ID,
                        HeroTrackerSeeder::FARM_TYPE,
                        HeroTrackerSeeder::REGION,
                        $from,
                        $to,
                        self::HORIZON,
                    );

                    $timings[] = (microtime(true) - $startedAt) * 1000;
                }
            } catch (Throwable $e) {
                $this->error("  {$label}: ".$e->getMessage());

                return self::FAILURE;
            }

            $median = $this->median($timings);

            $this->line(sprintf(
                '  %-16s %16s %8.0f ms %7.0f ms %7.0f ms %14s',
                $label,
                number_format($summary['n']),
                $median,
                min($timings),
                max($timings),
                $median > 0 ? number_format($summary['n'] / ($median / 1000)) : 'n/a',
            ));
        }

        $this->line('');
        $this->warn('Runs share a process, so later windows read a warmed cache. The');
        $this->warn('windows widen monotonically, so that bias flatters the wide ones —');
        $this->warn('read the rows/s column as a floor, not a ceiling.');

        return self::SUCCESS;
    }

    /**
     * @param list<float> $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$mid]
            : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
