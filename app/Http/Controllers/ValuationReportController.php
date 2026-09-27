<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AlloyDb\ValuationMovementPgSqlBuilder;
use App\Services\CashFlow\QueryProfiler;
use App\Services\CashFlow\QueryTrace;
use App\Services\CashFlow\ValuationMovementOracle;
use App\Services\CashFlow\ValuationMovementQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Livestock valuation movement — Phase 2.
 *
 * The page carries the same diagnostics as the other reports, minus one: there
 * is no Parquet section, because this report reads no Parquet. Every table it
 * touches — trackers, stock movements, valuation rates — is relational
 * dimension data reached through the MySQL connector, and that absence is the
 * phase's main finding rather than a gap in the page. It is stated on the page
 * for the same reason.
 *
 * In its place is a cross-engine panel, because portability rather than
 * latency is what this phase turned out to be worth: the same statement is run
 * on DuckDB and on AlloyDB and the rows are compared cell by cell, live.
 */
class ValuationReportController extends Controller
{
    private const string DEFAULT_PERIOD_FROM = '2025-01-01';
    private const string DEFAULT_PERIOD_TO = '2025-12-31';
    private const string DEFAULT_HORIZON = '2025-06-30';

    private const int SOURCE_ROW_LIMIT = 500;

    public function __invoke(Request $request, DuckDB $db): View
    {
        $appAlias = config('duckdb.app_database.alias');

        $farms = $this->farmsWithTrackers();
        $farmId = (string) $request->query('farm_id', $farms[0]['farm_id'] ?? '');
        $periodFrom = (string) $request->query('period_from', self::DEFAULT_PERIOD_FROM);
        $periodTo = (string) $request->query('period_to', self::DEFAULT_PERIOD_TO);
        $horizon = (string) $request->query('horizon', self::DEFAULT_HORIZON);

        $query = new ValuationMovementQuery($db, $appAlias);

        $rows = [];
        $totals = [];
        $error = null;
        $elapsedMs = null;
        $profile = null;
        $trace = null;
        $sourceRows = [];
        $sourceSummary = [];
        $sourceRowsMs = null;
        $oracle = ['checked' => 0, 'mismatched' => 0, 'ms' => null];
        $crossEngine = null;

        if ($farms === []) {
            $error = 'No farm has trackers. Run: php artisan duckdb:tracker:seed';
        } else {
            try {
                $tracer = $request->boolean('trace') ? new QueryTrace($db) : null;
                $tracer?->start();

                $startedAt = microtime(true);
                $rows = $query->run($farmId, $periodFrom, $periodTo, $horizon);
                $elapsedMs = (microtime(true) - $startedAt) * 1000;

                $trace = $tracer?->collect();

                $totals = $query->totals($farmId, $periodFrom, $periodTo, $horizon);

                $startedAt = microtime(true);
                $sourceSummary = $query->sourceSummary($farmId, $periodFrom, $periodTo, $horizon);
                $sourceRows = $query->sourceRows($farmId, $periodFrom, $periodTo, $horizon, self::SOURCE_ROW_LIMIT);
                $sourceRowsMs = (microtime(true) - $startedAt) * 1000;

                $oracle = $this->checkOracle($rows, $farmId, $periodFrom, $periodTo, $horizon);
                $crossEngine = $this->checkAlloyDb($rows, $farmId, $periodFrom, $periodTo, $horizon);

                if ($request->boolean('explain')) {
                    $profile = (new QueryProfiler($db))->profile($query->sql($farmId), [
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

        return view('valuation', [
            'farms' => $farms,
            'farmId' => $farmId,
            'periodFrom' => $periodFrom,
            'periodTo' => $periodTo,
            'horizon' => $horizon,
            'rows' => $rows,
            'totals' => $totals,
            'error' => $error,
            'elapsedMs' => $elapsedMs,
            'serverMs' => $serverMs,
            'sql' => $error === null && $farmId !== '' ? $query->sql($farmId) : null,
            'pgSql' => (new ValuationMovementPgSqlBuilder())->buildSql(),
            'profile' => $profile,
            'trace' => $trace,
            'sourceRows' => $sourceRows,
            'sourceSummary' => $sourceSummary,
            'sourceRowsMs' => $sourceRowsMs,
            'sourceRowLimit' => self::SOURCE_ROW_LIMIT,
            'oracle' => $oracle,
            'crossEngine' => $crossEngine,
            'conservation' => $this->conservation($totals),
        ]);
    }

    /**
     * The statement against Figured's interval loop, run live.
     *
     * On the page rather than only in the command because a report claiming
     * parity should be able to show it for whatever the viewer just selected,
     * not only for the shape someone once ran on the console.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{checked: int, mismatched: int, ms: null|float}
     */
    private function checkOracle(array $rows, string $farmId, string $from, string $to, string $horizon): array
    {
        $startedAt = microtime(true);
        $expected = (new ValuationMovementOracle())->run($farmId, $from, $to, $horizon);
        $ms = (microtime(true) - $startedAt) * 1000;

        $byKey = [];
        foreach ($expected as $row) {
            $byKey[$row['month'].'|'.$row['tracker_id']] = $row;
        }

        $mismatched = 0;

        foreach ($rows as $row) {
            $want = $byKey[(string) $row['month'].'|'.(string) $row['tracker_id']] ?? null;

            if ($want === null
                || (int) (string) $row['closing_head'] !== $want['closing_head']
                || (int) round(((float) (string) $row['movement_dollars']) * 10000) !== $want['movement']
            ) {
                $mismatched++;
            }
        }

        return ['checked' => count($rows), 'mismatched' => $mismatched, 'ms' => $ms];
    }

    /**
     * The same logic on AlloyDB, compared cell by cell.
     *
     * Returns null when AlloyDB is unreachable or has not been given this
     * farm's stock data — which is the common case, since only a few farms
     * were copied across. A missing comparison is reported as missing rather
     * than as a pass.
     *
     * @param list<array<string, mixed>> $rows
     * @return null|array{rows: int, matched: int, ms: float, sum_duck: float, sum_pg: float}
     */
    private function checkAlloyDb(array $rows, string $farmId, string $from, string $to, string $horizon): ?array
    {
        try {
            $sql = str_replace(
                [':period_from', ':period_to', ':farm_id', ':horizon'],
                ["'{$from}'", "'{$to}'", "'".str_replace("'", "''", $farmId)."'", "'{$horizon}'"],
                (new ValuationMovementPgSqlBuilder())->buildSql(),
            );

            $startedAt = microtime(true);
            $pg = DB::connection('alloydb')->select($sql);
            $ms = (microtime(true) - $startedAt) * 1000;

            if ($pg === []) {
                return null;
            }

            $byKey = [];
            foreach ($pg as $row) {
                $byKey[$row->month.'|'.$row->tracker_id] = [
                    (int) $row->closing_head,
                    (int) round(((float) $row->movement_dollars) * 10000),
                ];
            }

            $matched = 0;

            foreach ($rows as $row) {
                $key = (string) $row['month'].'|'.(string) $row['tracker_id'];
                $mine = [
                    (int) (string) $row['closing_head'],
                    (int) round(((float) (string) $row['movement_dollars']) * 10000),
                ];

                if (($byKey[$key] ?? null) === $mine) {
                    $matched++;
                }
            }

            return [
                'rows' => count($pg),
                'matched' => $matched,
                'ms' => $ms,
                'sum_duck' => array_sum(array_map(static fn (array $r): float => (float) (string) $r['movement_dollars'], $rows)),
                'sum_pg' => array_sum(array_map(static fn (object $r): float => (float) $r->movement_dollars, $pg)),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Movements must telescope to closing minus opening.
     *
     * @param list<array<string, mixed>> $totals
     * @return null|array{opening: float, closing: float, summed: float, drift: float}
     */
    private function conservation(array $totals): ?array
    {
        if (count($totals) < 2) {
            return null;
        }

        $first = $totals[0];
        $last = $totals[count($totals) - 1];

        $summed = 0.0;
        foreach ($totals as $row) {
            $summed += (float) (string) $row['movement_dollars'];
        }

        $opening = ((float) (string) $first['closing_value_dollars']) - ((float) (string) $first['movement_dollars']);
        $closing = (float) (string) $last['closing_value_dollars'];

        return [
            'opening' => $opening,
            'closing' => $closing,
            'summed' => $summed,
            'drift' => abs($summed - ($closing - $opening)),
        ];
    }

    /**
     * @return list<array{farm_id: string, trackers: int}>
     */
    private function farmsWithTrackers(): array
    {
        return DB::table('trackers')
            ->join('valuation_rates', 'valuation_rates.tracker_id', '=', 'trackers.tracker_id')
            ->groupBy('trackers.farm_id')
            ->orderBy('trackers.farm_id')
            ->get(['trackers.farm_id', DB::raw('COUNT(DISTINCT trackers.tracker_id) AS trackers')])
            ->map(static fn (object $row): array => [
                'farm_id' => (string) $row->farm_id,
                'trackers' => (int) $row->trackers,
            ])
            ->all();
    }
}
