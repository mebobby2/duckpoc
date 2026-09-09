<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Illuminate\Support\Facades\DB;

/**
 * Seeds monthly stock movements for every tracker, across each tracker's whole
 * journal history.
 *
 * The history matters, not just the reporting period: opening stock for any
 * month is every prior movement accumulated, so a report covering 2021 still
 * needs 1996-2020 to compute its opening balance. Seeding only the period
 * under test would make opening stock silently wrong — and wrong in a way that
 * still balances against itself, which is this project's recurring failure
 * mode.
 *
 * Movements are shaped to keep herd size roughly stable rather than random:
 * a mob that drifted to zero or to millions of head would make per-head
 * margins meaningless. Purchases and births roughly offset sales and deaths,
 * with a seasonal pattern so the numbers move month to month.
 */
final class StockMovementSeeder
{
    /** Head per tracker before the first recorded movement. */
    private const int OPENING_STOCK = 1_200;

    /**
     * Seeds movements for every tracker on the given farms.
     *
     * The year span is passed in rather than looked up here: it comes from the
     * journal data in the lake, and the DuckLake catalog is single-writer, so a
     * seeder that reached for it directly would contend with whatever else is
     * writing. The caller already holds a lake connection.
     *
     * @param array<string, array{0: int, 1: int}> $farmSpans farm_id => [firstYear, lastYear]
     * @param null|callable(string, int): void $onFarm
     */
    public function seed(array $farmSpans, ?callable $onFarm = null): void
    {
        foreach ($farmSpans as $farmId => [$firstYear, $lastYear]) {
            $written = $this->seedFarm($farmId, $firstYear, $lastYear);

            if ($onFarm !== null) {
                $onFarm($farmId, $written);
            }
        }
    }

    private function seedFarm(string $farmId, int $firstYear, int $lastYear): int
    {
        $trackers = DB::table('trackers')
            ->where('farm_id', $farmId)
            ->orderBy('display_order')
            ->pluck('tracker_id')
            ->all();

        if ($trackers === []) {
            return 0;
        }

        DB::table('tracker_stock_movements')->whereIn('tracker_id', $trackers)->delete();
        DB::table('trackers')->whereIn('tracker_id', $trackers)->update(['opening_stock' => self::OPENING_STOCK]);

        $rows = [];
        $written = 0;

        foreach ($trackers as $index => $trackerId) {
            for ($year = $firstYear; $year <= $lastYear; $year++) {
                for ($month = 1; $month <= 12; $month++) {
                    $rows[] = $this->movement($trackerId, $index, $year, $month);

                    if (count($rows) >= 2000) {
                        DB::table('tracker_stock_movements')->insert($rows);
                        $written += count($rows);
                        $rows = [];
                    }
                }
            }
        }

        if ($rows !== []) {
            DB::table('tracker_stock_movements')->insert($rows);
            $written += count($rows);
        }

        return $written;
    }

    /**
     * One month's movements for one tracker.
     *
     * Seasonal by design: births peak in spring (Sep-Nov), sales in autumn
     * (Mar-May), so the herd curve looks like a farm rather than noise. Net
     * change is kept near zero across a year so herd size stays plausible over
     * a 30-year history.
     */
    private function movement(string $trackerId, int $trackerIndex, int $year, int $month): array
    {
        $phase = ($trackerIndex + $year) % 3;

        $births = in_array($month, [9, 10, 11], true) ? 120 + ($phase * 10) : 0;
        $sales = in_array($month, [3, 4, 5], true) ? 110 + ($phase * 10) : 0;
        $purchases = $month === 7 ? 40 : 0;
        $deaths = 3 + (($trackerIndex + $month) % 4);

        return [
            'tracker_id' => $trackerId,
            'month' => sprintf('%04d-%02d-01', $year, $month),
            'purchases' => $purchases,
            'births' => $births,
            'sales' => $sales,
            'deaths' => $deaths,
        ];
    }
}
