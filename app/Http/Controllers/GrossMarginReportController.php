<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CashFlow\GrossMarginQuery;
use App\Services\CashFlow\QueryProfiler;
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

        if ($farm === null) {
            $error = 'No tracker farms found. Run: php artisan duckdb:tracker:seed && php artisan duckdb:stock:seed';
        } else {
            try {
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
