<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AlloyDb\CashFlowActualsForecastPgQuery;
use App\Services\CashFlow\CashFlowActualsForecastFarmSeeder;
use App\Services\CashFlow\CashFlowActualsForecastOptions;
use App\Services\CashFlow\CashFlowActualsForecastOracleSeeder;
use App\Services\CashFlow\CashFlowActualsForecastQuery;
use App\Services\CashFlow\CashFlowActualsForecastReport;
use App\Services\CashFlow\ReportPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Runs the actuals-plus-forecast Cash Flow and, on the oracle farm, checks
 * every cell against the grid worked out by hand from the seeder's lines —
 * three passes: EOY journals excluded, EOY journals included, and with an
 * overdraft configured so the interest recurrence has something to charge.
 *
 * The overdraft pass computes its expectation with the same recurrence
 * Figured's `OverdraftCalculationService` runs (verified against Figured's
 * own oracle in Phase 3), over the hand-derived closing balances, so the
 * SQL is checked against an independent evaluation rather than itself.
 */
class DuckDbCashFlowAfRunCommand extends Command
{
    protected $signature = 'duckdb:cashflow-af:run
        {--farm= : Farm (defaults to the oracle farm, which is also checked)}
        {--from= : First day of the period (defaults to the start of the farm FY2027)}
        {--to= : Last day of the period, inclusive (defaults to the end of the farm FY2027)}
        {--horizon=2026-06-30 : Actuals to this date, forecast after}
        {--include-eoy : Do not exclude end-of-year adjustment journals}
        {--with-overdraft : Add the overdraft limit and headroom rows}
        {--no-total : Drop the Total column}
        {--consolidated : No per-tracker blocks}
        {--repeats=1 : Timed runs}
        {--sql : Print the statement}
        {--engine=duckdb : duckdb (DuckLake, from app-minio) or alloydb (from app-alloydb)}';

    protected $description = 'Run the actuals-plus-forecast Cash Flow as one statement on DuckLake or AlloyDB, checking the oracle farm cell by cell';

    private const int FIXED_POINT = 10000;

    /** @var array<string, list<float>> Jun 2026 .. May 2027, EOY excluded, no overdraft */
    private const array ORACLE = [
        'trackers_gross_profit' => [1700, 500, 1700, -100, 0, 0, 0, 0, 0, 0, 0, 0],
        'other_income' => [150, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        'direct_costs' => [0, 400, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        'gross_profit' => [1850, 100, 1700, -100, 0, 0, 0, 0, 0, 0, 0, 0],
        'operating_expenses' => [500, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        'operating_surplus' => [1350, 100, 1700, -100, 0, 0, 0, 0, 0, 0, 0, 0],
        'non_operating_income' => [0, 0, 0, 0, 50, 0, 0, 0, 0, 0, 0, 0],
        'non_operating_expenses' => [0, 0, 0, 0, 30, 0, 0, 0, 0, 0, 0, 0],
        'total_surplus' => [1350, 100, 1700, -100, 20, 0, 0, 0, 0, 0, 0, 0],
        'non_operating_movements' => [0, 0, 0, 0, 0, -5001, -200, 0, 0, 0, 0, 0],
        'equity_movements' => [0, 0, 0, 0, 0, 0, 0, -250, 0, 0, 0, 0],
        'gst' => [-30, 40, -100, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        'net_cash_movement' => [1320, 140, 1600, -100, 20, -5001, -200, -250, 0, 0, 0, 0],
        'opening' => [1000, 2320, 2460, 4060, 3960, 3980, -1021, -1221, -1471, -1471, -1471, -1471],
        'closing' => [2320, 2460, 4060, 3960, 3980, -1021, -1221, -1471, -1471, -1471, -1471, -1471],
    ];

    /** @var array<string, array<string, list<float>>> tracker id => field => months */
    private const array ORACLE_TRACKERS = [
        CashFlowActualsForecastOracleSeeder::MILK_TRACKER => [
            'tracker_income' => [2000, 500, 1000, 100, 0, 0, 0, 0, 0, 0, 0, 0],
            'tracker_costs' => [300, 0, 0, 200, 0, 0, 0, 0, 0, 0, 0, 0],
            'tracker_gross_profit' => [1700, 500, 1000, -100, 0, 0, 0, 0, 0, 0, 0, 0],
        ],
        CashFlowActualsForecastOracleSeeder::STOCK_TRACKER => [
            'tracker_income' => [0, 0, 800, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            'tracker_costs' => [0, 0, 100, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            'tracker_gross_profit' => [0, 0, 700, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        ],
    ];

    public function handle(): int
    {
        if (!in_array($this->option('engine'), ['duckdb', 'alloydb'], true)) {
            $this->error('--engine must be duckdb or alloydb.');

            return self::FAILURE;
        }

        // The oracle's overdraft pass writes its configuration through the
        // default connection, which is AlloyDB only inside app-alloydb.
        if ($this->option('engine') === 'alloydb' && config('database.default') !== 'alloydb') {
            $this->error('Run --engine=alloydb from the app-alloydb container.');

            return self::FAILURE;
        }

        $farmId = (string) ($this->option('farm') ?: CashFlowActualsForecastOracleSeeder::FARM_ID);
        try {
            $period = $this->period($farmId);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $horizon = (string) $this->option('horizon');

        $options = new CashFlowActualsForecastOptions(
            groupByTracker: !$this->option('consolidated'),
            excludeEoyJournals: !$this->option('include-eoy'),
            withTotal: !$this->option('no-total'),
            withOverdraft: (bool) $this->option('with-overdraft'),
        );

        $query = $this->report($options);

        if ($this->option('sql')) {
            $this->line($query->sql());

            return self::SUCCESS;
        }

        $this->info(sprintf('Cash Flow (actuals + forecast) — %s, %s, actuals to %s', $farmId, $period->label(), $horizon));

        try {
            $rows = [];
            $times = [];
            for ($i = 0; $i < max(1, (int) $this->option('repeats')); $i++) {
                $t = hrtime(true);
                $rows = $query->run($farmId, $period, $horizon);
                $times[] = (hrtime(true) - $t) / 1e6;
            }
        } catch (Throwable $e) {
            $this->error('Query failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->error('No rows — is the farm seeded? php artisan duckdb:cashflow-af:seed');

            return self::FAILURE;
        }

        $this->printReport($rows, $query);
        sort($times);
        $this->line(sprintf('  statement: median %.1f ms over %d run(s) (min %.1f, max %.1f)', $times[intdiv(count($times), 2)], count($times), $times[0], $times[count($times) - 1]));

        if ($farmId !== CashFlowActualsForecastOracleSeeder::FARM_ID) {
            return self::SUCCESS;
        }

        return $this->checkOracle();
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function printReport(array $rows, CashFlowActualsForecastReport $query): void
    {
        $farm = array_values(array_filter($rows, static fn (array $r): bool => $r['scope'] === 'farm'));
        $this->line('');
        $header = sprintf('%-26s', 'Row');
        foreach ($farm as $row) {
            $header .= sprintf('%11s', $row['month'] === 'Total' ? 'Total' : substr((string) $row['month'], 2));
        }
        $this->line($header);
        $this->line(str_repeat('-', strlen($header)));

        foreach ($query->definition()->displayRows($query->options()->withOverdraft) as $def) {
            $line = sprintf('%-26s', $def['label']);
            foreach ($farm as $row) {
                $value = $row[$def['field']];
                $line .= sprintf('%11s', $value === null ? '' : number_format((float) (string) $value, 2));
            }
            $this->line($line);
        }

        $trackers = array_values(array_filter($rows, static fn (array $r): bool => $r['scope'] !== 'farm'));
        if ($trackers !== []) {
            $byTracker = [];
            foreach ($trackers as $row) {
                $byTracker[(string) $row['scope']][] = $row;
            }
            $this->line('');
            $this->line(sprintf('  %d tracker block(s):', count($byTracker)));
            foreach ($byTracker as $id => $trackerRows) {
                $name = (string) $trackerRows[0]['tracker_name'];
                foreach ($query->definition()->trackerDisplayRows() as $def) {
                    $line = sprintf('%-26s', substr($name.' '.$def['label'], 0, 26));
                    foreach ($trackerRows as $row) {
                        $line .= sprintf('%11s', number_format((float) (string) $row[$def['field']], 2));
                    }
                    $this->line($line);
                }
            }
        }
        $this->line('');
    }

    private function period(string $farmId): ReportPeriod
    {
        $default = ReportPeriod::financialYear(CashFlowActualsForecastFarmSeeder::PERIOD_YEAR, $this->financialYearEndMonth($farmId));

        return ReportPeriod::between(
            (string) ($this->option('from') ?: $default->from),
            (string) ($this->option('to') ?: $default->to),
        );
    }

    private function financialYearEndMonth(string $farmId): int
    {
        return (int) (DB::table('farms')->where('farm_id', $farmId)->value('financial_year_end_month') ?? 12);
    }

    private function report(CashFlowActualsForecastOptions $options): CashFlowActualsForecastReport
    {
        if ($this->option('engine') === 'alloydb') {
            return new CashFlowActualsForecastPgQuery(DB::connection('alloydb'), $options);
        }

        return new CashFlowActualsForecastQuery(
            app(DuckDB::class),
            config('duckdb.attached_alias'),
            config('duckdb.app_database.alias'),
            $options,
        );
    }

    private function checkOracle(): int
    {
        $farmId = CashFlowActualsForecastOracleSeeder::FARM_ID;
        $period = ReportPeriod::financialYear(CashFlowActualsForecastOracleSeeder::PERIOD_YEAR, $this->financialYearEndMonth($farmId));
        $horizon = CashFlowActualsForecastOracleSeeder::HORIZON;
        // Only its overdraft toggles are used, and they write through the
        // default connection; the lake handle is never touched on AlloyDB.
        $seeder = new CashFlowActualsForecastOracleSeeder(
            $this->option('engine') === 'alloydb' ? DuckDB::create() : app(DuckDB::class),
            (string) config('duckdb.attached_alias'),
        );
        $seeder->removeOverdraft();

        $failures = [];

        // Pass 1: the default request — EOY excluded, Total column, tracker blocks.
        $rows = $this->report(new CashFlowActualsForecastOptions())->run($farmId, $period, $horizon);
        $expected = self::ORACLE;
        $failures = array_merge($failures, $this->diffFarm('eoy excluded', $rows, $expected, true));
        $failures = array_merge($failures, $this->diffTrackers('eoy excluded', $rows));
        $failures = array_merge($failures, $this->diffColumnTypes($rows));

        // Pass 2: EOY journals included — May's $900 wages adjustment appears.
        $rows = $this->report(new CashFlowActualsForecastOptions(excludeEoyJournals: false))->run($farmId, $period, $horizon);
        $expected = self::ORACLE;
        foreach (['operating_expenses' => 900, 'operating_surplus' => -900, 'total_surplus' => -900, 'net_cash_movement' => -900, 'closing' => -900] as $field => $delta) {
            $expected[$field][11] += $delta;
        }
        $failures = array_merge($failures, $this->diffFarm('eoy included', $rows, $expected, true));

        // Pass 3: overdraft configured — interest accrues from November.
        $seeder->configureOverdraft();
        $rows = $this->report(new CashFlowActualsForecastOptions(withOverdraft: true))->run($farmId, $period, $horizon);
        $seeder->removeOverdraft();
        $failures = array_merge($failures, $this->diffFarm('overdraft', $rows, $this->withOverdraftInterest(self::ORACLE), true));
        $failures = array_merge($failures, $this->diffOverdraftRows($rows));

        $this->line('');
        if ($failures !== []) {
            $this->error('✘ ORACLE FAILED — '.count($failures).' mismatch(es):');
            foreach (array_slice($failures, 0, 40) as $failure) {
                $this->line('    '.$failure);
            }

            return self::FAILURE;
        }

        $cells = count(self::ORACLE) * 13 * 3 + 2 * 3 * 13 + 12 * 2;
        $this->info("✔ ORACLE PASSED — {$cells} cells across three passes match the hand-computed grid.");

        return self::SUCCESS;
    }

    /**
     * Figured's recurrence over the hand-derived closing balances: interest
     * on the overdrawn position net of interest already accrued, at 5%
     * annually, posted monthly, truncated to the fixed-point unit.
     *
     * @param array<string, list<float>> $base
     * @return array<string, list<float>>
     */
    private function withOverdraftInterest(array $base): array
    {
        $monthlyRate = (CashFlowActualsForecastOracleSeeder::OVERDRAFT_RATE / 10000.0 / 100.0) / 12.0;
        $cum = 0.0;
        $postedSoFar = 0;
        $expected = $base;

        foreach ($base['closing'] as $i => $closingDollars) {
            $principal = $closingDollars * self::FIXED_POINT - $cum;
            $interest = $principal < 0 ? -$principal * $monthlyRate : 0.0;
            $cum += $interest;
            $posted = (int) floor($interest);

            $expected['opening'][$i] -= $postedSoFar / self::FIXED_POINT;
            $postedSoFar += $posted;
            $dollars = $posted / self::FIXED_POINT;
            foreach (['non_operating_expenses' => 1, 'total_surplus' => -1, 'net_cash_movement' => -1] as $field => $sign) {
                $expected[$field][$i] += $sign * $dollars;
            }
            $expected['closing'][$i] -= $postedSoFar / self::FIXED_POINT;
        }

        return $expected;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, list<float>> $expected
     * @return list<string>
     */
    private function diffFarm(string $pass, array $rows, array $expected, bool $withTotal): array
    {
        $farm = array_values(array_filter($rows, static fn (array $r): bool => $r['scope'] === 'farm'));
        $failures = [];

        if (count($farm) !== 12 + ($withTotal ? 1 : 0)) {
            return ["[{$pass}] expected ".(12 + ($withTotal ? 1 : 0)).' farm rows, got '.count($farm)];
        }

        foreach ($expected as $field => $months) {
            foreach ($months as $i => $value) {
                $actual = (float) (string) $farm[$i][$field];
                if (abs($actual - $value) > 0.00005) {
                    $failures[] = sprintf('[%s] %s[%s]: expected %s, got %s', $pass, $field, $farm[$i]['month'], number_format($value, 4), number_format($actual, 4));
                }
            }

            if (!$withTotal) {
                continue;
            }

            $total = match ($field) {
                'opening' => $months[0],
                'closing' => $months[11],
                default => array_sum($months),
            };
            $actual = (float) (string) $farm[12][$field];
            if ($farm[12]['month'] !== 'Total' || abs($actual - $total) > 0.00005) {
                $failures[] = sprintf('[%s] %s[Total]: expected %s, got %s', $pass, $field, number_format($total, 4), number_format($actual, 4));
            }
        }

        return $failures;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function diffTrackers(string $pass, array $rows): array
    {
        $failures = [];

        foreach (self::ORACLE_TRACKERS as $trackerId => $fields) {
            $block = array_values(array_filter($rows, static fn (array $r): bool => $r['scope'] === $trackerId));
            if (count($block) !== 13) {
                $failures[] = "[{$pass}] {$trackerId}: expected 13 rows, got ".count($block);
                continue;
            }

            foreach ($fields as $field => $months) {
                foreach ($months as $i => $value) {
                    $actual = (float) (string) $block[$i][$field];
                    if (abs($actual - $value) > 0.00005) {
                        $failures[] = sprintf('[%s] %s.%s[%s]: expected %s, got %s', $pass, $trackerId, $field, $block[$i]['month'], number_format($value, 2), number_format($actual, 2));
                    }
                }
                $actual = (float) (string) $block[12][$field];
                if (abs($actual - array_sum($months)) > 0.00005) {
                    $failures[] = sprintf('[%s] %s.%s[Total]: expected %s, got %s', $pass, $trackerId, $field, number_format(array_sum($months), 2), number_format($actual, 2));
                }
            }
        }

        return $failures;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function diffColumnTypes(array $rows): array
    {
        $farm = array_values(array_filter($rows, static fn (array $r): bool => $r['scope'] === 'farm'));
        $failures = [];

        foreach ($farm as $i => $row) {
            $expected = $i === 0 ? 'actuals' : ($i === 12 ? 'total' : 'forecast');
            if ($row['column_type'] !== $expected) {
                $failures[] = sprintf('column_type[%s]: expected %s, got %s', $row['month'], $expected, $row['column_type']);
            }
        }

        if (($farm[0]['month'] ?? null) !== '2026-06' || ($farm[11]['month'] ?? null) !== '2027-05') {
            $failures[] = 'period: expected 2026-06 .. 2027-05 for FY2027 with a May balance date';
        }

        return $failures;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function diffOverdraftRows(array $rows): array
    {
        $farm = array_values(array_filter($rows, static fn (array $r): bool => $r['scope'] === 'farm'));
        $failures = [];
        $limit = CashFlowActualsForecastOracleSeeder::OVERDRAFT_LIMIT_DOLLARS;

        foreach (array_slice($farm, 0, 12) as $row) {
            $closing = (float) (string) $row['closing'];
            if (abs((float) (string) $row['overdraft_limit'] - $limit) > 0.00005) {
                $failures[] = sprintf('[overdraft] overdraft_limit[%s]: expected %d, got %s', $row['month'], $limit, $row['overdraft_limit']);
            }
            if (abs((float) (string) $row['overdraft_headroom'] - ($closing + $limit)) > 0.00005) {
                $failures[] = sprintf('[overdraft] overdraft_headroom[%s]: expected %s, got %s', $row['month'], number_format($closing + $limit, 4), $row['overdraft_headroom']);
            }
        }

        if ($farm[12]['overdraft_limit'] !== null || $farm[12]['overdraft_headroom'] !== null) {
            $failures[] = '[overdraft] the Total column must leave the overdraft rows blank';
        }

        return $failures;
    }
}
