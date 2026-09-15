<?php

declare(strict_types=1);

namespace App\Services\AlloyDb;

use Illuminate\Database\ConnectionInterface;

/**
 * Loads a complete mixed milk + livestock dairy farm into AlloyDB.
 *
 * Self-contained on purpose: farms, accounts, trackers, milk production, stock
 * movements and journal lines are all generated here and all land in
 * PostgreSQL. Nothing is read from anywhere else.
 *
 * That independence IS the proposition under test. Production data — trackers,
 * milk records, stock movements — has no reason to live in a separate
 * relational database when the analytical store is itself a relational
 * database. One system holds the farm's operational records and reports on
 * them, and a report is an ordinary join rather than a federated one.
 *
 * Two loading strategies, for different reasons:
 *
 * - **Dimensions are built row by row from PHP.** A few hundred rows, and the
 *   seasonal curves read more clearly as PHP than as generated SQL.
 * - **Journal lines are generated inside Postgres**, from `generate_series`.
 *   Shipping 500M rows over a client connection would measure the client.
 *
 * The generated values mirror `GrossMarginV2Seeder` so the two engines can be
 * compared on equivalent data — report output has been verified identical
 * across four periods.
 */
final class AlloyDbSeeder
{
    private const int FIXED_POINT = 10_000;
    private const int FIRST_YEAR = 2024;
    private const int YEARS = 4;
    private const int KG_MS_PER_COW_PEAK = 40;
    private const int PAYOUT_PER_KG_MS = 8;
    private const int HERD = 950;
    private const string HORIZON_DATE = '2026-08-31';

    /**
     * Opening head, applied to EVERY tracker including the milk platform.
     *
     * Mirrors what the lake actually holds. The Gross Margin seeder gives each
     * tracker its own opening figure and only livestock any movements, but the
     * stock-movement seeder ran afterwards and overwrote both — so the data the
     * DuckDB report reads has 1,200 opening head on all five trackers and
     * movements for all five. Equivalence with the lake is what makes a timing
     * comparison mean anything, so this reproduces the lake rather than the
     * more principled intent.
     */
    private const int OPENING_STOCK = 1_200;

    /** [suffix, name, type, stock class] */
    private const array TRACKERS = [
        ['milk', 'Milk Platform', 'milk', ''],
        ['ma-cows', 'MA Cows', 'livestock', 'MA Cows'],
        ['r2-heifers', 'R2 Heifers', 'livestock', 'R2 Heifers'],
        ['bobby-calves', 'Bobby Calves', 'livestock', 'Bobby Calves'],
        ['bulls', 'Breeding Bulls', 'livestock', 'Breeding Bulls'],
    ];

    /** [suffix, name, class, category, group, group label, group order, line order, tracker] */
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
     * The bookkeeping legs each report line drags along, and the accounts they
     * post to. Mirrors `GrossMarginV2Seeder` exactly so the two engines are
     * measured on the same shape.
     *
     * [suffix, line-id suffix, amount multiple, every nth line]
     */
    private const array NON_REPORT_LEGS = [
        ['gst', '-gst', 0.15, 2],
        ['accounts-payable', '-ap', -1.15, 1],
        ['bank', '-bank', 1.15, 1],
    ];

    /** [suffix, name, class, category] — receivable substitutes for payable on revenue. */
    private const array NON_REPORT_ACCOUNTS = [
        ['gst', 'GST', 'LIABILITY', 'current_liability'],
        ['accounts-payable', 'Accounts Payable', 'LIABILITY', 'current_liability'],
        ['accounts-receivable', 'Accounts Receivable', 'ASSET', 'current_asset'],
        ['bank', 'Farm Current Account', 'ASSET', 'current_asset'],
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly string $farmId,
        private readonly string $region,
        private readonly int $bulkRows = 0,
        /** Emit the GST/payable/bank lines a real chart of accounts carries. */
        private readonly bool $withNonReportLines = false,
    ) {
    }

    /**
     * @return array{dimension_rows: int, fact_rows: int}
     */
    public function seed(): array
    {
        $dimensions = $this->seedDimensions();
        $this->db->statement('DELETE FROM transaction_lines WHERE farm_id = ?', [$this->farmId]);
        $facts = $this->seedJournals();

        return ['dimension_rows' => $dimensions, 'fact_rows' => $facts];
    }

    private function seedDimensions(): int
    {
        $count = 0;

        $this->db->table('farms')->upsert(
            [['farm_id' => $this->farmId, 'farm_type' => 'dairy', 'region' => $this->region]],
            ['farm_id']
        );
        $count++;

        foreach (self::ACCOUNTS as [$suffix, $name, $class, $category, $group, $label, $groupOrder, $lineOrder]) {
            $this->db->table('accounts')->upsert([[
                'account_id' => 'gm-'.$suffix,
                'account_name' => $name,
                'account_class' => $class,
                'account_category' => $category,
                'report_group' => $group,
                'report_group_label' => $label,
                'report_group_order' => $groupOrder,
                'line_order' => $lineOrder,
            ]], ['account_id']);
            $count++;
        }

        $trackerIds = array_map(
            fn (array $t): string => $this->trackerId($t[0]),
            self::TRACKERS
        );

        $this->db->table('tracker_milk_production')->whereIn('tracker_id', $trackerIds)->delete();
        $this->db->table('tracker_stock_movements')->whereIn('tracker_id', $trackerIds)->delete();

        foreach (self::TRACKERS as $order => [$suffix, $name, $type, $stockType]) {
            $this->db->table('trackers')->upsert([[
                'tracker_id' => $this->trackerId($suffix),
                'farm_id' => $this->farmId,
                'tracker_name' => $name,
                'tracker_type' => $type,
                'stock_type' => $stockType,
                'opening_stock' => self::OPENING_STOCK,
                'display_order' => $order,
            ]], ['tracker_id']);
            $count++;
        }

        if ($this->withNonReportLines) {
            foreach (self::NON_REPORT_ACCOUNTS as [$suffix, $name, $class, $category]) {
                $this->db->table('accounts')->upsert([[
                    'account_id' => 'gm-'.$suffix,
                    'account_name' => $name,
                    'account_class' => $class,
                    'account_category' => $category,
                    'report_group' => null,
                    'report_group_label' => null,
                    'report_group_order' => 0,
                    'line_order' => 0,
                ]], ['account_id']);
                $count++;
            }
        }

        $count += $this->seedMilkProduction();
        $count += $this->seedStockMovements();

        return $count;
    }

    private function seedMilkProduction(): int
    {
        $rows = [];
        $trackerId = $this->trackerId('milk');

        for ($year = self::FIRST_YEAR; $year <= self::lastYear(); $year++) {
            for ($month = 1; $month <= 12; $month++) {
                // Southern-hemisphere lactation curve: peak in spring, dry over
                // winter. Without the seasonality a per-unit margin would be
                // flat all year, which is not what a dairy report looks like.
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
                    'kg_ms_current' => intdiv(self::HERD * self::KG_MS_PER_COW_PEAK * $seasonal, 100),
                    'kg_ms_deferred' => $month === 9 ? 13_000 : 0,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->db->table('tracker_milk_production')->insert($chunk);
        }

        return count($rows);
    }

    /**
     * Movements for every tracker, including the milk platform — see
     * OPENING_STOCK for why the milk tracker carries head at all.
     *
     * `phase` rotates the seasonal amplitude per tracker and year so the
     * closing balances diverge between mobs instead of moving in lockstep,
     * which would make per-head margins indistinguishable.
     */
    private function seedStockMovements(): int
    {
        $rows = [];

        foreach (self::TRACKERS as $index => [$suffix]) {
            $trackerId = $this->trackerId($suffix);

            for ($year = self::FIRST_YEAR; $year <= self::lastYear(); $year++) {
                $phase = ($index + $year) % 3;

                for ($month = 1; $month <= 12; $month++) {
                    $rows[] = [
                        'tracker_id' => $trackerId,
                        'month' => sprintf('%04d-%02d-01', $year, $month),
                        'purchases' => $month === 7 ? 40 : 0,
                        'births' => in_array($month, [9, 10, 11], true) ? 120 + ($phase * 10) : 0,
                        'sales' => in_array($month, [3, 4, 5], true) ? 110 + ($phase * 10) : 0,
                        'deaths' => 3 + (($index + $month) % 4),
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->db->table('tracker_stock_movements')->insert($chunk);
        }

        return count($rows);
    }

    private function seedJournals(): int
    {
        $perMonth = $this->linesPerAccountMonth();

        foreach (self::ACCOUNTS as [$suffix, , $class, , , , , , $trackerSuffix]) {
            $accountId = 'gm-'.$suffix;
            $trackerId = $this->trackerId($trackerSuffix);

            if ($suffix === 'milk-current' || $suffix === 'milk-deferred') {
                $this->insertMilkLines(
                    $accountId,
                    $trackerId,
                    $suffix === 'milk-current' ? 'kg_ms_current' : 'kg_ms_deferred'
                );

                continue;
            }

            $this->insertAccountLines($accountId, $trackerId, $class, $perMonth);
        }

        $row = $this->db->selectOne(
            'SELECT count(*) AS n FROM transaction_lines WHERE farm_id = ?',
            [$this->farmId]
        );

        return (int) $row->n;
    }

    private function insertMilkLines(string $accountId, string $trackerId, string $column): void
    {
        $payout = self::PAYOUT_PER_KG_MS;
        $fp = self::FIXED_POINT;
        $horizon = self::HORIZON_DATE;

        $this->db->statement(<<<SQL
            INSERT INTO transaction_lines
                (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id)
            SELECT
                ?, 'dairy', ?,
                ? || to_char(mp.month, 'YYYYMM'),
                ?,
                CASE WHEN mp.month <= DATE '{$horizon}' THEN 'actuals' ELSE 'forecast' END,
                'cash',
                (mp.month + INTERVAL '14 days')::DATE,
                -- Cast before multiplying: kg_ms x payout x 10,000 exceeds a
                -- 32-bit integer at realistic production volumes.
                -(mp.{$column}::BIGINT * {$payout} * {$fp}),
                ?
            FROM tracker_milk_production mp
            WHERE mp.tracker_id = ? AND mp.{$column} > 0
            SQL, [$this->farmId, $this->region, $accountId.'-', $accountId, $trackerId, $trackerId]);
    }

    private function insertAccountLines(string $accountId, string $trackerId, string $class, int $perMonth): void
    {
        $fp = self::FIXED_POINT;
        $signMult = $class === 'REVENUE' ? -1 : 1;
        $base = $class === 'REVENUE' ? 9_000 : 6_000;
        $horizon = self::HORIZON_DATE;
        $legs = $this->legValues($accountId, $class);

        // One statement per year, so no single insert has to materialise the
        // whole span at bulk volumes.
        for ($year = self::FIRST_YEAR; $year <= self::lastYear(); $year++) {
            $this->db->statement(<<<SQL
                INSERT INTO transaction_lines
                    (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id)
                SELECT
                    ?, 'dairy', ?,
                    ? || to_char(m.month_start, 'YYYYMM') || '-' || g.n::TEXT || leg.id_suffix,
                    leg.account_id,
                    CASE WHEN m.month_start <= DATE '{$horizon}' THEN 'actuals' ELSE 'forecast' END,
                    'cash',
                    -- Spread across the month so a line reads as an individual
                    -- transaction rather than a monthly lump.
                    (m.month_start + ((g.n % 28) * INTERVAL '1 day'))::DATE,
                    -- NUMERIC then ROUND, deliberately. Postgres `/` on two
                    -- integers is integer division and truncates, where DuckDB
                    -- divides truly and its CAST to BIGINT rounds. Left as
                    -- integer arithmetic this lands one cent low on every line.
                    -- The multiple is applied before the division so the report
                    -- leg (1.0) stays bit-identical to a farm seeded without
                    -- bookkeeping lines.
                    ({$signMult} * ROUND(({$base} + (EXTRACT(MONTH FROM m.month_start)::INT * 400))::NUMERIC * {$fp} * leg.amount_multiple / {$perMonth}))::BIGINT,
                    -- Only the report leg belongs to a tracker; Figured's
                    -- payable and bank lines carry an empty tracking array.
                    CASE WHEN leg.id_suffix = '' THEN ?::TEXT ELSE NULL END
                FROM generate_series(
                    DATE '{$year}-01-01',
                    DATE '{$year}-12-01',
                    INTERVAL '1 month'
                ) AS m(month_start)
                CROSS JOIN generate_series(0, {$perMonth} - 1) AS g(n)
                CROSS JOIN (VALUES {$legs}) AS leg(account_id, id_suffix, amount_multiple, every_nth)
                WHERE g.n % leg.every_nth = 0
                SQL, [$this->farmId, $this->region, $accountId.'-', $trackerId]);
        }
    }

    /**
     * The legs each report line expands into, as a Postgres VALUES list.
     *
     * Without `withNonReportLines` this is a single identity leg, so the farms
     * seeded before the bookkeeping lines existed keep exactly the shape and
     * output they had.
     */
    private function legValues(string $accountId, string $class): string
    {
        if (!$this->withNonReportLines) {
            return "('{$accountId}', '', 1.0, 1)";
        }

        $rows = ["('{$accountId}', '', 1.0, 1)"];

        foreach (self::NON_REPORT_LEGS as [$suffix, $idSuffix, $multiple, $everyNth]) {
            if ($suffix === 'accounts-payable' && $class === 'REVENUE') {
                $suffix = 'accounts-receivable';
                $idSuffix = '-ar';
            }

            $rows[] = sprintf("('gm-%s', '%s', %s, %d)", $suffix, $idSuffix, $multiple, $everyNth);
        }

        return implode(', ', $rows);
    }

    /**
     * Fan-out per account-month, derived from the requested total.
     *
     * Volume changes how much data the report scans, never what it reports: the
     * per-line amount is divided by the fan-out, so the monthly total holds
     * constant whether an account has one line a month or a thousand.
     */
    private function linesPerAccountMonth(): int
    {
        if ($this->bulkRows < 1) {
            return 1;
        }

        // The two milk accounts come from the production table, not the fan-out.
        $fanned = count(self::ACCOUNTS) - 2;

        return max(1, intdiv($this->bulkRows, $fanned * self::YEARS * 12));
    }

    private function trackerId(string $suffix): string
    {
        return $this->farmId.'-'.$suffix;
    }

    private static function lastYear(): int
    {
        return self::FIRST_YEAR + self::YEARS - 1;
    }
}
