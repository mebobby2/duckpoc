<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Illuminate\Support\Facades\DB;

/**
 * Figured's valuation-movement algorithm, reimplemented in PHP the way Figured
 * writes it — the oracle the DuckDB statement is checked against.
 *
 * Deliberately a transliteration of `NonCashMovementGenerator::getMovements()`
 * rather than a tidier equivalent, because its job is to be obviously the same
 * algorithm to anyone reading both. So it keeps the shape that makes the real
 * one slow: a loop over intervals, recomputing a closing position each time,
 * with the previous value carried in a variable —
 *
 *     $movement = $intervalValue - $previousValue;
 *     $previousValue = $intervalValue;
 *
 * Checking SQL against SQL would only prove the query is self-consistent. The
 * point of Phase 2 is that this loop and that statement agree.
 *
 * Reads MySQL directly. It is the same data the lake query reads (trackers and
 * stock movements are relational in this PoC, as in Figured), so there is no
 * second copy to drift.
 */
final class ValuationMovementOracle
{
    private const int FIXED_POINT = 10000;

    /**
     * @return list<array{month: string, tracker_id: string, closing_head: int, closing_value: int, movement: int}>
     */
    public function run(string $farmId, string $periodFrom, string $periodTo, string $horizon): array
    {
        $trackers = DB::table('trackers')
            ->where('farm_id', $farmId)
            ->orderBy('display_order')
            ->get(['tracker_id', 'opening_stock'])
            ->all();

        $months = $this->months($periodFrom, $periodTo);
        $out = [];

        foreach ($trackers as $tracker) {
            $trackerId = (string) $tracker->tracker_id;

            $movements = DB::table('tracker_stock_movements')
                ->where('tracker_id', $trackerId)
                ->orderBy('month')
                ->get(['month', 'type', 'purchases', 'births', 'sales', 'deaths'])
                ->all();

            $rates = DB::table('valuation_rates')
                ->where('tracker_id', $trackerId)
                ->pluck('per_head_value', 'month')
                ->all();

            // The horizon split, applied exactly as the SQL applies it.
            $kept = array_filter($movements, function (object $row) use ($horizon): bool {
                $month = substr((string) $row->month, 0, 10);

                return $month <= $horizon
                    ? $row->type === 'actuals'
                    : $row->type === 'forecast';
            });

            // Closing head per month, accumulated from the start of history —
            // this is what `getClosingTotal()` recomputes per interval.
            $running = (int) $tracker->opening_stock;
            $closingByMonth = [];

            foreach ($kept as $row) {
                $running += (int) $row->purchases + (int) $row->births
                    - (int) $row->sales - (int) $row->deaths;
                $closingByMonth[substr((string) $row->month, 0, 7)] = $running;
            }

            // The interval loop. `$previousValue` is unset for the first month
            // so the opening position comes from the month before the period,
            // not from zero — the trap the SQL avoids by taking LAG before the
            // period filter.
            unset($previousValue);

            foreach ($months as $month) {
                if (!isset($closingByMonth[$month], $rates[$month.'-01'])) {
                    continue;
                }

                $head = $closingByMonth[$month];
                $value = $head * (int) $rates[$month.'-01'];

                if (!isset($previousValue)) {
                    $previousValue = $this->valueBefore($month, $closingByMonth, $rates) ?? $value;
                }

                $out[] = [
                    'month' => $month,
                    'tracker_id' => $trackerId,
                    'closing_head' => $head,
                    'closing_value' => $value,
                    'movement' => $value - $previousValue,
                ];

                $previousValue = $value;
            }
        }

        usort($out, fn (array $a, array $b): int => [$a['month'], $a['tracker_id']] <=> [$b['month'], $b['tracker_id']]);

        return $out;
    }

    /**
     * The closing valuation of the month immediately before the period.
     *
     * Null when the period starts at the beginning of the tracker's history,
     * in which case the first movement is zero rather than the whole herd.
     *
     * @param array<string, int> $closingByMonth
     * @param array<string, int> $rates
     */
    private function valueBefore(string $month, array $closingByMonth, array $rates): ?int
    {
        $previous = date('Y-m', strtotime($month.'-01 -1 month'));

        if (!isset($closingByMonth[$previous], $rates[$previous.'-01'])) {
            return null;
        }

        return $closingByMonth[$previous] * (int) $rates[$previous.'-01'];
    }

    /**
     * @return list<string>
     */
    private function months(string $from, string $to): array
    {
        $months = [];
        $cursor = strtotime(substr($from, 0, 7).'-01');
        $end = strtotime(substr($to, 0, 7).'-01');

        while ($cursor <= $end) {
            $months[] = date('Y-m', $cursor);
            $cursor = strtotime(date('Y-m-d', $cursor).' +1 month');
        }

        return $months;
    }
}
