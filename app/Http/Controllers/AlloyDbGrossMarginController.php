<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AlloyDb\AlloyDbQueryProfile;
use App\Services\AlloyDb\GrossMarginV2PgQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class AlloyDbGrossMarginController extends Controller
{
    private const string DEFAULT_PERIOD_FROM = '2025-06-01';
    private const string DEFAULT_PERIOD_TO = '2026-05-31';
    private const string DEFAULT_HORIZON = '2026-08-31';

    /**
     * Above this, the row-count diagnostic is skipped.
     *
     * It is a second full aggregate over the same rows, and on the DuckDB side
     * an unguarded version of it grew to 40% of page time. Gating on the
     * report's own elapsed time is free; gating on a row count would mean
     * paying for a scan to decide whether a scan is affordable.
     */
    private const float DIAGNOSTICS_BUDGET_MS = 2_000.0;

    /** Sample journal lines shown when the listing is affordable. */
    private const int SOURCE_ROW_LIMIT = 200;

    /**
     * Above this many lines in scope, the sample listing is skipped.
     *
     * The breakdown is an aggregate and stays affordable at any volume; the
     * sample has to sort by date across everything in scope to return its first
     * 200, which is a different cost entirely.
     */
    private const int SOURCE_LISTING_MAX_ROWS = 5_000_000;

    public function __invoke(Request $request): View
    {
        $db = DB::connection('alloydb');

        $farms = $this->farms($db);
        $farmId = (string) $request->query('farm_id', $farms[0]['farm_id'] ?? '');
        $periodFrom = (string) $request->query('period_from', self::DEFAULT_PERIOD_FROM);
        $periodTo = (string) $request->query('period_to', self::DEFAULT_PERIOD_TO);
        $horizon = (string) $request->query('horizon', self::DEFAULT_HORIZON);
        $basis = (string) $request->query('basis', 'cash');

        $farm = collect($farms)->firstWhere('farm_id', $farmId) ?? ($farms[0] ?? null);

        $query = new GrossMarginV2PgQuery($db);
        $profiler = new AlloyDbQueryProfile($db);

        $rows = [];
        $error = null;
        $elapsedMs = null;
        $summaryMs = null;
        $sourceSummary = ['n' => 0, 'n_groups' => 0, 'n_trackers' => 0, 'net_dollars' => 0.0];
        $plan = null;
        $diagnosticsAffordable = true;
        $lineBreakdown = [];
        $linesProcessed = null;
        $sourceRows = [];
        $sourceRowsSkipped = false;

        if ($farm === null) {
            $error = 'No farm loaded. Run: php artisan alloydb:setup --fresh --columnar';
        } else {
            try {
                $startedAt = microtime(true);
                $rows = $query->run($farm['farm_id'], $periodFrom, $periodTo, $horizon, $basis);
                $elapsedMs = (microtime(true) - $startedAt) * 1000;

                // Carried out of the report itself, so it is available at any
                // volume — unlike the breakdown below, which is a second scan.
                $linesProcessed = isset($rows[0]['lines_processed'])
                    ? (int) $rows[0]['lines_processed']
                    : null;

                $diagnosticsAffordable = $elapsedMs < self::DIAGNOSTICS_BUDGET_MS
                    || $request->boolean('force_diagnostics');

                if ($diagnosticsAffordable) {
                    $startedAt = microtime(true);
                    $sourceSummary = $query->sourceRowSummary($farm['farm_id'], $periodFrom, $periodTo, $horizon, $basis);
                    $lineBreakdown = $query->lineBreakdown($farm['farm_id'], $periodFrom, $periodTo, $horizon, $basis);

                    $sourceRowsSkipped = $sourceSummary['n'] > self::SOURCE_LISTING_MAX_ROWS
                        && !$request->boolean('force_source_rows');

                    if (!$sourceRowsSkipped) {
                        $sourceRows = $query->sourceRows(
                            $farm['farm_id'], $periodFrom, $periodTo, $horizon, $basis, self::SOURCE_ROW_LIMIT
                        );
                    }

                    $summaryMs = (microtime(true) - $startedAt) * 1000;
                }

                if ($request->boolean('explain')) {
                    $plan = $profiler->explain($query->sql(), [
                        'farm_id' => $farm['farm_id'],
                        'farm_id2' => $farm['farm_id'],
                        'farm_id3' => $farm['farm_id'],
                        'basis' => $basis,
                        'period_from' => $periodFrom,
                        'period_from2' => $periodFrom,
                        'period_to' => $periodTo,
                        'period_to2' => $periodTo,
                        'horizon' => $horizon,
                        'horizon2' => $horizon,
                        'horizon3' => $horizon,
                    ]);
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $serverMs = defined('LARAVEL_START')
            ? (microtime(true) - LARAVEL_START) * 1000
            : null;

        return view('alloydb-gross-margin', [
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
            'serverMs' => $serverMs,
            'summaryMs' => $summaryMs,
            'sourceSummary' => $sourceSummary,
            'diagnosticsAffordable' => $diagnosticsAffordable,
            'diagnosticsBudgetMs' => self::DIAGNOSTICS_BUDGET_MS,
            'plan' => $plan,
            'linesProcessed' => $linesProcessed,
            'lineBreakdown' => $lineBreakdown,
            'sourceRows' => $sourceRows,
            'sourceRowsSkipped' => $sourceRowsSkipped,
            'sourceRowLimit' => self::SOURCE_ROW_LIMIT,
            'sourceListingMaxRows' => self::SOURCE_LISTING_MAX_ROWS,
            'columnar' => $profiler->columnarState(),
            'trackerBreakdown' => $farm === null ? [] : $this->trackerBreakdown($db, $farm['farm_id']),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function farms(object $db): array
    {
        try {
            return array_map(
                static fn (object $row): array => (array) $row,
                $db->select(
                    'SELECT f.farm_id, f.farm_type, f.region
                     FROM farms f
                     JOIN trackers t ON t.farm_id = f.farm_id
                     GROUP BY f.farm_id, f.farm_type, f.region
                     ORDER BY f.farm_id'
                )
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function trackerBreakdown(object $db, string $farmId): array
    {
        try {
            return array_map(
                static fn (object $row): array => (array) $row,
                $db->select(
                    'SELECT tracker_type, count(*) AS n
                     FROM trackers WHERE farm_id = ?
                     GROUP BY tracker_type ORDER BY tracker_type',
                    [$farmId]
                )
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
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
                (string) ($row['level'] ?? ''),
            ]);

            if (!isset($tree[$key])) {
                $tree[$key] = [
                    'label' => $row['account_name'] ?? $row['label'] ?? '',
                    'level' => (int) ($row['level'] ?? 0),
                    'report_group' => $row['report_group'] ?? null,
                    'months' => [],
                ];
            }

            $tree[$key]['months'][(string) $row['month']] = [
                'amount' => $row['amount'] === null ? null : (float) $row['amount'],
                'per_unit' => $row['per_unit'] === null ? null : (float) $row['per_unit'],
                'kg_ms' => $row['kg_ms'] === null ? null : (float) $row['kg_ms'],
                'head' => $row['head'] === null ? null : (float) $row['head'],
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
            $month = (string) $row['month'];

            if (!isset($months[$month])) {
                $months[$month] = [
                    'month' => $month,
                    'basis' => (string) ($row['column_basis'] ?? ''),
                ];
            }
        }

        ksort($months);

        return array_values($months);
    }
}
