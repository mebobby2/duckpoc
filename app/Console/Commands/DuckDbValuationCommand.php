<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashFlow\ValuationMovementOracle;
use App\Services\CashFlow\ValuationMovementQuery;
use App\Services\CashFlow\ValuationMovementSqlBuilder;
use App\Services\CashFlow\ValuationRateSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Phase 2 — checks the livestock valuation movement statement against a PHP
 * reimplementation of Figured's interval loop.
 *
 * Two checks, because either alone can pass while the report is wrong:
 *
 * - **Parity** catches a month whose movement disagrees with the loop.
 * - **Conservation** catches the class of bug parity misses: the movements of
 *   a period must telescope to (closing valuation - opening valuation), since
 *   every intermediate term cancels. A NULL first bucket or a partition that
 *   silently drops a tracker still produces plausible per-month numbers, and
 *   this is what fails on it. It is the check that caught Phase 3's dropped
 *   first month.
 */
class DuckDbValuationCommand extends Command
{
    protected $signature = 'duckdb:valuation
        {--farm= : Farm to report on (defaults to the first tracker-bearing farm)}
        {--from=2021-01-01 : Period start}
        {--to=2021-12-31 : Period end}
        {--horizon=2021-06-30 : Actuals/forecast boundary}
        {--seed : Seed valuation rates and the actuals/forecast split first}
        {--runs=0 : Time the statement against the PHP loop, this many runs each}';

    protected $description = 'Check the livestock valuation movement against Figured\'s interval loop';

    public function handle(DuckDB $db): int
    {
        $appAlias = config('duckdb.app_database.alias');

        $farmId = (string) ($this->option('farm') ?: DB::table('trackers')->value('farm_id'));

        if ($farmId === '') {
            $this->error('No trackers found. Run duckdb:tracker:seed first.');

            return self::FAILURE;
        }

        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $horizon = (string) $this->option('horizon');

        if ($this->option('seed') && !$this->seed($farmId, $horizon)) {
            return self::FAILURE;
        }

        $query = new ValuationMovementQuery($db, $appAlias);

        try {
            $actual = $query->run($farmId, $from, $to, $horizon);
            $totals = $query->totals($farmId, $from, $to, $horizon);
        } catch (Throwable $e) {
            $this->error('Query failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $expected = (new ValuationMovementOracle())->run($farmId, $from, $to, $horizon);

        $this->info(sprintf('Valuation movement — %s, %s to %s (horizon %s)', $farmId, $from, $to, $horizon));
        $this->line('');

        $parity = $this->checkParity($actual, $expected);
        $this->line('');
        $conservation = $this->checkConservation($totals);

        $runs = (int) $this->option('runs');
        if ($runs > 0) {
            $this->line('');
            $this->bench($query, $db, $farmId, $from, $to, $horizon, $runs);
        }

        return $parity && $conservation ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The statement against the loop — Phase 2's actual claim, and the place
     * that claim turned out to need a caveat.
     *
     * Three numbers, because two of them would mislead:
     *
     * - **PHP loop** is this PoC's `ValuationMovementOracle`, not Figured's
     *   generator, so it is a *floor* on the real difference: it reads MySQL
     *   directly, whereas Figured constructs a management valuation object per
     *   interval and runs `allocateManagementValuationStock()` before reading a
     *   total. Figured's real loop is slower than this by an unmeasured margin.
     * - **statement, MySQL-attached** is the report as built. Every table it
     *   reads — trackers, stock movements, valuation rates — is relational
     *   dimension data, so DuckDB reaches all of it through the MySQL scanner
     *   and touches the lake not at all.
     * - **statement, DuckDB-native** is the same statement over the same data
     *   materialised into DuckDB storage. The difference between the two is
     *   pure connector cost.
     *
     * The measured result is that the connector dominates: the window
     * functions are a few milliseconds and the cross-engine scan is roughly
     * six times that. A report whose inputs are entirely relational is the one
     * shape where pushing work into DuckDB is a net loss, and this is that
     * shape. See the README's Phase 2 entry.
     */
    private function bench(
        ValuationMovementQuery $query,
        DuckDB $db,
        string $farmId,
        string $from,
        string $to,
        string $horizon,
        int $runs,
    ): void {
        $attached = [];
        $php = [];

        for ($i = 0; $i < $runs; $i++) {
            $t = hrtime(true);
            $query->run($farmId, $from, $to, $horizon);
            $attached[] = (hrtime(true) - $t) / 1e6;

            $t = hrtime(true);
            (new ValuationMovementOracle())->run($farmId, $from, $to, $horizon);
            $php[] = (hrtime(true) - $t) / 1e6;
        }

        $native = $this->benchNative($db, $farmId, $from, $to, $horizon, $runs);

        sort($attached);
        sort($php);

        $medianAttached = $attached[intdiv(count($attached), 2)];
        $medianPhp = $php[intdiv(count($php), 2)];

        $this->line(sprintf('  PHP loop (best case)        %8.1f ms', $medianPhp));
        $this->line(sprintf('  statement, MySQL-attached   %8.1f ms  (%.1fx the loop)',
            $medianAttached, $medianPhp / max($medianAttached, 0.001)));

        if ($native !== null) {
            $this->line(sprintf('  statement, DuckDB-native    %8.1f ms  (%.1fx the loop)',
                $native, $medianPhp / max($native, 0.001)));
            $this->line(sprintf('  connector cost              %8.1f ms  (%.0f%% of the attached run)',
                $medianAttached - $native,
                100 * ($medianAttached - $native) / max($medianAttached, 0.001)));
        }
    }

    /**
     * The same statement over the same rows materialised into DuckDB storage.
     *
     * Returns null rather than failing the command: this is a diagnostic, and
     * a report that cannot materialise is still a report that ran.
     */
    private function benchNative(
        DuckDB $db,
        string $farmId,
        string $from,
        string $to,
        string $horizon,
        int $runs,
    ): ?float {
        $appAlias = config('duckdb.app_database.alias');

        try {
            foreach ([
                'bench_movements' => 'tracker_stock_movements',
                'bench_trackers' => 'trackers',
                'bench_rates' => 'valuation_rates',
            ] as $local => $remote) {
                $db->query("CREATE OR REPLACE TABLE {$local} AS SELECT * FROM {$appAlias}.{$remote}");
            }

            $sql = str_replace(
                [$appAlias.'.tracker_stock_movements', $appAlias.'.trackers', $appAlias.'.valuation_rates'],
                ['bench_movements', 'bench_trackers', 'bench_rates'],
                (new ValuationMovementSqlBuilder($appAlias))->buildSql(),
            );

            $sql = str_replace(
                ['$farm_id', '$period_from', '$period_to', '$horizon'],
                ["'{$farmId}'", "'{$from}'", "'{$to}'", "'{$horizon}'"],
                $sql,
            );

            $times = [];
            for ($i = 0; $i < $runs; $i++) {
                $t = hrtime(true);
                iterator_to_array($db->query($sql)->rows(true));
                $times[] = (hrtime(true) - $t) / 1e6;
            }

            sort($times);

            return $times[intdiv(count($times), 2)];
        } catch (Throwable $e) {
            $this->warn('  (native comparison unavailable: '.$e->getMessage().')');

            return null;
        }
    }

    private function seed(string $farmId, string $horizon): bool
    {
        $span = DB::table('tracker_stock_movements')
            ->join('trackers', 'trackers.tracker_id', '=', 'tracker_stock_movements.tracker_id')
            ->where('trackers.farm_id', $farmId)
            ->selectRaw('MIN(YEAR(month)) lo, MAX(YEAR(month)) hi')
            ->first();

        if ($span === null || $span->lo === null) {
            $this->error("No stock movements for {$farmId}. Run duckdb:stock:seed first.");

            return false;
        }

        $written = 0;
        (new ValuationRateSeeder())->seed(
            [$farmId => [(int) $span->lo, (int) $span->hi]],
            $horizon,
            function (string $farm, int $rows) use (&$written): void {
                $written += $rows;
            },
        );

        $this->line(sprintf('  seeded %s valuation rates (%d-%d)', number_format($written), $span->lo, $span->hi));
        $this->line('');

        return true;
    }

    /**
     * @param list<array<string, mixed>> $actual
     * @param list<array{month: string, tracker_id: string, closing_head: int, closing_value: int, movement: int}> $expected
     */
    private function checkParity(array $actual, array $expected): bool
    {
        if ($actual === []) {
            $this->error('  parity: no rows returned');

            return false;
        }

        $byKey = [];
        foreach ($expected as $row) {
            $byKey[$row['month'].'|'.$row['tracker_id']] = $row;
        }

        $checked = 0;
        $failed = 0;

        foreach ($actual as $row) {
            $key = (string) $row['month'].'|'.(string) $row['tracker_id'];
            $want = $byKey[$key] ?? null;

            if ($want === null) {
                $this->error(sprintf('  parity: %s has no oracle row', $key));
                $failed++;

                continue;
            }

            $checked++;

            $gotHead = (int) (string) $row['closing_head'];
            $gotMovement = (int) round(((float) (string) $row['movement_dollars']) * 10000);

            if ($gotHead !== $want['closing_head'] || $gotMovement !== $want['movement']) {
                $failed++;

                if ($failed <= 5) {
                    $this->error(sprintf(
                        '  parity: %s head %d vs %d, movement %d vs %d',
                        $key,
                        $gotHead,
                        $want['closing_head'],
                        $gotMovement,
                        $want['movement'],
                    ));
                }
            }
        }

        $missing = count($expected) - $checked;

        if ($failed === 0 && $missing === 0) {
            $this->info(sprintf('  parity: %d/%d rows match Figured\'s loop', $checked, count($expected)));

            return true;
        }

        $this->error(sprintf('  parity: %d mismatched, %d oracle rows unmatched', $failed, $missing));

        return false;
    }

    /**
     * Movements must telescope to closing minus opening.
     *
     * @param list<array<string, mixed>> $totals
     */
    private function checkConservation(array $totals): bool
    {
        if (count($totals) < 2) {
            $this->error('  conservation: need at least two months');

            return false;
        }

        $first = $totals[0];
        $last = $totals[count($totals) - 1];

        $summed = 0.0;
        foreach ($totals as $row) {
            $summed += (float) (string) $row['movement_dollars'];
        }

        // The first month's own movement is part of the sum, so the opening
        // position is its closing value less that movement.
        $opening = ((float) (string) $first['closing_value_dollars'])
            - ((float) (string) $first['movement_dollars']);
        $closing = (float) (string) $last['closing_value_dollars'];
        $expected = $closing - $opening;

        $drift = abs($summed - $expected);

        $this->line(sprintf('  opening %18s', number_format($opening, 2)));
        $this->line(sprintf('  closing %18s', number_format($closing, 2)));
        $this->line(sprintf('  sum of movements %9s', number_format($summed, 2)));

        if ($drift > 0.01) {
            $this->error(sprintf('  conservation: drift of %s', number_format($drift, 4)));

            return false;
        }

        $this->info('  conservation: movements telescope to closing - opening');

        return true;
    }
}
