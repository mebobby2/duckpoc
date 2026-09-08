<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;

/**
 * Seeds three farms that differ ONLY in how many trackers they have.
 *
 * This exists to test one claim, made by Figured's ex-CTO: that the cost of
 * a report is driven by the complexity of the farm rather than the number of
 * journals — "ones with 50 trackers means it has to do 50x queries".
 *
 * In Figured that is literally true. `LivestockStructureBuilder` loops
 * `foreach ($trackers as $tracker)` and, per tracker, calls
 * `LivestockQuantities::getTrackerQuantitiesSection(..., [$tracker->id], ...)`
 * — a single-element array, once per tracker — which does a
 * `Tracker::findOrFail()` plus a stock-quantity computation. The journal
 * aggregation itself does not fan out (that stays at four Mongo queries,
 * bucketed by interval type), so the tracker axis is a genuinely separate
 * cost from the volume axis this project measured first.
 *
 * THE EXPERIMENT'S CONTROL, and the whole reason this seeder exists rather
 * than reusing `CashFlowScaleSeeder`: **journal count is held constant** at
 * `ROWS_PER_FARM` across all three farms. Only tracker count varies. Vary
 * both and the resulting curve says nothing, because there would be no way
 * to attribute a slowdown to tracker count rather than to volume.
 *
 * Each farm also gets its own `region`, so each lands in its own partition
 * and one farm's query cannot be charged for another's files. That is not
 * how a real cohort would be laid out — it is a deliberate isolation of the
 * variable under test.
 */
final class TrackerScaleSeeder
{
    private const int FIXED_POINT = 10000;

    /** Actuals up to and including this year; forecast after it. */
    private const int HORIZON_YEAR = 2021;

    private const int FIRST_YEAR = 2018;
    private const int YEARS = 8;

    /**
     * Revenue per line, as a multiple of cost per line.
     *
     * Purely a realism calibration — 3x puts income comfortably above costs
     * given twice as many cost accounts as income accounts. It has no effect
     * on what this seeder exists to measure: tracker cardinality, not
     * magnitudes.
     */
    private const int REVENUE_WEIGHT = 3;

    /**
     * Identical for every farm — this is the control. See the class docblock.
     */
    private const int ROWS_PER_FARM = 200_000;

    /**
     * farm_id => [farm_type, region, tracker count]
     *
     * 1 / 10 / 50 gives three points on the curve, with 50 matching the
     * "ones with 50 trackers" case named as the real-world pain.
     */
    private const array FARMS = [
        'tracker-farm-01' => ['dairy', 'tracker-a', 1],
        'tracker-farm-10' => ['dairy', 'tracker-b', 10],
        'tracker-farm-50' => ['dairy', 'tracker-c', 50],
    ];

    /**
     * Chart of accounts: [suffix, class, category].
     *
     * The `tracker_*` categories are the ones whose lines carry a
     * `tracker_id`; the rest are farm-level and stay unattributed, mirroring
     * Cash Flow's real shape where per-tracker gross profits are summed
     * *alongside* farm-level `other_income` and `direct_costs`.
     */
    private const array ACCOUNTS = [
        ['livestock-sales', 'REVENUE', 'tracker_income'],
        ['wool-income', 'REVENUE', 'tracker_income'],
        ['feed', 'EXPENSE', 'tracker_direct_costs'],
        ['animal-health', 'EXPENSE', 'tracker_direct_costs'],
        ['shearing', 'EXPENSE', 'tracker_direct_costs'],
        ['cartage', 'EXPENSE', 'tracker_direct_costs'],

        ['other-income', 'REVENUE', 'other_income'],
        ['sundry-income', 'REVENUE', 'other_income'],
        ['general-direct', 'EXPENSE', 'direct_costs'],
        ['wages', 'EXPENSE', 'operating_expenses'],
        ['fuel', 'EXPENSE', 'operating_expenses'],
        ['repairs', 'EXPENSE', 'operating_expenses'],
        ['insurance', 'EXPENSE', 'operating_expenses'],
        ['gst-control', 'LIABILITY', 'gst'],
    ];

    /** Cycled so the "by stock type" grouping has something to group. */
    private const array STOCK_TYPES = ['Sheep', 'Cattle', 'Deer'];

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
        private readonly string $appAlias,
    ) {
    }

    /**
     * @param null|callable(string, int, int): void $onFarmSeeded
     */
    public function seed(?callable $onFarmSeeded = null): void
    {
        $this->clearExisting();
        $this->seedAccounts();
        $this->seedFarms();
        $this->seedTrackers();

        foreach (self::FARMS as $farmId => [$farmType, $region, $trackerCount]) {
            $this->seedTransactionLines($farmId, $farmType, $region, $trackerCount);

            if ($onFarmSeeded !== null) {
                $onFarmSeeded($farmId, self::ROWS_PER_FARM, $trackerCount);
            }
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function farms(): array
    {
        return self::FARMS;
    }

    public static function rowsPerFarm(): int
    {
        return self::ROWS_PER_FARM;
    }

    /**
     * Scoped to this seeder's own `tracker-` ids, so the Cash Flow oracle and
     * the `scale-` volume dataset both survive untouched.
     */
    private function clearExisting(): void
    {
        $this->db->query("DELETE FROM {$this->alias}.transaction_lines WHERE farm_id LIKE 'tracker-%'");

        DB::table('trackers')->where('farm_id', 'like', 'tracker-%')->delete();
        DB::table('farms')->where('farm_id', 'like', 'tracker-%')->delete();
        DB::table('accounts')->where('account_id', 'like', 'tracker-%')->delete();
    }

    private function seedAccounts(): void
    {
        $rows = [];
        foreach (self::ACCOUNTS as [$suffix, $class, $category]) {
            $rows[] = [
                'account_id' => 'tracker-'.$suffix,
                'account_name' => ucwords(str_replace('-', ' ', $suffix)),
                'account_class' => $class,
                'account_category' => $category,
                'is_gst_account' => $category === 'gst',
                'is_default_bank_account' => false,
            ];
        }

        DB::table('accounts')->insert($rows);
    }

    private function seedFarms(): void
    {
        $rows = [];
        foreach (self::FARMS as $farmId => [$farmType, $region]) {
            $rows[] = [
                'farm_id' => $farmId,
                'farm_type' => $farmType,
                'region' => $region,
                'opening_balance' => 0,
            ];
        }

        DB::table('farms')->insert($rows);
    }

    /**
     * Trackers go to MySQL, matching Figured — `trackers` is an Eloquent
     * table there, and only the `tracker_id` tag reaches the lake.
     */
    private function seedTrackers(): void
    {
        $rows = [];

        foreach (self::FARMS as $farmId => [, , $trackerCount]) {
            for ($i = 0; $i < $trackerCount; $i++) {
                $stockType = self::STOCK_TYPES[$i % count(self::STOCK_TYPES)];

                $rows[] = [
                    'tracker_id' => self::trackerId($farmId, $i),
                    'farm_id' => $farmId,
                    'tracker_name' => sprintf('%s Mob %d', $stockType, $i + 1),
                    'tracker_type' => 'livestock',
                    'stock_type' => $stockType,
                    'display_order' => $i,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('trackers')->insert($chunk);
        }
    }

    private static function trackerId(string $farmId, int $index): string
    {
        return sprintf('%s-t%02d', $farmId, $index);
    }

    /**
     * One INSERT per farm, rows synthesised in DuckDB from `range()`.
     *
     * Lines on a `tracker_*` account get a `tracker_id`, spread round-robin
     * across that farm's trackers; every other line gets NULL. So the 50-
     * tracker farm has the same journal count as the 1-tracker farm, just
     * distributed over 50 tracker values instead of 1 — which is precisely
     * the difference under test.
     */
    private function seedTransactionLines(string $farmId, string $farmType, string $region, int $trackerCount): void
    {
        $accountCount = count(self::ACCOUNTS);
        $fp = self::FIXED_POINT;
        $rowTarget = self::ROWS_PER_FARM;

        $sql = <<<SQL
            INSERT INTO {$this->alias}.transaction_lines
                (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id)
            SELECT
                '{$farmId}',
                '{$farmType}',
                '{$region}',
                '{$farmId}-' || g.i,
                a.account_id,
                CASE WHEN g.yr <= {$this->horizonYear()} THEN 'actuals' ELSE 'forecast' END,
                'cash',
                (make_date(g.yr, 1, 1) + INTERVAL (g.day_offset) DAY)::DATE,
                -- Revenue negative (credit), expenses positive (debit) — the
                -- convention everything downstream depends on.
                --
                -- Revenue is also scaled by REVENUE_WEIGHT. Without it the
                -- chart of accounts alone decides profitability: there are
                -- twice as many cost accounts as income accounts and rows are
                -- spread evenly per account, so equal per-line amounts make
                -- costs ~2x income and every farm shows a permanent negative
                -- gross profit. That is arithmetically correct and completely
                -- unrealistic, and in a demo it reads as a bug in the report.
                CASE
                    WHEN a.account_class = 'REVENUE'
                        THEN -(((g.i % 500) + 100) * {$fp} * {$this->revenueWeight()})
                    ELSE (((g.i % 500) + 100) * {$fp})
                END,
                -- Only tracker-attributable accounts carry a tag. Farm-level
                -- lines (operating expenses, GST, other income) belong to no
                -- tracker, exactly as in the real report.
                CASE
                    WHEN a.account_category IN ('tracker_income', 'tracker_direct_costs')
                    THEN '{$farmId}-t' || lpad(CAST(g.i % {$trackerCount} AS VARCHAR), 2, '0')
                END
            FROM (
                SELECT
                    i,
                    -- `//`, not `/`: DuckDB's `/` is float division.
                    {$this->firstYear()} + CAST((i // {$accountCount}) % {$this->years()} AS INTEGER) AS yr,
                    CAST((i * 7919) % 365 AS INTEGER) AS day_offset,
                    CAST(i % {$accountCount} AS INTEGER) AS acc_idx
                FROM range(0, {$rowTarget}) AS t(i)
            ) g
            JOIN (
                SELECT
                    account_id,
                    account_class,
                    account_category,
                    CAST(row_number() OVER (ORDER BY account_id) - 1 AS INTEGER) AS idx
                FROM {$this->appAlias}.accounts
                WHERE account_id LIKE 'tracker-%'
            ) a ON a.idx = g.acc_idx
            -- Sorted by date, not farm_id: each INSERT writes a single farm,
            -- so farm_id is constant and sorting on it is a no-op (DuckDB
            -- rejects it outright as a non-integer literal). Date order is
            -- what actually pays — it tightens Parquet row-group min/max on
            -- `date`, which is the statistic the report's `date BETWEEN`
            -- predicate prunes on (see the README's pruning section).
            ORDER BY (make_date(g.yr, 1, 1) + INTERVAL (g.day_offset) DAY)
            SQL;

        $this->db->query($sql);
    }

    private function horizonYear(): int
    {
        return self::HORIZON_YEAR;
    }

    private function firstYear(): int
    {
        return self::FIRST_YEAR;
    }

    private function years(): int
    {
        return self::YEARS;
    }

    private function revenueWeight(): int
    {
        return self::REVENUE_WEIGHT;
    }
}
