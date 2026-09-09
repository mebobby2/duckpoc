<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CashFlow\GrossMarginV2Query;
use App\Services\CashFlow\GrossMarginV2Seeder;
use App\Services\CashFlow\ParquetFileLister;
use App\Services\CashFlow\QueryProfiler;
use App\Services\CashFlow\QueryTrace;
use App\Services\CashFlow\StorageRequestProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Gross Margin V2 — the hierarchical, mixed-enterprise report.
 *
 * V1 produced a flat table per tracker for one tracker type. This is the shape
 * of the real report: sections nested under Income and Direct Costs, mixing
 * milk and livestock enterprises, with a Gross Margin line and Actual/Forecast
 * labelling per column.
 */
class GrossMarginV2ReportController extends Controller
{
    /** Spans the actuals/forecast boundary the seeder creates. */
    private const string DEFAULT_PERIOD_FROM = '2026-06-01';
    private const string DEFAULT_PERIOD_TO = '2027-05-31';

    private const int SOURCE_ROW_LIMIT = 500;
    private const float DIAGNOSTICS_BUDGET_MS = 30000.0;

    public function __invoke(Request $request, DuckDB $db): View
    {
        $alias = config('duckdb.attached_alias');

        $farms = $this->mixedEnterpriseFarms();

        $farmId = (string) $request->query('farm_id', GrossMarginV2Seeder::FARM_ID);
        $periodFrom = (string) $request->query('period_from', self::DEFAULT_PERIOD_FROM);
        $periodTo = (string) $request->query('period_to', self::DEFAULT_PERIOD_TO);
        $horizon = (string) $request->query('horizon', GrossMarginV2Seeder::HORIZON_DATE);
        $basis = (string) $request->query('basis', 'cash');

        $farm = collect($farms)->firstWhere('farm_id', $farmId) ?? ($farms[0] ?? null);

        $query = new GrossMarginV2Query($db, $alias);

        $rows = [];
        $error = null;
        $elapsedMs = null;
        $profile = null;
        $files = [];
        $storage = null;
        $sourceRows = [];
        $sourceSummary = ['n' => 0, 'n_groups' => 0, 'n_trackers' => 0, 'net_dollars' => 0.0];
        $summaryMs = null;
        $sourceRowsMs = null;
        $diagnosticsAffordable = true;
        $trace = null;

        if ($farm === null) {
            $error = 'No mixed-enterprise farm found. Run: php artisan duckdb:gm2:seed';
        } else {
            try {
                $requests = new StorageRequestProfile($db);
                $requests->startLogging();

                // Armed before the query, read after it — DuckDB's log is
                // per-process, so this is the only point at which a browser
                // request's own trace can be captured.
                $tracer = $request->boolean('trace') ? new QueryTrace($db) : null;
                $tracer?->start();

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

                // Collected immediately, before the diagnostic queries below
                // add their own events to the log.
                $trace = $tracer?->collect();

                $scope = [
                    $farm['farm_id'],
                    $farm['farm_type'],
                    $farm['region'],
                    $periodFrom,
                    $periodTo,
                    $horizon,
                    $basis,
                ];

                // Gated on the report's own elapsed time, which is free —
                // gating on a row count would mean paying for a full scan to
                // decide whether a full scan is affordable.
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

        return view('gross-margin-v2', [
            'farms' => $farms,
            'farm' => $farm,
            'farmId' => $farm['farm_id'] ?? $farmId,
            'periodFrom' => $periodFrom,
            'periodTo' => $periodTo,
            'horizon' => $horizon,
            'basis' => $basis,
            'tree' => $this->buildTree($rows),
            'months' => $this->monthColumns($rows),
            'sql' => $error === null ? $query->sql() : null,
            'error' => $error,
            'elapsedMs' => $elapsedMs,
            'profile' => $profile,
            'files' => $files,
            'storage' => $storage,
            'trackerBreakdown' => $farm === null ? [] : $this->trackerBreakdown($farm['farm_id']),
            'sourceRowLimit' => self::SOURCE_ROW_LIMIT,
            'diagnosticsBudgetMs' => self::DIAGNOSTICS_BUDGET_MS,
            'sourceRows' => $sourceRows,
            'sourceSummary' => $sourceSummary,
            'summaryMs' => $summaryMs,
            'sourceRowsMs' => $sourceRowsMs,
            'diagnosticsAffordable' => $diagnosticsAffordable,
            'trace' => $trace,
        ]);
    }

    /**
     * Collapses the long result into one entry per report row, holding its
     * months — the query already emitted them in display order, so this
     * preserves that order rather than re-deriving it.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function buildTree(array $rows): array
    {
        $tree = [];

        foreach ($rows as $row) {
            $key = implode('|', [
                (string) ($row['report_section'] ?? ''),
                (string) ($row['report_group'] ?? ''),
                (string) ($row['account_id'] ?? ''),
                (string) $row['level'],
            ]);

            $tree[$key] ??= [
                'label' => (string) ($row['account_name'] ?? $row['label']),
                'level' => (int) (string) $row['level'],
                'section' => (string) ($row['report_section'] ?? ''),
                'months' => [],
            ];

            $tree[$key]['months'][(string) $row['month']] = [
                'amount' => (float) (string) $row['amount'],
                'per_unit' => $row['per_unit'] === null ? null : (float) (string) $row['per_unit'],
            ];
        }

        return array_values($tree);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{month: string, basis: string}>
     */
    private function monthColumns(array $rows): array
    {
        $months = [];

        foreach ($rows as $row) {
            $months[(string) $row['month']] = (string) $row['column_basis'];
        }

        ksort($months);

        $columns = [];
        foreach ($months as $month => $basis) {
            $columns[] = ['month' => $month, 'basis' => $basis];
        }

        return $columns;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function trackerBreakdown(string $farmId): array
    {
        try {
            return DB::table('trackers')
                ->where('farm_id', $farmId)
                ->select('tracker_type')
                ->selectRaw('COUNT(*) AS n')
                ->groupBy('tracker_type')
                ->orderBy('tracker_type')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Farms with accounts carrying a report group — i.e. those shaped for this
     * report. The other farms in the lake have no hierarchy to render.
     *
     * @return list<array<string, mixed>>
     */
    private function mixedEnterpriseFarms(): array
    {
        try {
            return DB::table('farms')
                ->join('trackers', 'trackers.farm_id', '=', 'farms.farm_id')
                ->whereIn('farms.farm_id', [GrossMarginV2Seeder::FARM_ID, GrossMarginV2Seeder::BULK_FARM_ID, GrossMarginV2Seeder::HUGE_FARM_ID])
                ->select('farms.farm_id', 'farms.farm_type', 'farms.region')
                ->groupBy('farms.farm_id', 'farms.farm_type', 'farms.region')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
