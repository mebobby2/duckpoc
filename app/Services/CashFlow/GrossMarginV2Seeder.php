<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;

/**
 * Seeds a mixed-enterprise dairy farm — milk AND livestock trackers on the
 * same farm — which is the case Gross Margin V2 exists to report on.
 *
 * A single-enterprise farm would not test anything interesting: the point is
 * that Figured needs two different reports for this farm, because milk and
 * livestock quantities come from different services and the two structure
 * builders exclude each other. One farm with both is the smallest dataset
 * that makes that a problem.
 *
 * Accounts carry their report group, so the hierarchy is data rather than
 * builder branching — see the migration for why that matters.
 */
final class GrossMarginV2Seeder
{
    public const string FARM_ID = 'gm-dairy-farm';
    public const string FARM_TYPE = 'dairy';
    public const string REGION = 'gm-waikato';

    /** A volume variant of the same farm shape, for scale testing. */
    public const string BULK_FARM_ID = 'gm-dairy-farm-1m';
    public const string BULK_REGION = 'gm-bulk';

    /** A larger variant again — its own region so it partitions separately. */
    public const string HUGE_FARM_ID = 'gm-dairy-farm-500m';
    public const string HUGE_REGION = 'gm-huge';

    /**
     * The billion-row variant. Separate from HUGE rather than a bigger --rows
     * on it, because the 500M farm is the reference every timing in the README
     * is quoted against; re-seeding it at a different volume would invalidate
     * those numbers with nothing left to compare to.
     */
    public const string MEGA_FARM_ID = 'gm-dairy-farm-1b';
    public const string MEGA_REGION = 'gm-mega';

    /**
     * The same 500M report lines as HUGE, plus the bookkeeping lines a real
     * chart of accounts carries — GST, the payable/receivable side, and the
     * bank payment settling it.
     *
     * Exists because every other farm here is unrealistically pure: 100% of
     * its lines are accounts the Gross Margin report actually sums. Measured on
     * Figured's own seeded dairy farm, only ~28% are. The other ~72% are
     * Accounts Payable, Farm Current Account and GST — lines the report reads
     * and immediately discards.
     *
     * So the engines have been handed 3.5x more relevant data per row than the
     * system they are being compared against. This farm removes that
     * advantage. The report output is IDENTICAL to the 500M farm's, because
     * the added accounts carry no `report_group` and `account_scope` excludes
     * them — which is exactly what makes the comparison clean: same answer,
     * same useful rows, 3.5x the rows scanned to reach it.
     */
    public const string RAW_FARM_ID = 'gm-dairy-farm-500M-non-aggregated';
    public const string RAW_REGION = 'gm-raw';

    private const int FIXED_POINT = 10000;

    /**
     * Actuals to this date, forecast after.
     *
     * A DATE, not a year: the real report reads "Actuals to 31 August 2026"
     * with September onward as Forecast, so the boundary falls mid-year. Typing
     * journals by year instead put all of 2026 in `actuals`, and a mid-2026
     * horizon then dropped Sep-Dec into the gap — actuals after the horizon
     * match neither branch of the scope predicate.
     */
    public const string HORIZON_DATE = '2026-08-31';

    private const int FIRST_YEAR = 2024;
    private const int YEARS = 4;

    /** Milk solids per cow per month, peak season. Roughly NZ-typical. */
    private const int KG_MS_PER_COW_PEAK = 40;

    /** Dollars per kg of milk solids. */
    private const int PAYOUT_PER_KG_MS = 8;

    /**
     * [tracker_id suffix, name, type, stock_class, opening head]
     *
     * The milk tracker has no head count of its own — the herd is the
     * livestock MA Cows tracker. That mirrors reality: a dairy farm's milk
     * enterprise and its herd are separate operating entities that share
     * animals.
     */
    private const array TRACKERS = [
        ['milk', 'Milk Platform', 'milk', null, 0],
        ['ma-cows', 'MA Cows', 'livestock', 'MA Cows', 950],
        ['r2-heifers', 'R2 Heifers', 'livestock', 'R2 Heifers', 240],
        ['bobby-calves', 'Bobby Calves', 'livestock', 'Bobby Calves', 60],
        ['bulls', 'Breeding Bulls', 'livestock', 'Breeding Bulls', 18],
    ];

    /**
     * [suffix, name, class, category, group, group label, group order, line order, tracker suffix]
     *
     * Line items match the shape of the real report: milk split into current
     * year and deferred, livestock sales split by stock class.
     */
    private const array ACCOUNTS = [
        ['milk-current', 'Milk Production - Current Year', 'REVENUE', 'tracker_income', 'dairy_income', 'Dairy Income', 1, 1, 'milk'],
        ['milk-deferred', 'Milk Production - Deferred', 'REVENUE', 'tracker_income', 'dairy_income', 'Dairy Income', 1, 2, 'milk'],

        ['sales-bobby', 'Sales - Dairy Bobby Calves', 'REVENUE', 'tracker_income', 'livestock_income', 'Livestock Income', 2, 1, 'bobby-calves'],
        ['sales-r2', 'Sales - Dairy R2 Heifers', 'REVENUE', 'tracker_income', 'livestock_income', 'Livestock Income', 2, 2, 'r2-heifers'],
        ['sales-ma', 'Sales - Dairy MA Cows', 'REVENUE', 'tracker_income', 'livestock_income', 'Livestock Income', 2, 3, 'ma-cows'],
        ['sales-bulls', 'Sales - Dairy Breeding Bulls', 'REVENUE', 'tracker_income', 'livestock_income', 'Livestock Income', 2, 4, 'bulls'],

        ['other-income', 'Other Income', 'REVENUE', 'tracker_income', 'other_income', 'Other', 3, 1, 'milk'],

        ['feed-supplement', 'Feed - Supplements', 'EXPENSE', 'tracker_direct_costs', 'dairy_costs', 'Dairy Costs', 4, 1, 'milk'],
        ['feed-grazing', 'Feed - Grazing', 'EXPENSE', 'tracker_direct_costs', 'dairy_costs', 'Dairy Costs', 4, 2, 'milk'],
        ['shed-costs', 'Shed & Milk Harvesting', 'EXPENSE', 'tracker_direct_costs', 'dairy_costs', 'Dairy Costs', 4, 3, 'milk'],

        ['animal-health', 'Animal Health', 'EXPENSE', 'tracker_direct_costs', 'livestock_costs', 'Livestock Costs', 5, 1, 'ma-cows'],
        ['breeding', 'Breeding & AI', 'EXPENSE', 'tracker_direct_costs', 'livestock_costs', 'Livestock Costs', 5, 2, 'ma-cows'],
        ['calf-rearing', 'Calf Rearing', 'EXPENSE', 'tracker_direct_costs', 'livestock_costs', 'Livestock Costs', 5, 3, 'bobby-calves'],
        ['stock-purchases', 'Stock Purchases', 'EXPENSE', 'tracker_direct_costs', 'livestock_costs', 'Livestock Costs', 5, 4, 'r2-heifers'],
    ];

    /**
     * The bookkeeping accounts, and the leg each one contributes per report line.
     *
     * [suffix, name, class, category, is_gst, is_bank, line-id suffix, amount multiple, every nth line]
     *
     * Multiples are relative to the report line's own amount, at a 15% GST
     * rate. `every nth` is why GST appears on half the lines rather than all of
     * them: on the measured Figured farm the ratios per report line are 0.48
     * GST, 1.11 payable and 0.95 bank, and reproducing that mix is the whole
     * point — a uniform three-legs-per-line would land at 25% report lines
     * instead of the ~28% real data shows.
     *
     * These are NOT a balanced double entry. Nothing in this PoC reports a
     * balance sheet, so the legs are shaped to reproduce the account
     * DISTRIBUTION and the volume, not to sum to zero. Anything that starts
     * caring about balance needs a real journal model, not this.
     */
    private const array NON_REPORT_LEGS = [
        ['gst', '-gst', 0.15, 2],
        ['accounts-payable', '-ap', -1.15, 1],
        ['bank', '-bank', 1.15, 1],
    ];

    /**
     * The accounts those legs post to. [suffix, name, class, category, is_gst, is_bank]
     *
     * Receivable is here but absent from the legs above: it substitutes for
     * payable on revenue accounts, so it is posted to without being a leg of
     * its own.
     */
    private const array NON_REPORT_ACCOUNTS = [
        ['gst', 'GST', 'LIABILITY', 'current_liability', true, false],
        ['accounts-payable', 'Accounts Payable', 'LIABILITY', 'current_liability', false, false],
        ['accounts-receivable', 'Accounts Receivable', 'ASSET', 'current_asset', false, false],
        ['bank', 'Farm Current Account', 'ASSET', 'current_asset', false, true],
    ];

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
        private readonly string $appAlias,
        /** Which farm this instance seeds — see BULK_FARM_ID. */
        private readonly string $farmIdValue = self::FARM_ID,
        private readonly string $regionValue = self::REGION,
        /**
         * Target journal lines for the cost/sales accounts.
         *
         * 0 keeps the demo shape: one line per account per month, which is
         * legible on screen but only ~600 rows. A non-zero target fans those
         * accounts out into many lines per month, the way a real farm's
         * invoice lines do.
         *
         * Milk income is deliberately NOT fanned out. A dairy farm receives
         * one payout per month from its processor, so milk stays one line per
         * month derived from actual production — which also keeps the per-kg-MS
         * figures honest. Inflating it would make the volume look bigger and
         * the report less realistic at the same time.
         */
        private readonly int $bulkRows = 0,
        /** See RAW_FARM_ID — emits the bookkeeping lines alongside each report line. */
        private readonly bool $withNonReportLines = false,
    ) {
    }

    public function seed(): void
    {
        $this->clearExisting();
        $this->seedAccounts();
        $this->seedNonReportAccounts();
        $this->seedFarm();
        $this->seedTrackers();
        $this->seedMilkProduction();
        $this->seedStockMovements();
        $this->seedJournals();
    }

    /**
     * The accounts that fan out, as [suffix, class, tracker suffix].
     *
     * Milk is excluded: a dairy farm gets one payout a month from its
     * processor, so those two accounts stay one line per month derived from
     * actual production rather than being inflated.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function reportAccounts(): array
    {
        $out = [];

        foreach (self::ACCOUNTS as [$suffix, , $class, , , , , , $trackerSuffix]) {
            if ($suffix !== 'milk-current' && $suffix !== 'milk-deferred') {
                $out[] = [$suffix, $class, $trackerSuffix];
            }
        }

        return $out;
    }

    public static function payoutPerKgMs(): int
    {
        return self::PAYOUT_PER_KG_MS;
    }

    public static function yearCount(): int
    {
        return self::YEARS;
    }

    public static function firstYear(): int
    {
        return self::FIRST_YEAR;
    }

    public static function lastYear(): int
    {
        return self::FIRST_YEAR + self::YEARS - 1;
    }

    private function clearExisting(): void
    {
        $this->db->query("DELETE FROM {$this->alias}.transaction_lines WHERE farm_id = '{$this->farmIdValue}'");

        $trackerIds = DB::table('trackers')->where('farm_id', $this->farmIdValue)->pluck('tracker_id')->all();

        if ($trackerIds !== []) {
            DB::table('tracker_milk_production')->whereIn('tracker_id', $trackerIds)->delete();
            DB::table('tracker_stock_movements')->whereIn('tracker_id', $trackerIds)->delete();
        }

        DB::table('trackers')->where('farm_id', $this->farmIdValue)->delete();
        DB::table('farms')->where('farm_id', $this->farmIdValue)->delete();
        // Accounts are shared by both farm variants, so only the demo farm
        // (which owns them) clears them.
        if ($this->farmIdValue === self::FARM_ID) {
            DB::table('accounts')->where('account_id', 'like', 'gm-%')->delete();
        }
    }

    private function seedAccounts(): void
    {
        if ($this->farmIdValue !== self::FARM_ID
            && DB::table('accounts')->where('account_id', 'like', 'gm-%')->exists()) {
            return;
        }

        $rows = [];

        foreach (self::ACCOUNTS as [$suffix, $name, $class, $category, $group, $groupLabel, $groupOrder, $lineOrder]) {
            $rows[] = [
                'account_id' => 'gm-'.$suffix,
                'account_name' => $name,
                'account_class' => $class,
                'account_category' => $category,
                'report_group' => $group,
                'report_group_label' => $groupLabel,
                'report_group_order' => $groupOrder,
                'line_order' => $lineOrder,
                'is_gst_account' => false,
                'is_default_bank_account' => false,
            ];
        }

        DB::table('accounts')->insert($rows);
    }

    /**
     * Upserted rather than guarded like `seedAccounts()`, and always run.
     *
     * The accounts table is shared across every gm farm, so these rows may
     * already exist from an earlier seed of a different farm. Adding them is
     * harmless to the other farms: `report_group` is NULL, so `account_scope`
     * skips them and no existing report can change. Only a farm seeded with
     * `withNonReportLines` ever posts to them.
     */
    private function seedNonReportAccounts(): void
    {
        if (!$this->withNonReportLines) {
            return;
        }

        foreach (self::NON_REPORT_ACCOUNTS as [$suffix, $name, $class, $category, $isGst, $isBank]) {
            DB::table('accounts')->upsert([[
                'account_id' => 'gm-'.$suffix,
                'account_name' => $name,
                'account_class' => $class,
                'account_category' => $category,
                'report_group' => null,
                'report_group_label' => null,
                'report_group_order' => 0,
                'line_order' => 0,
                'is_gst_account' => $isGst,
                'is_default_bank_account' => $isBank,
            ]], ['account_id']);
        }
    }

    private function seedFarm(): void
    {
        DB::table('farms')->insert([
            'farm_id' => $this->farmIdValue,
            'farm_type' => self::FARM_TYPE,
            'region' => $this->regionValue,
            'opening_balance' => 0,
        ]);
    }

    private function seedTrackers(): void
    {
        $rows = [];

        foreach (self::TRACKERS as $index => [$suffix, $name, $type, $stockClass, $openingStock]) {
            $rows[] = [
                'tracker_id' => $this->trackerId($suffix),
                'farm_id' => $this->farmIdValue,
                'tracker_name' => $name,
                'tracker_type' => $type,
                'stock_type' => $stockClass ?? 'Milk',
                'opening_stock' => $openingStock,
                'display_order' => $index,
            ];
        }

        DB::table('trackers')->insert($rows);
    }

    private function trackerId(string $suffix): string
    {
        return $this->farmIdValue.'-'.$suffix;
    }

    /**
     * Milk solids follow the NZ dairy season: peak in spring, dry off in
     * winter. Deferred payout lands in one later month, which is why the real
     * report shows a single deferred figure rather than a monthly stream.
     */
    private function seedMilkProduction(): void
    {
        $rows = [];
        $trackerId = $this->trackerId('milk');
        $herd = 950;

        for ($year = self::FIRST_YEAR; $year <= self::lastYear(); $year++) {
            for ($month = 1; $month <= 12; $month++) {
                $seasonal = match (true) {
                    in_array($month, [9, 10, 11], true) => 100,
                    in_array($month, [8, 12], true) => 80,
                    in_array($month, [1, 2, 3], true) => 60,
                    in_array($month, [4, 5], true) => 30,
                    default => 0,
                };

                $rows[] = [
                    'tracker_id' => $trackerId,
                    'month' => sprintf('%04d-%02d-01', $year, $month),
                    'kg_ms_current' => intdiv($herd * self::KG_MS_PER_COW_PEAK * $seasonal, 100),
                    'kg_ms_deferred' => $month === 9 ? 13_000 : 0,
                ];
            }
        }

        DB::table('tracker_milk_production')->insert($rows);
    }

    /**
     * Livestock movements for the four stock-class trackers. Calving in
     * spring, culling in autumn, so herd size moves the way a real dairy
     * herd does.
     */
    private function seedStockMovements(): void
    {
        $rows = [];

        foreach (self::TRACKERS as [$suffix, , $type, , ]) {
            if ($type !== 'livestock') {
                continue;
            }

            $trackerId = $this->trackerId($suffix);
            $scale = match ($suffix) {
                'ma-cows' => 10,
                'r2-heifers' => 4,
                'bobby-calves' => 3,
                default => 1,
            };

            for ($year = self::FIRST_YEAR; $year <= self::lastYear(); $year++) {
                for ($month = 1; $month <= 12; $month++) {
                    $rows[] = [
                        'tracker_id' => $trackerId,
                        'month' => sprintf('%04d-%02d-01', $year, $month),
                        'births' => in_array($month, [8, 9], true) ? 12 * $scale : 0,
                        'purchases' => $month === 6 ? 2 * $scale : 0,
                        'sales' => in_array($month, [4, 5], true) ? 7 * $scale : 0,
                        'deaths' => 1 + ($month % 2),
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('tracker_stock_movements')->insert($chunk);
        }
    }

    /**
     * Journal lines, generated in DuckDB, one insert per year so each lands in
     * a single partition.
     *
     * Milk income is derived from the actual production rows rather than
     * invented, so the report's per-kg-MS figures are internally consistent —
     * income divided by kg MS lands near the payout rate instead of being an
     * arbitrary number.
     */
    private function seedJournals(): void
    {
        $fp = self::FIXED_POINT;

        foreach (self::ACCOUNTS as [$suffix, , $class, , , , , , $trackerSuffix]) {
            $accountId = 'gm-'.$suffix;
            $trackerId = $this->trackerId($trackerSuffix);

            if ($suffix === 'milk-current' || $suffix === 'milk-deferred') {
                $column = $suffix === 'milk-current' ? 'kg_ms_current' : 'kg_ms_deferred';
                $payout = self::PAYOUT_PER_KG_MS;

                $this->db->query(<<<SQL
                    INSERT INTO {$this->alias}.transaction_lines
                        (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id)
                    SELECT
                        '{$this->farmId()}', '{$this->farmType()}', '{$this->region()}',
                        '{$accountId}-' || strftime(mp.month, '%Y%m'),
                        '{$accountId}',
                        CASE WHEN mp.month <= DATE '{$this->horizonDate()}' THEN 'actuals' ELSE 'forecast' END,
                        'cash',
                        (mp.month + INTERVAL 14 DAY)::DATE,
                        -- Cast BEFORE multiplying: kg_ms x payout x 10,000
                        -- overflows INT32 (38,000 x 8 x 10,000 = 3.04e9), and
                        -- casting the product is too late.
                        -(CAST(mp.{$column} AS BIGINT) * {$payout} * {$fp}),
                        '{$trackerId}'
                    FROM {$this->appAlias}.tracker_milk_production mp
                    WHERE mp.tracker_id = '{$trackerId}' AND mp.{$column} > 0
                    SQL);

                continue;
            }

            $this->insertAccountLines($accountId, $trackerId, $class);
        }
    }

    /**
     * Lines for one non-milk account, across the whole span.
     *
     * At the demo scale this is a single line per month, which reads cleanly on
     * screen. With a bulk target it fans out into many lines per month while
     * holding the monthly TOTAL identical — the per-line amount is divided by
     * the fan-out factor. That matters: volume should change how much data the
     * report scans, not what it reports, so the same query over 600 rows and
     * over a million produces the same figures.
     */
    private function insertAccountLines(string $accountId, string $trackerId, string $class): void
    {
        $fp = self::FIXED_POINT;
        $signMult = $class === 'REVENUE' ? -1 : 1;
        $perMonth = $this->linesPerAccountMonth();
        $legs = $this->legValues($accountId, $class);

        // One insert per year, so each writes into exactly one partition.
        for ($year = self::FIRST_YEAR; $year <= self::lastYear(); $year++) {
            $this->db->query(<<<SQL
                INSERT INTO {$this->alias}.transaction_lines
                    (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id)
                SELECT
                    '{$this->farmId()}', '{$this->farmType()}', '{$this->region()}',
                    '{$accountId}-' || strftime(m.month_start, '%Y%m') || '-' || g.n || leg.id_suffix,
                    leg.account_id,
                    CASE WHEN m.month_start <= DATE '{$this->horizonDate()}' THEN 'actuals' ELSE 'forecast' END,
                    'cash',
                    -- Spread across the month so a line is a plausible
                    -- individual transaction rather than a monthly lump.
                    (m.month_start + INTERVAL (CAST(g.n % 28 AS INTEGER)) DAY)::DATE,
                    -- Division stays last so the report leg (multiple 1.0) is
                    -- bit-identical to what the farms without bookkeeping lines
                    -- hold. That identity is the correctness check: this farm's
                    -- report must match the 500M farm's figure for figure.
                    CAST({$signMult} * (({$fp} * ({$this->baseAmount($class)} + (month(m.month_start) * 400))) * leg.amount_multiple) / {$perMonth} AS BIGINT),
                    -- Only the report leg belongs to a tracker. Figured's
                    -- payable and bank lines carry an empty tracking array, and
                    -- a tracker here would put bookkeeping into a tracker's
                    -- per-unit margin.
                    CASE WHEN leg.id_suffix = '' THEN '{$trackerId}' ELSE NULL END
                FROM generate_series(
                    DATE '{$year}-01-01',
                    DATE '{$year}-12-01',
                    INTERVAL 1 MONTH
                ) AS m(month_start)
                CROSS JOIN range(0, {$perMonth}) AS g(n)
                CROSS JOIN ({$legs}) AS leg(account_id, id_suffix, amount_multiple, every_nth)
                WHERE g.n % leg.every_nth = 0
                SQL);
        }
    }

    private function baseAmount(string $class): int
    {
        return $class === 'REVENUE' ? 9_000 : 6_000;
    }

    /**
     * The legs each report line expands into, as a SQL VALUES list.
     *
     * Without `withNonReportLines` this is a single identity leg, so the
     * generated SQL keeps exactly the shape and output it had before the
     * bookkeeping lines existed — the other four farms are untouched.
     *
     * Revenue posts its counterparty to receivables rather than payables,
     * which costs nothing to get right and stops the data being obviously
     * fictional to anyone who opens it.
     */
    private function legValues(string $accountId, string $class): string
    {
        if (!$this->withNonReportLines) {
            return "VALUES ('{$accountId}', '', 1.0, 1)";
        }

        $rows = ["('{$accountId}', '', 1.0, 1)"];

        foreach (self::NON_REPORT_LEGS as [$suffix, $idSuffix, $multiple, $everyNth]) {
            if ($suffix === 'accounts-payable' && $class === 'REVENUE') {
                $suffix = 'accounts-receivable';
                $idSuffix = '-ar';
            }

            $rows[] = sprintf("('gm-%s', '%s', %s, %d)", $suffix, $idSuffix, $multiple, $everyNth);
        }

        return 'VALUES '.implode(', ', $rows);
    }

    /**
     * How many lines each non-milk account gets per month.
     *
     * Counts REPORT lines only. With `withNonReportLines` the farm ends up
     * with roughly 3.5x this many rows in total, because each report line
     * drags its bookkeeping legs along. That is deliberate: asking for 500M
     * means "the same report-relevant volume as the 500M farm", so the two are
     * an A/B on chaff rather than on useful data.
     *
     * Derived from the requested total rather than configured directly, so a
     * caller asks for "a million rows" and does not have to work out the
     * per-account arithmetic. Milk is excluded from the fan-out (one payout a
     * month), so the divisor counts only the accounts that do fan out.
     */
    private function linesPerAccountMonth(): int
    {
        if ($this->bulkRows < 1) {
            return 1;
        }

        $fannedAccounts = 0;
        foreach (self::ACCOUNTS as [$suffix]) {
            if ($suffix !== 'milk-current' && $suffix !== 'milk-deferred') {
                $fannedAccounts++;
            }
        }

        $months = self::YEARS * 12;

        return max(1, intdiv($this->bulkRows, $fannedAccounts * $months));
    }

    private function farmId(): string
    {
        return $this->farmIdValue;
    }

    private function farmType(): string
    {
        return self::FARM_TYPE;
    }

    private function region(): string
    {
        return $this->regionValue;
    }

    private function horizonDate(): string
    {
        return self::HORIZON_DATE;
    }

    private function firstYearValue(): int
    {
        return self::FIRST_YEAR;
    }

    private function lastYearValue(): int
    {
        return self::lastYear();
    }
}
