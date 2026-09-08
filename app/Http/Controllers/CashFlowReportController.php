<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CashFlow\CashFlowOracleSeeder;
use App\Services\CashFlow\CashFlowQuery;
use App\Services\CashFlow\ParquetFileLister;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Renders the DuckDB Cash Flow report in a browser, with the report
 * parameters exposed as form inputs.
 *
 * Every request builds its own DuckDB instance (extensions loaded, DuckLake
 * attached) via the container binding, runs one query, and discards it —
 * which is the per-request model this PoC is evaluating, so the elapsed time
 * shown on the page includes that setup cost, not just the query.
 */
class CashFlowReportController extends Controller
{
    /** Source rows shown in the viewer before truncating. */
    private const int SOURCE_ROW_LIMIT = 500;

    public function __invoke(Request $request, DuckDB $db): View
    {
        $alias = config('duckdb.attached_alias');

        $farms = $this->availableFarms();

        $farmId = (string) $request->query('farm_id', CashFlowOracleSeeder::FARM_ID);
        $periodFrom = (string) $request->query('period_from', '2024-01-01');
        $periodTo = (string) $request->query('period_to', '2024-12-31');
        $horizon = (string) $request->query('horizon', '2024-02-28');
        $basis = (string) $request->query('basis', 'cash');

        $farm = collect($farms)->firstWhere('farm_id', $farmId) ?? ($farms[0] ?? null);

        $rows = [];
        $sql = null;
        $error = null;
        $elapsedMs = null;

        $query = new CashFlowQuery($db, $alias);

        $sourceRows = [];
        $sourceSummary = ['n' => 0, 'net_dollars' => 0.0];
        $files = [];

        if ($farm === null) {
            $error = 'No farms found in DuckLake. Run: php artisan duckdb:cashflow:seed';
        } else {
            $sql = $query->sql();

            try {
                $startedAt = microtime(true);

                $rows = $query->run(
                    farmId: $farm['farm_id'],
                    farmType: $farm['farm_type'],
                    region: $farm['region'],
                    periodFrom: $periodFrom,
                    periodTo: $periodTo,
                    horizon: $horizon,
                    basis: $basis,
                );

                $elapsedMs = (microtime(true) - $startedAt) * 1000;

                $sourceSummary = $query->sourceRowSummary(
                    farmId: $farm['farm_id'],
                    farmType: $farm['farm_type'],
                    region: $farm['region'],
                    periodFrom: $periodFrom,
                    periodTo: $periodTo,
                    horizon: $horizon,
                    basis: $basis,
                );

                $sourceRows = $query->sourceRows(
                    farmId: $farm['farm_id'],
                    farmType: $farm['farm_type'],
                    region: $farm['region'],
                    periodFrom: $periodFrom,
                    periodTo: $periodTo,
                    horizon: $horizon,
                    basis: $basis,
                    limit: self::SOURCE_ROW_LIMIT,
                );

                $files = (new ParquetFileLister($db, $alias))
                    ->forQuery($farm, $periodFrom, $periodTo);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return view('cashflow', [
            'farms' => $farms,
            'farm' => $farm,
            'farmId' => $farm['farm_id'] ?? $farmId,
            'periodFrom' => $periodFrom,
            'periodTo' => $periodTo,
            'horizon' => $horizon,
            'basis' => $basis,
            'rows' => $rows,
            'reportRows' => $query->definition()->displayRows(),
            'sql' => $sql,
            'error' => $error,
            'elapsedMs' => $elapsedMs,
            'sourceRows' => $sourceRows,
            'sourceSummary' => $sourceSummary,
            'sourceRowLimit' => self::SOURCE_ROW_LIMIT,
            'files' => $files,
        ]);
    }

    /**
     * Straight out of MySQL via Eloquent — no reason to route a dimension
     * lookup through DuckDB. This was previously a lake read costing ~1s from
     * a remote bucket; as a MySQL query it is sub-millisecond.
     *
     * @return list<array<string, mixed>>
     */
    private function availableFarms(): array
    {
        try {
            return DB::table('farms')
                ->select('farm_id', 'farm_type', 'region', 'opening_balance')
                ->orderBy('farm_id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        } catch (Throwable) {
            // Migrations not run yet — the view surfaces this as guidance.
            return [];
        }
    }

}
