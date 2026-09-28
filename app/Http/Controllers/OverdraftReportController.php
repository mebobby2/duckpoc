<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CashFlow\DataPipelineCheck;
use App\Services\CashFlow\OverdraftOracleSeeder;
use App\Services\CashFlow\PipelineOptions;
use App\Services\CashFlow\OverdraftQuery;
use App\Services\CashFlow\ParquetFileLister;
use App\Services\CashFlow\QueryProfiler;
use App\Services\CashFlow\QueryTrace;
use App\Services\CashFlow\ReportingGroupEntities;
use App\Services\CashFlow\StorageRequestProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Overdraft interest.
 *
 * The report is Figured's `DataPipeline` run as one statement, with the
 * overdraft handler inside it at the virtual-journal merge — see
 * `DataPipelineSqlBuilder`. The page shows the result; what it is doing
 * underneath sits in the collapsed diagnostics, each stage of the pipeline
 * checked against `DataPipelineOracle` for the farm and options selected.
 */
class OverdraftReportController extends Controller
{
    private const string DEFAULT_PERIOD_FROM = '2024-01-01';
    private const string DEFAULT_PERIOD_TO = '2024-12-31';
    private const string DEFAULT_HORIZON = '2026-08-31';

    private const int SOURCE_ROW_LIMIT = 500;

    /** @var list<string> */
    private const array TERMS = [
        'interest_only_monthly',
        'interest_only_bi_monthly',
        'interest_only_quarterly',
        'interest_only_semi_annually',
        'interest_only_annually',
    ];

    public function __invoke(Request $request, DuckDB $db): View
    {
        // max_execution_time counts CPU across every thread on Linux, and DuckDB
        // runs on all sixteen cores: the 600 s limit is ~37 s of wall clock, and
        // its hard kill takes the whole single-process `artisan serve` down.
        set_time_limit(0);

        $alias = config('duckdb.attached_alias');
        $appAlias = config('duckdb.app_database.alias');

        $farms = $this->reportableFarms();
        $farmId = (string) $request->query('farm_id', $farms[0]['farm_id'] ?? OverdraftOracleSeeder::FARM_ID);
        $periodFrom = (string) $request->query('period_from', self::DEFAULT_PERIOD_FROM);
        $periodTo = (string) $request->query('period_to', self::DEFAULT_PERIOD_TO);
        $horizon = (string) $request->query('horizon', self::DEFAULT_HORIZON);

        // The report is Figured's whole DataPipeline as one statement; the
        // gates are query parameters so the pipes can be switched from the
        // options panel. Defaults are a cash flow's.
        $all = $request->boolean('pipeline_all');
        // check=0 runs the report statement once, without the transliteration
        // and the 24 per-stage reruns — which at 500M lines are ~25x the report.
        $check = $request->boolean('check', true);
        $options = new PipelineOptions(
            type: (string) $request->query('pipeline_type', 'actualsForecast'),
            basis: 'cash',
            ytd: $all || $request->boolean('ytd'),
            ytdType: $request->query('ytd_type') ?: null,
            excludeEoyJournals: $all || $request->boolean('exclude_eoy', true),
            includeOpeningBudgetGst: $all || $request->boolean('opening_gst'),
            calculateCurrentYearEarnings: $all || $request->boolean('cye'),
            calculateRetained: $all || $request->boolean('retained'),
            showExpectedSign: $all || $request->boolean('expected_sign'),
            dynamicBankAccount: $all || $request->boolean('dynamic_bank'),
        );
        $entities = ReportingGroupEntities::for($farmId);
        if ($entities !== []) {
            $options = $options->forReportingGroup($entities);
        }

        // The lines behind the report share the pipeline scan's predicate —
        // farm, cash basis, period, horizon — so the Phase 3 query's listing
        // and file scope still describe exactly what the statement read.
        $scope = new OverdraftQuery($db, $alias, $appAlias);

        $pipeline = null;
        $error = null;
        $files = [];
        $storage = [];
        $reportRequests = null;
        $profile = null;
        $trace = null;
        $sourceRows = [];
        $sourceSummary = [];
        $sourceRowsMs = null;

        if ($farms === []) {
            $error = 'No farm has an overdraft or a reporting group. Run: php artisan duckdb:pipeline --seed';
        } else {
            try {
                $requests = new StorageRequestProfile($db);
                $requests->startLogging();

                $tracer = $request->boolean('trace') ? new QueryTrace($db) : null;
                $tracer?->start();

                $pipeline = (new DataPipelineCheck($db, $alias, $appAlias))
                    ->run($farmId, $periodFrom, $periodTo, $horizon, $options, $check);

                $reportRequests = $requests->connectionEvents();
                $trace = $tracer?->collect();

                $startedAt = microtime(true);
                // A reporting group's lines are its children's; the parent has none.
                $sourceSummary = $scope->sourceSummary($farmId, $periodFrom, $periodTo, $horizon, $entities);
                $sourceRows = $scope->sourceRows($farmId, $periodFrom, $periodTo, $horizon, self::SOURCE_ROW_LIMIT, $entities);
                $sourceRowsMs = (microtime(true) - $startedAt) * 1000;

                $files = (new ParquetFileLister($db, $alias))->forQuery(
                    ['farm_id' => $farmId, 'farm_ids' => $entities ?: [$farmId], 'farm_type' => 'dairy', 'region' => $this->regionFor($farms, $farmId)],
                    $periodFrom,
                    $periodTo,
                );
                $storage = $requests->summarise($files);

                if ($request->boolean('explain')) {
                    $profile = (new QueryProfiler($db))->profile($pipeline['sql'], [
                        'farm_id' => $farmId,
                        'period_from' => $periodFrom,
                        'period_to' => $periodTo,
                        'horizon' => $horizon,
                    ]);
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $serverMs = defined('LARAVEL_START') ? (microtime(true) - LARAVEL_START) * 1000 : null;

        return view('overdraft', [
            'farms' => $farms,
            'farmId' => $farmId,
            'periodFrom' => $periodFrom,
            'periodTo' => $periodTo,
            'horizon' => $horizon,
            'pipeline' => $pipeline,
            'options' => $options,
            'pipelineAll' => $all,
            'check' => $check,
            'error' => $error,
            'serverMs' => $serverMs,
            'sql' => $pipeline['sql'] ?? null,
            'config' => $this->overdraftConfig($farmId),
            'terms' => self::TERMS,
            'files' => $files,
            'storage' => $storage,
            'reportRequests' => $reportRequests,
            'profile' => $profile,
            'trace' => $trace,
            'sourceRows' => $sourceRows,
            'sourceSummary' => $sourceSummary,
            'sourceRowsMs' => $sourceRowsMs,
            'sourceRowLimit' => self::SOURCE_ROW_LIMIT,
        ]);
    }

    /**
     * Saves the overdraft settings, then redirects back.
     *
     * A POST rather than more query parameters because it writes. The rate and
     * term are read from the `overdrafts` table by the SQL — deliberately, so
     * the query stays the one Figured's shape implies — which means changing
     * them has to be a write rather than a bind.
     */
    public function save(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'farm_id' => ['required', 'string'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'payment_term' => ['required', 'string', 'in:'.implode(',', self::TERMS)],
        ]);

        DB::table('overdrafts')
            ->where('farm_id', $validated['farm_id'])
            ->update([
                // Stored inflated, as Figured stores it: 5% is 50000.
                'rate' => (int) round(((float) $validated['rate']) * 10000),
                'payment_term' => $validated['payment_term'],
            ]);

        return redirect()->route('overdraft', $request->only(['farm_id', 'period_from', 'period_to', 'horizon']));
    }

    /**
     * @param list<array<string, mixed>> $farms
     */
    private function regionFor(array $farms, string $farmId): string
    {
        foreach ($farms as $farm) {
            if (($farm['farm_id'] ?? null) === $farmId) {
                return (string) ($farm['region'] ?? '');
            }
        }

        return '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * Farms with an overdraft, plus reporting-group parents: a parent has no
     * overdraft of its own, but its children's interest is in its report.
     */
    private function reportableFarms(): array
    {
        try {
            $parents = DB::table('reporting_group_farms as g')
                ->join('farms as f', 'f.farm_id', '=', 'g.parent_farm_id')
                ->select(['g.parent_farm_id as farm_id', 'f.region']);

            return array_map(
                static fn (object $row): array => (array) $row,
                DB::table('overdrafts as o')
                    ->join('farms as f', 'f.farm_id', '=', 'o.farm_id')
                    ->select(['o.farm_id', 'f.region'])
                    ->union($parents)
                    ->orderBy('farm_id')
                    ->get()
                    ->all()
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function overdraftConfig(string $farmId): ?array
    {
        $row = DB::table('overdrafts')
            ->where('farm_id', $farmId)
            ->orderByDesc('start_date')
            ->first();

        return $row === null ? null : (array) $row;
    }
}
