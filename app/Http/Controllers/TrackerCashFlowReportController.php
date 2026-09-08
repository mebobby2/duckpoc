<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CashFlow\TrackerCashFlowQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Renders Cash Flow with per-tracker sections.
 *
 * Separate from `CashFlowReportController` on purpose — the plain Cash Flow
 * report is the parity-checked artefact (84/84 against Figured) and is left
 * untouched, so nothing here can regress it.
 *
 * What this page is for: showing that the number of trackers on a farm does
 * not change the cost of its report. Figured resolves per-tracker sections
 * with a query per tracker; this resolves them by grouping a single scan. The
 * page therefore reports tracker count and per-query timings prominently,
 * because those are the numbers under test.
 */
class TrackerCashFlowReportController extends Controller
{
    private const string DEFAULT_FARM_ID = 'tracker-farm-50';

    /**
     * Spans the actuals/forecast boundary the seeder creates (actuals through
     * 2021, forecast from 2022), with the horizon between — so every month in
     * the window has data. A horizon inside one of those halves leaves months
     * legitimately empty, which reads as a bug on a demo page.
     */
    private const string DEFAULT_PERIOD_FROM = '2021-01-01';
    private const string DEFAULT_PERIOD_TO = '2022-12-31';
    private const string DEFAULT_HORIZON = '2021-12-31';

    public function __invoke(Request $request, DuckDB $db): View
    {
        $alias = config('duckdb.attached_alias');

        $farms = $this->trackerFarms();

        $farmId = (string) $request->query('farm_id', self::DEFAULT_FARM_ID);
        $periodFrom = (string) $request->query('period_from', self::DEFAULT_PERIOD_FROM);
        $periodTo = (string) $request->query('period_to', self::DEFAULT_PERIOD_TO);
        $horizon = (string) $request->query('horizon', self::DEFAULT_HORIZON);
        $basis = (string) $request->query('basis', 'cash');

        $farm = collect($farms)->firstWhere('farm_id', $farmId) ?? ($farms[0] ?? null);

        $query = new TrackerCashFlowQuery($db, $alias);

        $rows = [];
        $trackerRows = [];
        $error = null;
        $reportMs = null;
        $detailMs = null;

        if ($farm === null) {
            $error = 'No tracker farms found. Run: php artisan duckdb:tracker:seed';
        } else {
            try {
                $scope = [
                    'farmId' => $farm['farm_id'],
                    'farmType' => $farm['farm_type'],
                    'region' => $farm['region'],
                    'periodFrom' => $periodFrom,
                    'periodTo' => $periodTo,
                    'horizon' => $horizon,
                    'basis' => $basis,
                ];

                $startedAt = microtime(true);
                $rows = $query->run(...$scope);
                $reportMs = (microtime(true) - $startedAt) * 1000;

                $startedAt = microtime(true);
                $trackerRows = $query->trackerDetail(...$scope);
                $detailMs = (microtime(true) - $startedAt) * 1000;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return view('tracker-cashflow', [
            'farms' => $farms,
            'farm' => $farm,
            'farmId' => $farm['farm_id'] ?? $farmId,
            'periodFrom' => $periodFrom,
            'periodTo' => $periodTo,
            'horizon' => $horizon,
            'basis' => $basis,
            'rows' => $rows,
            'reportRows' => $query->definition()->displayRows(),
            'trackerGrid' => $this->groupByTracker($trackerRows),
            'months' => array_map(static fn (array $row): string => (string) $row['month'], $rows),
            'sql' => $error === null ? $query->sql() : null,
            'detailSql' => $error === null ? $query->trackerDetailSql() : null,
            'error' => $error,
            'reportMs' => $reportMs,
            'detailMs' => $detailMs,
            'trackerCount' => $farm === null ? 0 : $this->trackerCount($farm['farm_id']),
        ]);
    }

    /**
     * Reshapes the flat (month, tracker) result into one entry per tracker
     * holding its months in order — the shape the table renders.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function groupByTracker(array $rows): array
    {
        $byTracker = [];

        foreach ($rows as $row) {
            $id = (string) $row['tracker_id'];

            $byTracker[$id] ??= [
                'tracker_id' => $id,
                'tracker_name' => (string) $row['tracker_name'],
                'stock_type' => (string) $row['stock_type'],
                'months' => [],
            ];

            $byTracker[$id]['months'][(string) $row['month']] = [
                'tracker_income' => (float) (string) $row['tracker_income'],
                'tracker_direct_costs' => (float) (string) $row['tracker_direct_costs'],
                'tracker_gross_profit' => (float) (string) $row['tracker_gross_profit'],
            ];
        }

        return array_values($byTracker);
    }

    /**
     * From MySQL — trackers are dimension data and live there, matching
     * Figured. The lake carries only `transaction_lines.tracker_id`.
     */
    private function trackerCount(string $farmId): int
    {
        try {
            return DB::table('trackers')->where('farm_id', $farmId)->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function trackerFarms(): array
    {
        try {
            return DB::table('farms')
                ->join('trackers', 'trackers.farm_id', '=', 'farms.farm_id')
                ->select('farms.farm_id', 'farms.farm_type', 'farms.region', 'farms.opening_balance')
                ->selectRaw('COUNT(trackers.tracker_id) AS tracker_count')
                ->groupBy('farms.farm_id', 'farms.farm_type', 'farms.region', 'farms.opening_balance')
                ->orderBy('farms.farm_id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
