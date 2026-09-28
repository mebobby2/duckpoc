<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AlloyDb\CashFlowActualsForecastPgQuery;
use App\Services\CashFlow\CashFlowActualsForecastFarmSeeder;
use App\Services\CashFlow\CashFlowActualsForecastOptions;
use App\Services\CashFlow\CashFlowActualsForecastQuery;
use App\Services\CashFlow\ParquetFileLister;
use App\Services\CashFlow\QueryProfiler;
use App\Services\CashFlow\ReportPeriod;
use App\Services\CashFlow\StorageRequestProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Figured's Cash Flow, actuals plus forecast, as one DuckDB statement — the
 * request `/reports/data/cash_flow?type=actualsForecast&period=…` with its
 * options exposed as form inputs under their Figured names.
 *
 * The period is a `from` and `to` date rather than Figured's financial
 * year, so a request can span several years and scan a whole farm. With
 * neither given it is the farm's FY2027, the year Figured's request asks
 * for.
 */
class CashFlowActualsForecastReportController extends Controller
{
    private const int ROW_LIMIT = 300;

    public const string ENGINE_DUCKDB = 'duckdb';
    public const string ENGINE_ALLOYDB = 'alloydb';

    /**
     * One page for both engines, told apart by the route. The lake's
     * connection is resolved only on the lake's route: resolving it attaches
     * DuckLake, which cannot happen inside the AlloyDB stack.
     */
    public function __invoke(Request $request, string $engine = self::ENGINE_DUCKDB): View
    {
        $onLake = $engine !== self::ENGINE_ALLOYDB;
        $db = $onLake ? app(DuckDB::class) : null;
        $alias = config('duckdb.attached_alias');
        $appAlias = config('duckdb.app_database.alias');

        $farms = $this->farms();
        $farmId = (string) $request->query('farm_id', CashFlowActualsForecastFarmSeeder::FARM_ID);
        $farm = collect($farms)->firstWhere('farm_id', $farmId) ?? ($farms[0] ?? null);
        $farmId = $farm['farm_id'] ?? $farmId;

        $defaultPeriod = ReportPeriod::financialYear(
            CashFlowActualsForecastFarmSeeder::PERIOD_YEAR,
            (int) ($farm['financial_year_end_month'] ?? 12),
        );
        $from = (string) $request->query('from', $defaultPeriod->from);
        $to = (string) $request->query('to', $defaultPeriod->to);
        $horizon = (string) $request->query('actuals_horizon', CashFlowActualsForecastFarmSeeder::HORIZON);

        $options = new CashFlowActualsForecastOptions(
            groupByTracker: $request->query('group_by', 'tracker') === 'tracker',
            excludeEoyJournals: $request->boolean('exclude_eoy_journals', true),
            withTotal: $request->boolean('with_total', true),
            withOverdraft: $request->boolean('with_overdraft', false),
        );

        $query = $onLake
            ? new CashFlowActualsForecastQuery($db, $alias, $appAlias, $options)
            : new CashFlowActualsForecastPgQuery(DB::connection('alloydb'), $options);

        $rows = [];
        $error = null;
        $elapsedMs = null;
        $reportRequests = null;
        $virtualJournals = [];
        $virtualJournalSummary = [];
        $sourceRows = [];
        $sourceSummary = ['n' => 0, 'n_tracker_tagged' => 0, 'net_dollars' => 0.0, 'period_from' => '', 'period_to' => ''];
        $files = [];
        $storage = [];
        $profile = null;

        $period = null;

        try {
            $period = ReportPeriod::between($from, $to);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }

        if ($farm === null) {
            $error = 'No farm seeded. Run: php artisan duckdb:cashflow-af:seed';
        } elseif ($period !== null) {
            try {
                $requests = $onLake ? new StorageRequestProfile($db) : null;
                $requests?->startLogging();

                $startedAt = hrtime(true);
                $rows = $query->run($farmId, $period, $horizon);
                $elapsedMs = (hrtime(true) - $startedAt) / 1e6;
                $reportRequests = $requests?->connectionEvents();

                $virtualJournalSummary = $query->virtualJournalSummary($farmId, $period, $horizon);
                $virtualJournals = $query->virtualJournals($farmId, $period, $horizon, self::ROW_LIMIT);
                $sourceSummary = $query->sourceRowSummary($farmId, $period, $horizon);
                $sourceRows = $query->sourceRows($farmId, $period, $horizon, self::ROW_LIMIT);

                if ($onLake && $sourceSummary['period_from'] !== '') {
                    $files = (new ParquetFileLister($db, $alias))->forQuery($farm, $sourceSummary['period_from'], $sourceSummary['period_to']);
                    $storage = $requests->summarise($files);
                }

                if ($onLake && $request->boolean('explain')) {
                    $profile = (new QueryProfiler($db))->profile($query->sql(), [
                        'farm_id' => $farmId,
                        'period_from' => $period->from,
                        'period_to' => $period->to,
                        'horizon' => $horizon,
                        'basis' => $options->basis,
                    ]);
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        [$farmRows, $trackerBlocks] = $this->split($rows);

        return view('cashflow-actuals-forecast', [
            'engineLabel' => $onLake ? 'one DuckDB statement over DuckLake on MinIO' : 'one PostgreSQL statement on AlloyDB Omni, facts and dimensions in one database',
            'farms' => $farms,
            'farm' => $farm,
            'farmId' => $farmId,
            'from' => $from,
            'to' => $to,
            'horizon' => $horizon,
            'options' => $options,
            'farmRows' => $farmRows,
            'trackerBlocks' => $trackerBlocks,
            'displayRows' => $query->definition()->displayRows($options->withOverdraft),
            'trackerDisplayRows' => $query->definition()->trackerDisplayRows(),
            'sql' => $query->sql(),
            'error' => $error,
            'elapsedMs' => $elapsedMs,
            'reportRequests' => $reportRequests,
            'serverMs' => defined('LARAVEL_START') ? (microtime(true) - LARAVEL_START) * 1000 : null,
            'virtualJournals' => $virtualJournals,
            'virtualJournalSummary' => $virtualJournalSummary,
            'sourceRows' => $sourceRows,
            'sourceSummary' => $sourceSummary,
            'rowLimit' => self::ROW_LIMIT,
            'files' => $files,
            'storage' => $storage,
            'profile' => $profile,
        ]);
    }

    /**
     * The long result into the consolidated rows and one block per tracker,
     * each block in column order.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{0: list<array<string, mixed>>, 1: list<array{tracker_id: string, tracker_name: string, tracker_type: string, columns: list<array<string, mixed>>}>}
     */
    private function split(array $rows): array
    {
        $farm = [];
        $blocks = [];

        foreach ($rows as $row) {
            if ($row['scope'] === 'farm') {
                $farm[] = $row;
                continue;
            }

            $id = (string) $row['scope'];
            $blocks[$id] ??= [
                'tracker_id' => $id,
                'tracker_name' => (string) $row['tracker_name'],
                'tracker_type' => (string) $row['tracker_type'],
                'columns' => [],
            ];
            $blocks[$id]['columns'][] = $row;
        }

        return [$farm, array_values($blocks)];
    }

    /**
     * Farms with a balance date — the ones this report can resolve a
     * financial year for.
     *
     * @return list<array<string, mixed>>
     */
    private function farms(): array
    {
        try {
            return DB::table('farms')
                ->select('farm_id', 'farm_type', 'region', 'financial_year_end_month')
                ->where('farm_id', 'like', 'cfaf-%')
                ->orderBy('farm_id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
