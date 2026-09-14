<?php

declare(strict_types=1);

namespace App\Services\Mongo;

use Illuminate\Support\Facades\DB;

/**
 * Gross Margin V2 assembled the way Figured assembles it: Mongo returns grouped
 * sums, MySQL returns the dimensions, and PHP does everything in between.
 *
 * This class is the baseline's entire point. `GrossMarginV2SqlBuilder` and
 * `GrossMarginV2PgSqlBuilder` express the same report as one statement, because
 * their facts and dimensions live somewhere a single query can reach. Here they
 * do not, so the work reappears here as loops:
 *
 * | Step                       | DuckDB / AlloyDB            | Here                 |
 * |----------------------------|-----------------------------|----------------------|
 * | Attach account names       | `JOIN account_scope`        | `classify()`         |
 * | Account/group/section rolls| `GROUPING SETS`             | `rollUp()`           |
 * | Gross margin               | derived CTE                 | `marginRows()`       |
 * | Running stock balance      | `SUM(...) OVER (...)`       | `stockUnits()`       |
 * | Per-unit margin            | projection                  | `emit()`             |
 *
 * None of these is hard. The interesting quantity is what they COST once the
 * inputs stop being small, and the three timings this returns — Mongo, MySQL,
 * PHP — are what the comparison rests on. If PHP dominates, then the engine
 * underneath was never the ceiling.
 *
 * Output shape is identical to the SQL versions, column for column, so the same
 * Blade view renders all three and the numbers can be diffed directly.
 */
final class GrossMarginV2MongoReport
{
    private const int FIXED_POINT = 10_000;

    public function __construct(
        private readonly MongoJournalQuery $journals,
    ) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     lines_processed: int,
     *     queries: list<array{bucket: string, ms: float, groups: int}>,
     *     timings: array{mongo_ms: float, mysql_ms: float, php_ms: float, total_ms: float},
     *     account_ids: list<string>
     * }
     */
    public function run(
        string $farmId,
        string $periodFrom,
        string $periodTo,
        string $horizon,
        string $basis,
    ): array {
        $startedTotal = microtime(true);
        $mysqlMs = 0.0;

        // MySQL first, and not only for the names. The account list has to be
        // marshalled into Mongo's $match, so the dimension read is not a
        // decoration on the fact query — it is a prerequisite of it. Two
        // round trips to two databases before a single journal line is touched.
        $started = microtime(true);
        $accounts = $this->accounts();
        $mysqlMs += (microtime(true) - $started) * 1000;

        $accountIds = array_keys($accounts);

        $fetched = $this->journals->totalsByAccountMonth(
            $farmId, $periodFrom, $periodTo, $horizon, $basis, $accountIds
        );

        $started = microtime(true);
        $milk = $this->milkUnits($farmId);
        $movements = $this->stockMovements($farmId);
        $mysqlMs += (microtime(true) - $started) * 1000;

        $startedPhp = microtime(true);

        $months = $this->monthSpine($periodFrom, $periodTo, $horizon);
        $classified = $this->classify($fetched['totals'], $accounts);
        $levels = $this->rollUp($classified);
        $rowsOut = array_merge($this->levelRows($levels), $this->marginRows($levels));
        $stock = $this->stockUnits($movements);

        $linesProcessed = 0;

        foreach ($fetched['totals'] as $total) {
            $linesProcessed += $total['line_count'];
        }

        $rows = $this->emit($rowsOut, $months, $milk, $stock, $linesProcessed);

        $phpMs = (microtime(true) - $startedPhp) * 1000;

        return [
            'rows' => $rows,
            'lines_processed' => $linesProcessed,
            'queries' => $fetched['queries'],
            'timings' => [
                'mongo_ms' => $fetched['ms'],
                'mysql_ms' => $mysqlMs,
                'php_ms' => $phpMs,
                'total_ms' => (microtime(true) - $startedTotal) * 1000,
            ],
            'account_ids' => $accountIds,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function accounts(): array
    {
        $rows = DB::table('accounts')
            ->whereNotNull('report_group')
            ->get([
                'account_id', 'account_name', 'account_class',
                'report_group', 'report_group_label', 'report_group_order', 'line_order',
            ]);

        $byId = [];

        foreach ($rows as $row) {
            $byId[(string) $row->account_id] = (array) $row;
        }

        return $byId;
    }

    /**
     * @return array<string, float>
     */
    private function milkUnits(string $farmId): array
    {
        $rows = DB::table('tracker_milk_production as mp')
            ->join('trackers as t', 't.tracker_id', '=', 'mp.tracker_id')
            ->where('t.farm_id', $farmId)
            ->groupBy('mp.month')
            ->get([
                'mp.month',
                DB::raw('SUM(mp.kg_ms_current + mp.kg_ms_deferred) as quantity'),
            ]);

        $byMonth = [];

        foreach ($rows as $row) {
            $byMonth[substr((string) $row->month, 0, 7)] = (float) $row->quantity;
        }

        return $byMonth;
    }

    /**
     * Every movement for the farm, unfiltered by date.
     *
     * A running balance needs the whole history before the period or every
     * closing figure is wrong — the same reason `stock_running` in the SQL
     * versions carries no date predicate. The difference is that there the
     * unfiltered scan stays inside the engine, and here all of it crosses the
     * wire into PHP.
     *
     * @return list<object>
     */
    private function stockMovements(string $farmId): array
    {
        return DB::table('tracker_stock_movements as mv')
            ->join('trackers as t', 't.tracker_id', '=', 'mv.tracker_id')
            ->where('t.farm_id', $farmId)
            ->orderBy('mv.tracker_id')
            ->orderBy('mv.month')
            ->get([
                'mv.tracker_id', 'mv.month', 'mv.purchases', 'mv.births', 'mv.sales', 'mv.deaths',
                't.opening_stock',
            ])
            ->all();
    }

    /**
     * The window function, by hand.
     *
     * `SUM(...) OVER (PARTITION BY tracker_id ORDER BY month ROWS BETWEEN
     * UNBOUNDED PRECEDING AND CURRENT ROW)` becomes a sorted pass with a
     * carried accumulator that resets per tracker. Correct, and O(n) either
     * way — the cost is not the algorithm, it is that the rows had to be moved
     * out of the database to run it.
     *
     * @param  list<object>  $movements
     * @return array<string, float>
     */
    private function stockUnits(array $movements): array
    {
        $byMonth = [];
        $currentTracker = null;
        $running = 0;

        foreach ($movements as $movement) {
            $tracker = (string) $movement->tracker_id;

            if ($tracker !== $currentTracker) {
                $currentTracker = $tracker;
                $running = (int) $movement->opening_stock;
            }

            $running += (int) $movement->purchases
                + (int) $movement->births
                - (int) $movement->sales
                - (int) $movement->deaths;

            $month = substr((string) $movement->month, 0, 7);
            $byMonth[$month] = ($byMonth[$month] ?? 0) + $running;
        }

        return array_map(static fn (int|float $v): float => (float) $v, $byMonth);
    }

    /**
     * @return list<array{month: string, interval_index: int, column_basis: string}>
     */
    private function monthSpine(string $periodFrom, string $periodTo, string $horizon): array
    {
        $months = [];
        $cursor = strtotime(date('Y-m-01', strtotime($periodFrom)));
        $last = strtotime(date('Y-m-01', strtotime($periodTo)));
        $horizonMonth = date('Y-m', strtotime($horizon));
        $index = 1;

        while ($cursor <= $last) {
            $month = date('Y-m', $cursor);

            $months[] = [
                'month' => $month,
                'interval_index' => $index++,
                'column_basis' => $month <= $horizonMonth ? 'Actual' : 'Forecast',
            ];

            $cursor = strtotime('+1 month', $cursor);
        }

        return $months;
    }

    /**
     * Attach the dimension columns Mongo could not.
     *
     * @param  array<string, array{account_id: string, month: string, amount_raw: int, line_count: int}>  $totals
     * @param  array<string, array<string, mixed>>  $accounts
     * @return list<array<string, mixed>>
     */
    private function classify(array $totals, array $accounts): array
    {
        $out = [];

        foreach ($totals as $total) {
            $account = $accounts[$total['account_id']] ?? null;

            if ($account === null) {
                continue;
            }

            $group = (string) $account['report_group'];
            $isIncome = str_ends_with($group, '_income');

            $out[] = [
                'month' => $total['month'],
                'account_id' => $total['account_id'],
                'account_name' => (string) $account['account_name'],
                'report_group' => $group,
                'report_group_label' => (string) $account['report_group_label'],
                'report_group_order' => (int) $account['report_group_order'],
                'line_order' => (int) $account['line_order'],
                'report_section' => $isIncome ? 'income' : 'costs',
                'report_section_order' => $isIncome ? 1 : 2,
                // Revenue is stored negative, as it is in every other stack,
                // and flips here so the report reads in natural signs.
                'amount' => ($account['account_class'] === 'REVENUE' ? -$total['amount_raw'] : $total['amount_raw'])
                    / self::FIXED_POINT,
            ];
        }

        return $out;
    }

    /**
     * `GROUPING SETS` as three passes over the same rows.
     *
     * The SQL builds (month, section, group, account), (month, section, group)
     * and (month, section) in one aggregation; without that, each level is its
     * own accumulator keyed by its own tuple.
     *
     * @param  list<array<string, mixed>>  $classified
     * @return array{account: array<string, array<string, mixed>>, group: array<string, array<string, mixed>>, section: array<string, array<string, mixed>>}
     */
    private function rollUp(array $classified): array
    {
        $account = [];
        $group = [];
        $section = [];

        foreach ($classified as $row) {
            $month = $row['month'];

            $keys = [
                'account' => $month.'|'.$row['report_section'].'|'.$row['report_group'].'|'.$row['account_id'],
                'group' => $month.'|'.$row['report_section'].'|'.$row['report_group'],
                'section' => $month.'|'.$row['report_section'],
            ];

            // The accumulator starts at zero and the row is added once. Seeding
            // it from $row would carry that row's own amount in, and the array
            // union operator keeps the left side's keys — so `$row + ['amount'
            // => 0.0]` silently doubles every total it touches.
            $account[$keys['account']] ??= [...$row, 'amount' => 0.0];
            $account[$keys['account']]['amount'] += $row['amount'];

            $group[$keys['group']] ??= [...$row, 'amount' => 0.0];
            $group[$keys['group']]['amount'] += $row['amount'];

            $section[$keys['section']] ??= [...$row, 'amount' => 0.0];
            $section[$keys['section']]['amount'] += $row['amount'];
        }

        return ['account' => $account, 'group' => $group, 'section' => $section];
    }

    /**
     * @param  array{account: array<string, array<string, mixed>>, group: array<string, array<string, mixed>>, section: array<string, array<string, mixed>>}  $levels
     * @return list<array<string, mixed>>
     */
    private function levelRows(array $levels): array
    {
        $rows = [];

        foreach ($levels['account'] as $row) {
            $rows[] = $row + ['level' => 0, 'label' => $row['report_group_label']];
        }

        foreach ($levels['group'] as $row) {
            $rows[] = [...$row, 'level' => 1, 'label' => $row['report_group_label'], 'account_id' => null, 'account_name' => null, 'line_order' => 0];
        }

        foreach ($levels['section'] as $row) {
            $rows[] = [
                ...$row,
                'level' => 2,
                'label' => $row['report_section'] === 'income' ? 'Income' : 'Direct Costs',
                'report_group' => null,
                'report_group_order' => 99,
                'account_id' => null,
                'account_name' => null,
                'line_order' => 0,
            ];
        }

        return $rows;
    }

    /**
     * Income minus costs, per month, from the section totals.
     *
     * @param  array{section: array<string, array<string, mixed>>}  $levels
     * @return list<array<string, mixed>>
     */
    private function marginRows(array $levels): array
    {
        $byMonth = [];

        foreach ($levels['section'] as $row) {
            $month = (string) $row['month'];
            $byMonth[$month] = ($byMonth[$month] ?? 0.0)
                + ($row['report_section'] === 'income' ? $row['amount'] : -$row['amount']);
        }

        $rows = [];

        foreach ($byMonth as $month => $amount) {
            $rows[] = [
                'month' => $month,
                'report_section' => 'margin',
                'report_section_order' => 3,
                'report_group' => null,
                'report_group_order' => 0,
                'report_group_label' => null,
                'account_id' => null,
                'account_name' => null,
                'line_order' => 0,
                'level' => 3,
                'label' => 'Gross Margin',
                'amount' => $amount,
            ];
        }

        return $rows;
    }

    /**
     * Join the rows to the month spine, attach quantities, and sort.
     *
     * The sort is the SQL's ORDER BY done by hand, and it has to be stable
     * across the same five keys or the rendered report reorders itself between
     * runs.
     *
     * @param  list<array<string, mixed>>  $rowsOut
     * @param  list<array{month: string, interval_index: int, column_basis: string}>  $months
     * @param  array<string, float>  $milk
     * @param  array<string, float>  $stock
     * @return list<array<string, mixed>>
     */
    private function emit(array $rowsOut, array $months, array $milk, array $stock, int $linesProcessed): array
    {
        $spine = [];

        foreach ($months as $month) {
            $spine[$month['month']] = $month;
        }

        $out = [];

        foreach ($rowsOut as $row) {
            $month = (string) $row['month'];

            if (! isset($spine[$month])) {
                continue;
            }

            $group = $row['report_group'];
            $kgMs = $milk[$month] ?? null;
            $head = $stock[$month] ?? null;

            $perUnit = null;

            if (is_string($group) && str_starts_with($group, 'dairy')) {
                $perUnit = ($kgMs ?? 0.0) != 0.0 ? $row['amount'] / $kgMs : null;
            } elseif (is_string($group) && str_starts_with($group, 'livestock')) {
                $perUnit = ($head ?? 0.0) != 0.0 ? $row['amount'] / $head : null;
            }

            $out[] = [
                'lines_processed' => $linesProcessed,
                'interval_index' => $spine[$month]['interval_index'],
                'month' => $month,
                'column_basis' => $spine[$month]['column_basis'],
                'report_section' => $row['report_section'],
                'report_group' => $group,
                'label' => $row['label'],
                'account_id' => $row['account_id'],
                'account_name' => $row['account_name'],
                'level' => $row['level'],
                'amount' => $row['amount'],
                'kg_ms' => $kgMs,
                'head' => $head,
                'per_unit' => $perUnit,
                '_sort' => [
                    $row['report_section_order'],
                    $row['report_group_order'],
                    $row['level'],
                    $row['line_order'],
                    $spine[$month]['interval_index'],
                ],
            ];
        }

        usort($out, static fn (array $a, array $b): int => $a['_sort'] <=> $b['_sort']);

        return array_map(static function (array $row): array {
            unset($row['_sort']);

            return $row;
        }, $out);
    }
}
