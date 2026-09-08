<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashFlow\TrackerCashFlowQuery;
use App\Services\CashFlow\TrackerScaleSeeder;
use Illuminate\Console\Command;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * The 1 vs 10 vs 50 tracker timing curve.
 *
 * Tests one claim: that per-tracker report sections cost nothing extra in a
 * columnar model, because tracker count is a GROUP BY cardinality rather than
 * a query multiplier. Journal volume is identical across the three farms (see
 * `TrackerScaleSeeder`), so tracker count is the only variable.
 *
 * Reports the median of several runs, not a single measurement. Timings
 * against remote object storage here have been seen to vary by up to 4x run
 * to run, and this project has twice drawn a wrong conclusion from one
 * sample — so a single number would not be evidence either way.
 */
class DuckDbTrackerCurveCommand extends Command
{
    protected $signature = 'duckdb:tracker:curve {--runs=5 : Runs per farm; the median is reported}';

    protected $description = 'Measure report time against tracker count (1/10/50) with journal volume held constant';

    /** Both halves in scope: 2021 actuals, 2022 forecast, horizon between. */
    private const string PERIOD_FROM = '2021-01-01';
    private const string PERIOD_TO = '2022-12-31';
    private const string HORIZON = '2021-12-31';

    public function handle(DuckDB $db): int
    {
        $runs = max(1, (int) $this->option('runs'));
        $query = new TrackerCashFlowQuery($db, config('duckdb.attached_alias'));

        $this->info(sprintf(
            'Tracker scaling curve — %s journals per farm (constant), %d run(s) per farm',
            number_format(TrackerScaleSeeder::rowsPerFarm()),
            $runs,
        ));
        $this->line(sprintf('Period %s..%s, horizon %s', self::PERIOD_FROM, self::PERIOD_TO, self::HORIZON));
        $this->line('');

        if (!$this->assertSqlIsIdenticalAcrossFarms($query)) {
            return self::FAILURE;
        }

        $this->line('');
        $this->line(sprintf('  %-18s %8s %10s %10s %10s %12s', 'farm', 'trackers', 'median', 'min', 'max', 'vs 1 tracker'));
        $this->line('  '.str_repeat('-', 72));

        $baseline = null;

        foreach (TrackerScaleSeeder::farms() as $farmId => [$farmType, $region, $trackerCount]) {
            try {
                $timings = $this->timeRuns($query, $farmId, $farmType, $region, $runs);
            } catch (Throwable $e) {
                $this->error("  {$farmId}: ".$e->getMessage());

                return self::FAILURE;
            }

            $median = $this->median($timings);
            $baseline ??= $median;

            $this->line(sprintf(
                '  %-18s %8d %8.0f ms %7.0f ms %7.0f ms %11s',
                $farmId,
                $trackerCount,
                $median,
                min($timings),
                max($timings),
                $baseline > 0 ? sprintf('%+.0f%%', (($median - $baseline) / $baseline) * 100) : 'n/a',
            ));
        }

        $this->line('');
        $this->line('Reading this: a flat column means tracker count is free — 50 trackers');
        $this->line('resolved in one grouped scan rather than 50 round trips. A rising');
        $this->line('column would mean the fan-out has simply moved rather than gone.');
        $this->line('');
        $this->warn('Caveat: these runs share a process, so later farms read a warmed');
        $this->warn('HTTP/metadata cache. That biases *against* the first farm measured');
        $this->warn('(1 tracker), so a flat result is still meaningful — but a small');
        $this->warn('apparent improvement with more trackers is cache, not speedup.');

        return self::SUCCESS;
    }

    /**
     * The structural half of the claim, and cheaper to prove than the timing:
     * if the SQL text is identical for 1 and 50 trackers, then no per-tracker
     * work can possibly be being generated.
     */
    private function assertSqlIsIdenticalAcrossFarms(TrackerCashFlowQuery $query): bool
    {
        $sql = $query->sql();

        $this->line(sprintf(
            '  Generated SQL: %d bytes, %d lines — identical for every farm, since',
            strlen($sql),
            substr_count($sql, "\n") + 1,
        ));
        $this->line('  nothing interpolates a tracker id or count into it.');

        // Nothing in the builder takes a tracker count, so this is a guard
        // against a future change quietly reintroducing per-tracker SQL.
        foreach (array_keys(TrackerScaleSeeder::farms()) as $farmId) {
            if (str_contains($sql, (string) $farmId)) {
                $this->error("  A farm id ({$farmId}) appears in the SQL text — it should be a bound parameter.");

                return false;
            }
        }

        return true;
    }

    /**
     * @return list<float> elapsed milliseconds, one per run
     */
    private function timeRuns(
        TrackerCashFlowQuery $query,
        string $farmId,
        string $farmType,
        string $region,
        int $runs,
    ): array {
        $timings = [];

        for ($i = 0; $i < $runs; $i++) {
            $started = microtime(true);

            $query->run(
                $farmId,
                $farmType,
                $region,
                self::PERIOD_FROM,
                self::PERIOD_TO,
                self::HORIZON,
            );

            $timings[] = (microtime(true) - $started) * 1000;
        }

        return $timings;
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
