<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Illuminate\Support\Facades\DB;

/**
 * Seeds per-head valuation rates, and splits existing stock movements into
 * actuals and forecast around a horizon date.
 *
 * Rates drift rather than jump: a herd revalued from $900 to $905 a head
 * produces a movement dominated by the revaluation, which is the interesting
 * case, while a step change would make every month after it look identical.
 * Seasonal because stock values genuinely are — store lambs are worth more in
 * autumn than in spring.
 *
 * The movement split is retrospective on purpose. `StockMovementSeeder` writes
 * a tracker's whole history without a type, because until Phase 2 nothing read
 * one; marking rows here rather than reseeding keeps the head counts that
 * Gross Margin V2's parity check already passes against byte-identical.
 */
final class ValuationRateSeeder
{
    /** Dollars per head x 10,000 at the start of a tracker's history. */
    private const int BASE_VALUE = 900_0000;

    /** Annual drift, as a fraction of the base. */
    private const float ANNUAL_DRIFT = 0.04;

    /**
     * Seeds rates for every tracker on the given farms, over each farm's span.
     *
     * @param array<string, array{0: int, 1: int}> $farmSpans farm_id => [firstYear, lastYear]
     * @param null|callable(string, int): void $onFarm
     */
    public function seed(array $farmSpans, string $horizon, ?callable $onFarm = null): void
    {
        foreach ($farmSpans as $farmId => [$firstYear, $lastYear]) {
            $written = $this->seedFarm((string) $farmId, $firstYear, $lastYear, $horizon);

            if ($onFarm !== null) {
                $onFarm((string) $farmId, $written);
            }
        }
    }

    private function seedFarm(string $farmId, int $firstYear, int $lastYear, string $horizon): int
    {
        $trackers = DB::table('trackers')
            ->where('farm_id', $farmId)
            ->orderBy('display_order')
            ->pluck('tracker_id')
            ->all();

        if ($trackers === []) {
            return 0;
        }

        DB::table('valuation_rates')->whereIn('tracker_id', $trackers)->delete();

        // The horizon split is a property of the date, not of the tracker, so
        // it is one statement rather than a pass per tracker.
        DB::table('tracker_stock_movements')
            ->whereIn('tracker_id', $trackers)
            ->update(['type' => DB::raw(sprintf(
                "CASE WHEN month <= '%s' THEN 'actuals' ELSE 'forecast' END",
                addslashes($horizon),
            ))]);

        $rows = [];
        $written = 0;

        foreach ($trackers as $index => $trackerId) {
            for ($year = $firstYear; $year <= $lastYear; $year++) {
                for ($month = 1; $month <= 12; $month++) {
                    $rows[] = $this->rate((string) $trackerId, (int) $index, $year, $month, $firstYear);

                    if (count($rows) >= 2000) {
                        DB::table('valuation_rates')->insert($rows);
                        $written += count($rows);
                        $rows = [];
                    }
                }
            }
        }

        if ($rows !== []) {
            DB::table('valuation_rates')->insert($rows);
            $written += count($rows);
        }

        return $written;
    }

    /**
     * One month's per-head value for one tracker.
     *
     * Deterministic — no randomness anywhere in this PoC's seeders, so a
     * reseed reproduces the same numbers and a parity failure is always the
     * query's fault rather than the data's.
     */
    private function rate(string $trackerId, int $trackerIndex, int $year, int $month, int $firstYear): array
    {
        $yearsElapsed = $year - $firstYear;

        // Each tracker sits at a different price point; a deer tracker and a
        // sheep tracker valued identically would hide a partition bug.
        $base = self::BASE_VALUE + ($trackerIndex % 7) * 25_0000;

        $drift = (int) round($base * self::ANNUAL_DRIFT * $yearsElapsed);

        // Autumn premium, peaking in April.
        $seasonal = (int) round($base * 0.03 * sin((($month - 1) / 12) * 2 * M_PI));

        return [
            'tracker_id' => $trackerId,
            'month' => sprintf('%04d-%02d-01', $year, $month),
            'per_head_value' => $base + $drift + $seasonal,
        ];
    }
}
