<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CashFlow\GrossMarginQuery;
use App\Services\CashFlow\ParquetFileLister;
use App\Services\CashFlow\QueryProfiler;
use App\Services\CashFlow\StorageRequestProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Renders Gross Margin per operating entity — a tracker-level margin report.
 *
 * Separate from both Cash Flow controllers: this is the report Figured's
 * ex-CTO identified as the genuinely slow one ("the real slow bits is
 * generating a GM per operating entity of the farm"), and it is the first here
 * to need per-tracker *quantities* rather than journals alone.
 */
class GrossMarginReportController extends Controller
{
    private const string DEFAULT_FARM_ID = 'tracker-farm-10';

    private const string DEFAULT_PERIOD_FROM = '2021-01-01';
    private const string DEFAULT_PERIOD_TO = '2021-12-31';
    private const string DEFAULT_HORIZON = '2021-12-31';

    /** Source rows shown before truncating. */
    private const int SOURCE_ROW_LIMIT = 500;

    /**
     * Above this report time, the diagnostic scans are skipped unless
     * `?force_diagnostics=1` — the report's own elapsed time is a free proxy
     * for how much data is in scope. Same guard, and same reasoning, as the
     * tracker Cash Flow page.
     */
    private const float DIAGNOSTICS_BUDGET_MS = 30000.0;

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

        $query = new GrossMarginQuery($db, $alias);

        $rows = [];
        $error = null;
        $elapsedMs = null;
        $profile = null;
        $sourceRows = [];
        $sourceSummary = ['n' => 0, 'n_trackers' => 0, 'net_dollars' => 0.0];
        $files = [];
        $storage = null;
        $summaryMs = null;
        $sourceRowsMs = null;
        $diagnosticsAffordable = true;

        if ($farm === null) {
            $error = 'No tracker farms found. Run: php artisan duckdb:tracker:seed && php artisan duckdb:stock:seed';
        } else {
            try {
                // Armed before any lake read, so its counters cover this
                // report's own query rather than a re-run.
                $requests = new StorageRequestProfile($db);
                $requests->startLogging();

                $startedAt = microtime(true);

                $rows = $query->run(
                    $farm['farm_id'],
                    $farm['farm_type'],
                    $farm['region'],
                    $periodFrom,
                    $periodTo,
                    $horizon,
                    $basis,
                );

                $elapsedMs = (microtime(true) - $startedAt) * 1000;

                $scope = [
                    $farm['farm_id'],
                    $farm['farm_type'],
                    $farm['region'],
                    $periodFrom,
                    $periodTo,
                    $horizon,
                    $basis,
                ];

                $diagnosticsAffordable = $elapsedMs < self::DIAGNOSTICS_BUDGET_MS
                    || $request->boolean('force_diagnostics');

                if ($diagnosticsAffordable) {
                    $startedAt = microtime(true);
                    $sourceSummary = $query->sourceRowSummary(...$scope);
                    $summaryMs = (microtime(true) - $startedAt) * 1000;

                    $startedAt = microtime(true);
                    $sourceRows = $query->sourceRows(...$scope, limit: self::SOURCE_ROW_LIMIT);
                    $sourceRowsMs = (microtime(true) - $startedAt) * 1000;
                }

                // Catalog metadata only — no data scan — so it stays on at any
                // volume.
                $files = (new ParquetFileLister($db, $alias))
                    ->forQuery($farm, $periodFrom, $periodTo);

                $storage = $requests->summarise($files);

                if ($request->boolean('explain')) {
                    $profile = (new QueryProfiler($db))->profile($query->sql(), [
                        'farm_id' => $farm['farm_id'],
                        'farm_type' => $farm['farm_type'],
                        'region' => $farm['region'],
                        'basis' => $basis,
                        'period_from' => $periodFrom,
                        'period_to' => $periodTo,
                        'horizon' => $horizon,
                    ]);
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return view('gross-margin', [
            'farms' => $farms,
            'farm' => $farm,
            'farmId' => $farm['farm_id'] ?? $farmId,
            'periodFrom' => $periodFrom,
            'periodTo' => $periodTo,
            'horizon' => $horizon,
            'basis' => $basis,
            'trackers' => $this->groupByTracker($rows),
            'months' => $this->distinctMonths($rows),
            'reportRows' => $query->definition()->displayRows(),
            'sql' => $error === null ? $query->sql() : null,
            'error' => $error,
            'elapsedMs' => $elapsedMs,
            'profile' => $profile,
            'sourceRows' => $sourceRows,
            'sourceSummary' => $sourceSummary,
            'sourceRowLimit' => self::SOURCE_ROW_LIMIT,
            'summaryMs' => $summaryMs,
            'sourceRowsMs' => $sourceRowsMs,
            'diagnosticsAffordable' => $diagnosticsAffordable,
            'diagnosticsBudgetMs' => self::DIAGNOSTICS_BUDGET_MS,
            'files' => $files,
            'storage' => $storage,
            'trackerCount' => $farm === null ? 0 : $this->trackerCount($farm['farm_id']),
            'movementRowCount' => $farm === null ? 0 : $this->movementRowCount($farm['farm_id']),
        ]);
    }

    /**
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

            $byTracker[$id]['months'][(string) $row['month']] = $row;
        }

        return array_values($byTracker);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function distinctMonths(array $rows): array
    {
        $months = [];

        foreach ($rows as $row) {
            $months[(string) $row['month']] = true;
        }

        $keys = array_keys($months);
        sort($keys);

        return $keys;
    }

    private function trackerCount(string $farmId): int
    {
        try {
            return DB::table('trackers')->where('farm_id', $farmId)->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function movementRowCount(string $farmId): int
    {
        try {
            return DB::table('tracker_stock_movements')
                ->join('trackers', 'trackers.tracker_id', '=', 'tracker_stock_movements.tracker_id')
                ->where('trackers.farm_id', $farmId)
                ->count();
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
                ->select('farms.farm_id', 'farms.farm_type', 'farms.region')
                ->selectRaw('COUNT(trackers.tracker_id) AS tracker_count')
                ->groupBy('farms.farm_id', 'farms.farm_type', 'farms.region')
                ->orderBy('farms.farm_id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
