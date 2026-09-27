<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;

/**
 * A farm shaped like the one Figured's Cash Flow benchmark was run on: a
 * New Zealand dairy farm with a May balance date, thirteen milk trackers,
 * two livestock trackers, an overdraft, two-monthly GST and end-of-year
 * adjustments, with actuals to 30 June 2026 and forecast to 31 May 2027.
 *
 * Four seasons of history so the opening balance and the GST windows have
 * something to read, and a `--lines` target that fans each account-month
 * out into as many lines as it takes while holding the monthly totals — so
 * the same report at 10K and at 5M lines produces the same figures.
 *
 * Milk income before the horizon is a real journal derived from production
 * x payout, exactly as the report's virtual journal derives it after the
 * horizon. That is deliberate: the seam between actual and virtual milk
 * income should be invisible in the report, and this is the data that
 * proves it.
 *
 * Amounts are hashes of the row's own coordinates, so a reseed reproduces
 * them exactly.
 */
final class CashFlowActualsForecastFarmSeeder
{
    public const string FARM_ID = 'cfaf-dairy-nz';
    public const string REGION = 'cfaf-dairy';
    public const int PERIOD_YEAR = 2027;
    public const string HORIZON = '2026-06-30';

    private const string FIRST_MONTH = '2023-06-01';
    private const string LAST_MONTH = '2027-05-01';
    private const int MONTHS = 48;
    private const int FIXED_POINT = 10000;

    /** Rows per INSERT, the size the 500M-row hero farm was seeded in. */
    private const int INSERT_CHUNK_ROWS = 25_000_000;

    private const string MILK_SALES = 'cfaf-milk-sales';
    private const string BANK = 'cfaf-bank';
    private const string GST = 'cfaf-gst';
    private const string WAGES = 'cfaf-wages';
    private const string MACHINERY = 'cfaf-machinery';

    /** [suffix, name, herd] — the herd sizes the production and costs scale with. */
    private const array MILK_TRACKERS = [
        ['home', 'Milk — Home Farm', 620],
        ['runoff', 'Milk — Runoff', 310],
        ['north', 'Milk — North Block', 480],
        ['south', 'Milk — South Block', 450],
        ['river', 'Milk — River Flats', 390],
        ['hill', 'Milk — Hill Block', 270],
        ['east', 'Milk — East Block', 520],
        ['west', 'Milk — West Block', 410],
        ['lease-a', 'Milk — Lease A', 350],
        ['lease-b', 'Milk — Lease B', 330],
        ['heifer', 'Milk — Heifer Platform', 240],
        ['organic', 'Milk — Organic Unit', 180],
        ['winter', 'Milk — Winter Milk', 290],
    ];

    /** [suffix, name, stock type, opening head] */
    private const array STOCK_TRACKERS = [
        ['ma-cows', 'MA Cows', 'MA Cows', 4800],
        ['r2-heifers', 'R2 Heifers', 'R2 Heifers', 1100],
    ];

    /** [id, name, class, category, type, system, is gst, default bank] */
    private const array ACCOUNTS = [
        [self::MILK_SALES, 'Milk Sales', 'REVENUE', 'other_income'],
        ['cfaf-shed-costs', 'Dairy Shed Expenses', 'EXPENSE', 'direct_costs'],
        ['cfaf-animal-health', 'Animal Health', 'EXPENSE', 'direct_costs'],
        ['cfaf-stock-sales', 'Livestock Sales', 'REVENUE', 'other_income'],
        ['cfaf-stock-purchases', 'Livestock Purchases', 'EXPENSE', 'direct_costs'],
        ['cfaf-grazing', 'Grazing', 'EXPENSE', 'direct_costs'],
        ['cfaf-rebates', 'Rebates & Sundry Income', 'REVENUE', 'other_income'],
        ['cfaf-fertiliser', 'Fertiliser', 'EXPENSE', 'direct_costs'],
        ['cfaf-feed', 'Supplementary Feed', 'EXPENSE', 'direct_costs'],
        [self::WAGES, 'Wages', 'EXPENSE', 'operating_expenses'],
        ['cfaf-repairs', 'Repairs & Maintenance', 'EXPENSE', 'operating_expenses'],
        ['cfaf-admin', 'Administration', 'EXPENSE', 'operating_expenses'],
        ['cfaf-electricity', 'Electricity', 'EXPENSE', 'operating_expenses'],
        ['cfaf-insurance', 'Insurance', 'EXPENSE', 'operating_expenses'],
        ['cfaf-interest-received', 'Interest Received', 'REVENUE', 'non_operating_income'],
        ['cfaf-interest-paid', 'Interest Paid', 'EXPENSE', 'non_operating_expenses'],
        ['cfaf-od-interest', 'Overdraft Interest', 'EXPENSE', 'non_operating_expenses', null, 'OVERDRAFT'],
        [self::MACHINERY, 'Plant & Machinery', 'ASSET', 'non_operating_movements'],
        ['cfaf-term-loan', 'Term Loan', 'LIABILITY', 'non_operating_movements'],
        ['cfaf-drawings', 'Drawings', 'EQUITY', 'equity_movements'],
        [self::GST, 'GST', 'LIABILITY', 'gst', null, 'GST', true],
        ['cfaf-gst-payments', 'GST Payments / Refunds', 'LIABILITY', 'gst', null, 'GSTPAYMENTS'],
        [self::BANK, 'Farm Cheque', 'ASSET', 'current_asset', 'BANK', null, false, true],
    ];

    /** [account, monthly dollars, day of month] for lines belonging to no tracker. */
    private const array FARM_PLAN = [
        ['cfaf-rebates', 6_000, 12],
        ['cfaf-fertiliser', 60_000, 6],
        ['cfaf-feed', 150_000, 9],
        [self::WAGES, 120_000, 20],
        ['cfaf-repairs', 35_000, 14],
        ['cfaf-admin', 12_000, 3],
        ['cfaf-electricity', 20_000, 22],
        ['cfaf-insurance', 8_000, 1],
        ['cfaf-interest-received', 1_500, 28],
        ['cfaf-interest-paid', 45_000, 28],
        ['cfaf-term-loan', 30_000, 5],
        ['cfaf-drawings', 40_000, 15],
    ];

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
        private readonly string $appAlias,
        private readonly int $milkTrackerCount = 13,
        private readonly int $stockTrackerCount = 2,
        private readonly string $farmId = self::FARM_ID,
    ) {
    }

    /**
     * @return array{lines: int, lines_per_slot: int, trackers: int}
     */
    public function seed(int $targetLines): array
    {
        $this->clear();
        $this->seedFarm();
        $this->seedAccounts();
        $this->seedTrackers();
        $this->seedMilkProductionAndRates();

        $plan = $this->linePlan();
        $perSlot = max(1, (int) ceil($targetLines / (count($plan) * self::MONTHS)));

        $this->seedMilkActualIncome();
        $this->seedPlannedLines($plan, $perSlot);
        $this->seedGst();
        $this->seedOneOffs();

        $n = 0;
        foreach ($this->db->query("SELECT count(*) AS n FROM {$this->alias}.transaction_lines WHERE farm_id = '".$this->farmId."'")->rows(true) as $r) {
            $n = (int) (string) $r['n'];
        }

        return ['lines' => $n, 'lines_per_slot' => $perSlot, 'trackers' => count($this->milkTrackers()) + count($this->stockTrackers())];
    }

    private function clear(): void
    {
        $this->db->query("DELETE FROM {$this->alias}.transaction_lines WHERE farm_id = '".$this->farmId."'");

        $trackerIds = DB::table('trackers')->where('farm_id', $this->farmId)->pluck('tracker_id')->all();
        DB::table('milk_payout_rates')->whereIn('tracker_id', $trackerIds)->delete();
        DB::table('tracker_milk_production')->whereIn('tracker_id', $trackerIds)->delete();
        DB::table('trackers')->where('farm_id', $this->farmId)->delete();

        foreach (['overdrafts', 'gst_settings'] as $table) {
            DB::table($table)->where('farm_id', $this->farmId)->delete();
        }
        DB::table('accounts')->where('farm_id', $this->farmId)->delete();
        DB::table('farms')->where('farm_id', $this->farmId)->delete();
    }

    private function seedFarm(): void
    {
        DB::table('farms')->insert([
            'farm_id' => $this->farmId,
            'farm_type' => 'dairy',
            'region' => self::REGION,
            'opening_balance' => 0,
            'financial_year_end_month' => 5,
            'country_code' => 'NZ',
        ]);

        DB::table('gst_settings')->insert([
            'farm_id' => $this->farmId,
            'sales_tax_period' => 'TWOMONTHS',
            'sales_tax_basis' => 'PAYMENTS',
        ]);

        DB::table('overdrafts')->insert([
            'farm_id' => $this->farmId,
            'rate' => 85000,
            'overdraft_limit' => 1_500_000 * self::FIXED_POINT,
            'start_date' => self::FIRST_MONTH,
            'payment_term' => 'interest_only_monthly',
        ]);
    }

    private function seedAccounts(): void
    {
        DB::table('accounts')->insert(array_map(
            fn (array $a): array => [
                'account_id' => $this->accountId($a[0]),
                'farm_id' => $this->farmId,
                'account_name' => $a[1],
                'account_class' => $a[2],
                'account_category' => $a[3],
                'account_type' => $a[4] ?? null,
                'system_account' => $a[5] ?? null,
                'mapped_to_account_id' => null,
                'inverted_for_user' => false,
                'report_group' => null,
                'report_group_label' => null,
                'report_group_order' => 0,
                'line_order' => 0,
                'is_gst_account' => $a[6] ?? false,
                'is_default_bank_account' => $a[7] ?? false,
            ],
            self::ACCOUNTS,
        ));
    }

    private function seedTrackers(): void
    {
        $rows = [];
        $order = 0;

        foreach ($this->milkTrackers() as [$suffix, $name]) {
            $rows[] = [
                'tracker_id' => $this->trackerId($suffix),
                'farm_id' => $this->farmId,
                'tracker_name' => $name,
                'tracker_type' => 'milk',
                'stock_type' => 'Milk',
                'income_account_id' => $this->accountId(self::MILK_SALES),
                'opening_stock' => 0,
                'display_order' => $order++,
            ];
        }

        foreach ($this->stockTrackers() as [$suffix, $name, $stockType, $opening]) {
            $rows[] = [
                'tracker_id' => $this->trackerId($suffix),
                'farm_id' => $this->farmId,
                'tracker_name' => $name,
                'tracker_type' => 'livestock',
                'stock_type' => $stockType,
                'income_account_id' => null,
                'opening_stock' => $opening,
                'display_order' => $order++,
            ];
        }

        DB::table('trackers')->insert($rows);
    }

    /**
     * NZ season: dry in June, peak September to November, tailing off to
     * May. Deferred payout lands in October. Payout steps up each season
     * and again after December, so a per-month rate table is genuinely
     * exercised rather than a constant in disguise.
     */
    private function seedMilkProductionAndRates(): void
    {
        $production = [];
        $rates = [];

        foreach ($this->milkTrackers() as [$suffix, , $herd]) {
            $trackerId = $this->trackerId($suffix);

            for ($i = 0; $i < self::MONTHS; $i++) {
                $month = date('Y-m-01', strtotime(self::FIRST_MONTH." +{$i} month"));
                $m = (int) substr($month, 5, 2);
                $season = (int) substr($month, 0, 4) - ($m <= 5 ? 1 : 0);

                $seasonal = match (true) {
                    $m === 6 => 0,
                    $m === 7 => 20,
                    $m === 8 => 80,
                    in_array($m, [9, 10, 11], true) => 100,
                    $m === 12 => 80,
                    in_array($m, [1, 2, 3], true) => 60,
                    default => 30,
                };

                $production[] = [
                    'tracker_id' => $trackerId,
                    'month' => $month,
                    'kg_ms_current' => intdiv($herd * 40 * $seasonal, 100),
                    'kg_ms_deferred' => $m === 10 ? $herd * 15 : 0,
                ];

                $base = 6.00 + 0.5 * ($season - 2023);
                $rates[] = [
                    'tracker_id' => $trackerId,
                    'month' => $month,
                    'advance_rate' => (int) round(($base + ($m >= 6 && $m <= 11 ? 0 : 0.5)) * self::FIXED_POINT),
                    'deferred_rate' => (int) round(1.5 * self::FIXED_POINT),
                ];
            }
        }

        foreach (array_chunk($production, 500) as $chunk) {
            DB::table('tracker_milk_production')->insert($chunk);
        }
        foreach (array_chunk($rates, 500) as $chunk) {
            DB::table('milk_payout_rates')->insert($chunk);
        }
    }

    /**
     * Milk payments dated on or before the horizon, as real journals. The
     * same production x rate the report's virtual journal applies after it.
     */
    private function seedMilkActualIncome(): void
    {
        $f = $this->farmId;
        $r = self::REGION;
        $h = self::HORIZON;
        $acc = $this->accountId(self::MILK_SALES);

        $this->db->query(<<<SQL
            INSERT INTO {$this->alias}.transaction_lines
                (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id, tag)
            SELECT
                '{$f}', 'dairy', '{$r}',
                'cfaf-milk-' || t.tracker_id || '-' || strftime(mp.month, '%Y%m'),
                '{$acc}', 'actuals', 'cash',
                (mp.month + INTERVAL 1 MONTH + INTERVAL 19 DAY)::DATE,
                -(CAST(mp.kg_ms_current AS BIGINT) * r.advance_rate + CAST(mp.kg_ms_deferred AS BIGINT) * r.deferred_rate),
                t.tracker_id, NULL
            FROM {$this->appAlias}.trackers t
            JOIN {$this->appAlias}.tracker_milk_production mp ON mp.tracker_id = t.tracker_id
            JOIN {$this->appAlias}.milk_payout_rates r ON r.tracker_id = t.tracker_id AND r.month = mp.month
            WHERE t.farm_id = '{$f}' AND t.tracker_type = 'milk'
              AND (mp.kg_ms_current > 0 OR mp.kg_ms_deferred > 0)
              AND (mp.month + INTERVAL 1 MONTH + INTERVAL 19 DAY)::DATE <= DATE '{$h}'
            SQL);
    }

    /**
     * One slot per (account, tracker) that posts every month.
     *
     * @return list<array{0: string, 1: ?string, 2: int, 3: int}> [account, tracker, monthly dollars, day]
     */
    private function linePlan(): array
    {
        $plan = [];

        foreach ($this->milkTrackers() as [$suffix, , $herd]) {
            $plan[] = ['cfaf-shed-costs', $this->trackerId($suffix), intdiv(12_000 * $herd, 500), 8];
            $plan[] = ['cfaf-animal-health', $this->trackerId($suffix), intdiv(4_000 * $herd, 500), 16];
        }

        foreach ($this->stockTrackers() as [$suffix, , , $head]) {
            $plan[] = ['cfaf-stock-sales', $this->trackerId($suffix), intdiv(2_500 * $head, 1000), 11];
            $plan[] = ['cfaf-stock-purchases', $this->trackerId($suffix), intdiv(800 * $head, 1000), 19];
            $plan[] = ['cfaf-grazing', $this->trackerId($suffix), intdiv(3_000 * $head, 1000), 24];
        }

        foreach (self::FARM_PLAN as [$account, $monthly, $day]) {
            $plan[] = [$account, null, $monthly, $day];
        }

        return $plan;
    }

    /**
     * The bulk. Each slot's monthly figure is split across `$perSlot` lines
     * with a hash for noise, one insert per season so each lands in one
     * partition. Revenue is credit-negative, so the sign comes from the
     * account's class.
     *
     * @param list<array{0: string, 1: ?string, 2: int, 3: int}> $plan
     */
    private function seedPlannedLines(array $plan, int $perSlot): void
    {
        $classByAccount = [];
        foreach (self::ACCOUNTS as $a) {
            $classByAccount[$a[0]] = $a[2];
        }

        $values = [];
        foreach ($plan as [$account, $tracker, $monthly, $day]) {
            $values[] = sprintf(
                "('%s', %s, %d, %d, %d)",
                $this->accountId($account),
                $tracker === null ? 'NULL' : "'{$tracker}'",
                $classByAccount[$account] === 'REVENUE' ? -1 : 1,
                $monthly,
                $day,
            );
        }
        $planValues = implode(', ', $values);

        $f = $this->farmId;
        $r = self::REGION;
        $h = self::HORIZON;
        $fp = self::FIXED_POINT;

        $perInsert = max(1, intdiv(self::INSERT_CHUNK_ROWS, 12 * count($plan)));

        foreach ([['2023-06-01', '2024-05-01'], ['2024-06-01', '2025-05-01'], ['2025-06-01', '2026-05-01'], ['2026-06-01', '2027-05-01']] as [$from, $to]) {
            for ($first = 1; $first <= $perSlot; $first += $perInsert) {
                $last = min($perSlot, $first + $perInsert - 1);
                $this->db->query(<<<SQL
                INSERT INTO {$this->alias}.transaction_lines
                    (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id, tag)
                SELECT
                    '{$f}', 'dairy', '{$r}',
                    p.account_id || '-' || COALESCE(p.tracker_id, 'farm') || '-' || strftime(g.ms, '%Y%m') || '-' || n.i,
                    p.account_id,
                    CASE WHEN (g.ms + INTERVAL (p.day - 1) DAY)::DATE <= DATE '{$h}' THEN 'actuals' ELSE 'forecast' END,
                    'cash',
                    (g.ms + INTERVAL (p.day - 1) DAY)::DATE,
                    CAST(round(
                        p.sign * p.monthly * {$fp}
                        * (0.8 + CAST(hash(p.account_id || COALESCE(p.tracker_id, '') || strftime(g.ms, '%Y%m') || n.i) % 41 AS DOUBLE) / 100.0)
                        / {$perSlot}
                    ) AS BIGINT),
                    p.tracker_id, NULL
                FROM generate_series(DATE '{$from}', DATE '{$to}', INTERVAL 1 MONTH) AS g(ms)
                CROSS JOIN (VALUES {$planValues}) AS p(account_id, tracker_id, sign, monthly, day)
                CROSS JOIN generate_series({$first}, {$last}) AS n(i)
                SQL);
            }
        }
    }

    /**
     * Net GST owed on the 28th of every month, and — before the horizon —
     * the actual settlement of each two-monthly window on its due date,
     * tagged as a payment. Both are the same hash, so the settlement is
     * exactly the window's net and the handler's reversal nets to zero.
     */
    private function seedGst(): void
    {
        $f = $this->farmId;
        $r = self::REGION;
        $h = self::HORIZON;
        $fp = self::FIXED_POINT;
        $gst = $this->accountId(self::GST);
        $first = self::FIRST_MONTH;
        $last = self::LAST_MONTH;
        $tag = CashFlowActualsForecastSqlBuilder::TAG_GST_PAYMENT;

        $this->db->query(<<<SQL
            INSERT INTO {$this->alias}.transaction_lines
                (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id, tag)
            SELECT
                '{$f}', 'dairy', '{$r}', 'cfaf-gst-' || strftime(ms, '%Y%m'), '{$gst}',
                CASE WHEN (ms + INTERVAL 27 DAY)::DATE <= DATE '{$h}' THEN 'actuals' ELSE 'forecast' END,
                'cash', (ms + INTERVAL 27 DAY)::DATE,
                -(40000 + CAST(hash('gst' || strftime(ms, '%Y%m')) % 30000 AS BIGINT)) * {$fp},
                NULL, NULL
            FROM generate_series(DATE '{$first}', DATE '{$last}', INTERVAL 1 MONTH) AS g(ms)
            UNION ALL
            SELECT
                '{$f}', 'dairy', '{$r}', 'cfaf-gst-settle-' || strftime(d.ms, '%Y%m'), '{$gst}',
                'actuals', 'cash', d.pay_date,
                (SELECT SUM(40000 + CAST(hash('gst' || strftime(w.ms, '%Y%m')) % 30000 AS BIGINT)) * {$fp}
                 FROM generate_series((d.ms - INTERVAL 2 MONTH)::DATE, (d.ms - INTERVAL 1 MONTH)::DATE, INTERVAL 1 MONTH) AS w(ms)),
                NULL, '{$tag}'
            FROM (
                SELECT
                    g.ms,
                    CASE
                        WHEN month(g.ms) = 12 THEN make_date(year(g.ms) + 1, 1, 15)
                        WHEN month(g.ms) = 4  THEN make_date(year(g.ms), 5, 7)
                        ELSE make_date(year(g.ms), month(g.ms), 28)
                    END AS pay_date
                FROM generate_series(DATE '{$first}', DATE '{$last}', INTERVAL 1 MONTH) AS g(ms)
                WHERE month(g.ms) % 2 = 0 AND g.ms >= DATE '2023-08-01'
            ) d
            WHERE d.pay_date <= DATE '{$h}'
            SQL);
    }

    /**
     * The opening bank position, the end-of-year adjustment each May, and
     * a forecast machinery purchase sized to put the farm into overdraft
     * through the spring before the milk cheques arrive.
     */
    private function seedOneOffs(): void
    {
        $f = $this->farmId;
        $r = self::REGION;
        $fp = self::FIXED_POINT;
        $eoy = CashFlowActualsForecastSqlBuilder::TAG_EOY_MANUAL;

        $rows = [
            ['cfaf-bank-open', self::BANK, 'actuals', '2023-06-01', 250_000 * $fp, 'NULL'],
            ['cfaf-eoy-2024', self::WAGES, 'actuals', '2024-05-31', 45_000 * $fp, "'{$eoy}'"],
            ['cfaf-eoy-2025', self::WAGES, 'actuals', '2025-05-31', 45_000 * $fp, "'{$eoy}'"],
            ['cfaf-eoy-2026', self::WAGES, 'actuals', '2026-05-31', 45_000 * $fp, "'{$eoy}'"],
            ['cfaf-eoy-2027', self::WAGES, 'forecast', '2027-05-31', 45_000 * $fp, "'{$eoy}'"],
            ['cfaf-tractor', self::MACHINERY, 'forecast', '2026-09-15', 180_000 * $fp, 'NULL'],
        ];

        $values = [];
        foreach ($rows as [$id, $account, $type, $date, $amount, $tag]) {
            $values[] = "('{$f}', 'dairy', '{$r}', '{$id}', '{$this->accountId($account)}', '{$type}', 'cash', DATE '{$date}', {$amount}, NULL, {$tag})";
        }

        $this->db->query(sprintf(
            'INSERT INTO %s.transaction_lines'
            .' (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id, tag)'
            .' VALUES %s',
            $this->alias,
            implode(', ', $values),
        ));
    }

    /**
     * The thirteen named trackers, then as many generated blocks as the
     * count asks for. Herd sizes cycle on a prime longer than any sensible
     * count, so no two generated blocks produce the same curve.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function milkTrackers(): array
    {
        $trackers = array_slice(self::MILK_TRACKERS, 0, $this->milkTrackerCount);

        for ($i = count($trackers) + 1; $i <= $this->milkTrackerCount; $i++) {
            $trackers[] = [sprintf('block-%03d', $i), sprintf('Milk — Block %03d', $i), 180 + ($i * 37) % 461];
        }

        return $trackers;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private function stockTrackers(): array
    {
        $trackers = array_slice(self::STOCK_TRACKERS, 0, $this->stockTrackerCount);

        for ($i = count($trackers) + 1; $i <= $this->stockTrackerCount; $i++) {
            $trackers[] = [sprintf('herd-%03d', $i), sprintf('Herd %03d', $i), 'MA Cows', 600 + ($i * 211) % 1733];
        }

        return $trackers;
    }

    /**
     * `accounts.account_id` is a global key, so a second farm from this
     * seeder needs ids of its own. The default farm keeps the ids it has
     * always had, which keeps its earlier measurements reproducible.
     */
    private function accountId(string $id): string
    {
        if ($this->farmId === self::FARM_ID) {
            return $id;
        }

        return $this->farmId.'-'.substr($id, strlen('cfaf-'));
    }

    private function trackerId(string $suffix): string
    {
        return $this->farmId.'-'.$suffix;
    }
}
