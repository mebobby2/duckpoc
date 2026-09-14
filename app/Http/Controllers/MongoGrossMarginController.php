<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Mongo\GrossMarginV2MongoReport;
use App\Services\Mongo\MongoConnectionFactory;
use App\Services\Mongo\MongoJournalQuery;
use App\Services\Mongo\MongoReportDiagnostics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * The baseline page: the same Gross Margin report, on the topology Figured runs
 * today.
 *
 * Its reason to exist is the timing split. Every other page in this PoC reports
 * one number, because one query does the work. Here there are three — Mongo,
 * MySQL, PHP — and which of them dominates decides whether "replace Mongo" is
 * even the right project.
 */
class MongoGrossMarginController extends Controller
{
    private const string DEFAULT_PERIOD_FROM = '2025-06-01';

    private const string DEFAULT_PERIOD_TO = '2026-05-31';

    private const string DEFAULT_HORIZON = '2026-08-31';

    /** Above this, the extra diagnostic passes are skipped. */
    private const float DIAGNOSTICS_BUDGET_MS = 2_000.0;

    private const int SOURCE_ROW_LIMIT = 200;

    /**
     * Above this many lines in scope, the sample listing is skipped.
     *
     * The breakdown is an aggregate and stays affordable; the sample has to
     * sort by date across everything in scope to return its first 200, which is
     * a different cost entirely.
     */
    private const int SOURCE_LISTING_MAX_ROWS = 5_000_000;

    public function __invoke(
        Request $request,
        GrossMarginV2MongoReport $report,
        MongoJournalQuery $journals,
        MongoReportDiagnostics $diagnostics,
        MongoConnectionFactory $mongo,
    ): View {
        $farms = $this->farms();
        $farmId = (string) $request->query('farm_id', $farms[0]['farm_id'] ?? '');
        $periodFrom = (string) $request->query('period_from', self::DEFAULT_PERIOD_FROM);
        $periodTo = (string) $request->query('period_to', self::DEFAULT_PERIOD_TO);
        $horizon = (string) $request->query('horizon', self::DEFAULT_HORIZON);
        $basis = (string) $request->query('basis', 'cash');

        $farm = collect($farms)->firstWhere('farm_id', $farmId) ?? ($farms[0] ?? null);

        $rows = [];
        $error = null;
        $timings = null;
        $queries = [];
        $linesProcessed = null;
        $summaryMs = null;
        $sourceSummary = ['n' => 0, 'n_groups' => 0, 'n_trackers' => 0, 'net_dollars' => 0.0];
        $lineBreakdown = [];
        $sourceRows = [];
        $sourceRowsSkipped = false;
        $diagnosticsAffordable = true;
        $plan = null;

        if ($farm === null) {
            $error = 'No farm loaded. Run: php artisan mongo:setup --fresh';
        } else {
            try {
                $result = $report->run($farm['farm_id'], $periodFrom, $periodTo, $horizon, $basis);

                $rows = $result['rows'];
                $timings = $result['timings'];
                $queries = $result['queries'];
                $linesProcessed = $result['lines_processed'];
                $accountIds = $result['account_ids'];

                $diagnosticsAffordable = $timings['total_ms'] < self::DIAGNOSTICS_BUDGET_MS
                    || $request->boolean('force_diagnostics');

                if ($diagnosticsAffordable) {
                    $startedAt = microtime(true);

                    $sourceSummary = $diagnostics->summary(
                        $farm['farm_id'], $periodFrom, $periodTo, $horizon, $basis, $accountIds
                    );
                    $lineBreakdown = $diagnostics->lineBreakdown(
                        $farm['farm_id'], $periodFrom, $periodTo, $horizon, $basis, $accountIds
                    );

                    $sourceRowsSkipped = $sourceSummary['n'] > self::SOURCE_LISTING_MAX_ROWS
                        && ! $request->boolean('force_source_rows');

                    if (! $sourceRowsSkipped) {
                        $sourceRows = $diagnostics->sourceRows(
                            $farm['farm_id'], $periodFrom, $periodTo, $horizon, $basis,
                            $accountIds, self::SOURCE_ROW_LIMIT
                        );
                    }

                    $summaryMs = (microtime(true) - $startedAt) * 1000;
                }

                if ($request->boolean('explain')) {
                    $plan = $journals->explain(
                        $farm['farm_id'], $periodFrom, $periodTo, $horizon, $basis, $accountIds
                    );
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $serverMs = defined('LARAVEL_START')
            ? (microtime(true) - LARAVEL_START) * 1000
            : null;

        return view('mongo-gross-margin', [
            'farms' => $farms,
            'farm' => $farm,
            'farmId' => $farm['farm_id'] ?? $farmId,
            'periodFrom' => $periodFrom,
            'periodTo' => $periodTo,
            'horizon' => $horizon,
            'basis' => $basis,
            'tree' => $this->buildTree($rows),
            'months' => $this->monthColumns($rows),
            'error' => $error,
            'timings' => $timings,
            'queries' => $queries,
            'serverMs' => $serverMs,
            'summaryMs' => $summaryMs,
            'linesProcessed' => $linesProcessed,
            'sourceSummary' => $sourceSummary,
            'lineBreakdown' => $lineBreakdown,
            'sourceRows' => $sourceRows,
            'sourceRowsSkipped' => $sourceRowsSkipped,
            'sourceRowLimit' => self::SOURCE_ROW_LIMIT,
            'sourceListingMaxRows' => self::SOURCE_LISTING_MAX_ROWS,
            'diagnosticsAffordable' => $diagnosticsAffordable,
            'diagnosticsBudgetMs' => self::DIAGNOSTICS_BUDGET_MS,
            'plan' => $plan,
            'pipeline' => $farm === null ? null : $this->samplePipeline($journals, $farm['farm_id'], $basis),
            'server' => $this->guarded(fn (): array => $mongo->serverState()),
            'collection' => $this->guarded(fn (): array => $diagnostics->collectionStats()),
            'trackerBreakdown' => $farm === null ? [] : $this->trackerBreakdown($farm['farm_id']),
        ]);
    }

    /**
     * Mongo being down must render the error panel, not a 500.
     *
     * The AlloyDB page learned this the hard way: an unguarded diagnostics call
     * outside the try block took the whole page down whenever the database was
     * stopped, which is exactly when the page is most worth reading.
     *
     * @param  callable(): array<string, mixed>  $probe
     * @return array<string, mixed>
     */
    private function guarded(callable $probe): array
    {
        try {
            return $probe();
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * The pipeline as it is actually sent, for display.
     *
     * Rendered from the same builder the report uses rather than retyped, so it
     * cannot drift from what ran. The account list is elided — on a real chart
     * of accounts it is the longest thing on the page and says nothing.
     */
    private function samplePipeline(MongoJournalQuery $journals, string $farmId, string $basis): string
    {
        $pipeline = $journals->pipeline(
            $farmId, $basis, 'actuals', '2025-06-01', '2026-05-31', ['…in-scope account ids from MySQL…']
        );

        return json_encode($pipeline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function farms(): array
    {
        try {
            return array_map(
                static fn (object $row): array => (array) $row,
                DB::table('farms as f')
                    ->join('trackers as t', 't.farm_id', '=', 'f.farm_id')
                    ->groupBy('f.farm_id', 'f.farm_type', 'f.region')
                    ->orderBy('f.farm_id')
                    ->get(['f.farm_id', 'f.farm_type', 'f.region'])
                    ->all()
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function trackerBreakdown(string $farmId): array
    {
        try {
            return array_map(
                static fn (object $row): array => (array) $row,
                DB::table('trackers')
                    ->where('farm_id', $farmId)
                    ->groupBy('tracker_type')
                    ->orderBy('tracker_type')
                    ->get(['tracker_type', DB::raw('count(*) as n')])
                    ->all()
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
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

            if (! isset($tree[$key])) {
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
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{month: string, basis: string}>
     */
    private function monthColumns(array $rows): array
    {
        $months = [];

        foreach ($rows as $row) {
            $month = (string) $row['month'];

            if (! isset($months[$month])) {
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
