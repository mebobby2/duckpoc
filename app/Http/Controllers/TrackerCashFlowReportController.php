<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CashFlow\ParquetFileLister;
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

    /** Source rows shown in the viewer before truncating. */
    private const int SOURCE_ROW_LIMIT = 500;

    /**
     * Above this many rows in scope, the source-row listing is skipped unless
     * `?force_source_rows=1`. See the call site for why.
     */
    private const int SOURCE_LISTING_MAX_ROWS = 5_000_000;

    /**
     * If the report itself took longer than this, the diagnostic scans are
     * skipped unless `?force_diagnostics=1` — the report's own elapsed time is
     * a free proxy for how much data is in scope.
     */
    private const float DIAGNOSTICS_BUDGET_MS = 2000.0;

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
        $sourceRows = [];
        $sourceSummary = ['n' => 0, 'n_tracker_tagged' => 0, 'n_trackers' => 0, 'net_dollars' => 0.0];
        $files = [];
        $error = null;
        $reportMs = null;
        $detailMs = null;
        $summaryMs = null;
        $sourceRowsMs = null;
        $sourceRowsSkipped = false;
        $diagnosticsAffordable = true;

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

                // Both diagnostics below are full scans of everything in
                // scope, on top of the two the report already did. On the
                // billion-row hero farm that was fatal: four scans of ~1.5 GB
                // blew through PHP's execution limit, and because
                // `php artisan serve` is single-process the resulting fatal
                // killed the whole server rather than just this request.
                //
                // Gated on the report's OWN measured time, which is free — an
                // earlier attempt gated the listing on a row count from
                // `sourceRowSummary()`, which is itself a full scan, so the
                // guard could never fire before paying the cost it was meant
                // to avoid. If the report came back quickly, the data is small
                // enough that the diagnostics are cheap too.
                $diagnosticsAffordable = $reportMs < self::DIAGNOSTICS_BUDGET_MS
                    || $request->boolean('force_diagnostics');

                if ($diagnosticsAffordable) {
                    $startedAt = microtime(true);
                    $sourceSummary = $query->sourceRowSummary(...$scope);
                    $summaryMs = (microtime(true) - $startedAt) * 1000;

                    $sourceRowsSkipped = $sourceSummary['n'] > self::SOURCE_LISTING_MAX_ROWS
                        && !$request->boolean('force_source_rows');

                    if (!$sourceRowsSkipped) {
                        $startedAt = microtime(true);
                        $sourceRows = $query->sourceRows(...$scope, limit: self::SOURCE_ROW_LIMIT);
                        $sourceRowsMs = (microtime(true) - $startedAt) * 1000;
                    }
                }

                // Catalog metadata only — no data scan — so this stays on
                // regardless of volume. It is also the most useful panel at
                // scale, since it shows which partitions were skippable.
                $files = (new ParquetFileLister($db, $alias))
                    ->forQuery($farm, $periodFrom, $periodTo);
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
            'sourceRows' => $sourceRows,
            'sourceSummary' => $sourceSummary,
            'sourceRowLimit' => self::SOURCE_ROW_LIMIT,
            'sourceRowsSkipped' => $sourceRowsSkipped,
            'sourceListingMaxRows' => self::SOURCE_LISTING_MAX_ROWS,
            'summaryMs' => $summaryMs,
            'sourceRowsMs' => $sourceRowsMs,
            'diagnosticsAffordable' => $diagnosticsAffordable,
            'diagnosticsBudgetMs' => self::DIAGNOSTICS_BUDGET_MS,
            'files' => $files,
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
