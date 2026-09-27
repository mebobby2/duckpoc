<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;

/**
 * A hand-computable farm for the actuals-plus-forecast Cash Flow.
 *
 * Every mechanism the statement contains has one line here that exercises
 * it, sized so the expected cell can be worked out on paper (the expected
 * grid is in `DuckDbCashFlowAfRunCommand`):
 *
 * - a May balance date, so `period=2027` is 2026-06-01 .. 2027-05-31 and the
 *   GST calendar has both exception months;
 * - a milk tracker with an actual June payment, production whose payment
 *   falls after the horizon (virtual), a deferred payout, and May production
 *   whose June payment is before the horizon (must NOT be synthesised);
 * - a livestock tracker, so the rollup sums two trackers;
 * - one line in every farm section, with a machinery purchase large enough
 *   to send the balance negative in November;
 * - a net GST line either side of the horizon and an actual settlement;
 * - an end-of-year adjustment that `exclude_eoy_journals` must drop;
 * - an opening bank line the day before the period.
 *
 * The overdraft is not configured by default; `configureOverdraft()` adds
 * one so the recurrence can be checked as a second pass.
 */
final class CashFlowActualsForecastOracleSeeder
{
    public const string FARM_ID = 'cfaf-oracle-farm';
    public const string REGION = 'cfaf-oracle';
    public const int PERIOD_YEAR = 2027;
    public const string HORIZON = '2026-06-30';

    public const string MILK_TRACKER = 'cfaf-oracle-milk';
    public const string STOCK_TRACKER = 'cfaf-oracle-cows';

    public const string MILK = 'cfafo-milk';
    public const string SHED = 'cfafo-shed';
    public const string STOCK_SALES = 'cfafo-stock-sales';
    public const string GRAZING = 'cfafo-grazing';
    public const string OTHER_INCOME = 'cfafo-other';
    public const string FERTILISER = 'cfafo-fert';
    public const string WAGES = 'cfafo-wages';
    public const string INTEREST_RECEIVED = 'cfafo-int-recv';
    public const string INTEREST_PAID = 'cfafo-int-paid';
    public const string OD_INTEREST = 'cfafo-od-interest';
    public const string MACHINERY = 'cfafo-machinery';
    public const string LOAN = 'cfafo-loan';
    public const string DRAWINGS = 'cfafo-drawings';
    public const string GST = 'cfafo-gst';
    public const string GST_PAYMENTS = 'cfafo-gst-payments';
    public const string BANK = 'cfafo-bank';

    /** Annual percentage x 10,000: 5%, the rate Figured's own overdraft oracle uses. */
    public const int OVERDRAFT_RATE = 50000;
    public const int OVERDRAFT_LIMIT_DOLLARS = 1000;

    private const int FIXED_POINT = 10000;

    /** @var list<string> */
    private const array ACCOUNT_IDS = [
        self::MILK, self::SHED, self::STOCK_SALES, self::GRAZING, self::OTHER_INCOME, self::FERTILISER,
        self::WAGES, self::INTEREST_RECEIVED, self::INTEREST_PAID, self::OD_INTEREST, self::MACHINERY,
        self::LOAN, self::DRAWINGS, self::GST, self::GST_PAYMENTS, self::BANK,
    ];

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
    ) {
    }

    public function seed(): int
    {
        $this->clear();
        $this->seedFarm();
        $this->seedAccounts();
        $this->seedTrackers();
        $this->seedMilk();

        return $this->seedTransactionLines();
    }

    public function configureOverdraft(): void
    {
        DB::table('overdrafts')->where('farm_id', self::FARM_ID)->delete();
        DB::table('overdrafts')->insert([
            'farm_id' => self::FARM_ID,
            'rate' => self::OVERDRAFT_RATE,
            'overdraft_limit' => self::OVERDRAFT_LIMIT_DOLLARS * self::FIXED_POINT,
            'start_date' => '2026-06-01',
            'payment_term' => 'interest_only_monthly',
        ]);
    }

    public function removeOverdraft(): void
    {
        DB::table('overdrafts')->where('farm_id', self::FARM_ID)->delete();
    }

    private function clear(): void
    {
        $this->db->query("DELETE FROM {$this->alias}.transaction_lines WHERE farm_id = '".self::FARM_ID."'");

        $trackerIds = [self::MILK_TRACKER, self::STOCK_TRACKER];
        DB::table('milk_payout_rates')->whereIn('tracker_id', $trackerIds)->delete();
        DB::table('tracker_milk_production')->whereIn('tracker_id', $trackerIds)->delete();
        DB::table('trackers')->whereIn('tracker_id', $trackerIds)->delete();
        DB::table('overdrafts')->where('farm_id', self::FARM_ID)->delete();
        DB::table('gst_settings')->where('farm_id', self::FARM_ID)->delete();
        DB::table('accounts')->whereIn('account_id', self::ACCOUNT_IDS)->delete();
        DB::table('farms')->where('farm_id', self::FARM_ID)->delete();
    }

    private function seedFarm(): void
    {
        DB::table('farms')->insert([
            'farm_id' => self::FARM_ID,
            'farm_type' => 'dairy',
            'region' => self::REGION,
            'opening_balance' => 0,
            'financial_year_end_month' => 5,
            'country_code' => 'NZ',
        ]);

        DB::table('gst_settings')->insert([
            'farm_id' => self::FARM_ID,
            'sales_tax_period' => 'TWOMONTHS',
            'sales_tax_basis' => 'PAYMENTS',
        ]);
    }

    private function seedAccounts(): void
    {
        DB::table('accounts')->insert(array_map(
            static fn (array $a): array => self::account(...$a),
            [
                [self::MILK, 'Milk Sales', 'REVENUE', 'other_income'],
                [self::SHED, 'Dairy Shed Expenses', 'EXPENSE', 'direct_costs'],
                [self::STOCK_SALES, 'Livestock Sales', 'REVENUE', 'other_income'],
                [self::GRAZING, 'Grazing', 'EXPENSE', 'direct_costs'],
                [self::OTHER_INCOME, 'Rebates', 'REVENUE', 'other_income'],
                [self::FERTILISER, 'Fertiliser', 'EXPENSE', 'direct_costs'],
                [self::WAGES, 'Wages', 'EXPENSE', 'operating_expenses'],
                [self::INTEREST_RECEIVED, 'Interest Received', 'REVENUE', 'non_operating_income'],
                [self::INTEREST_PAID, 'Interest Paid', 'EXPENSE', 'non_operating_expenses'],
                [self::OD_INTEREST, 'Overdraft Interest', 'EXPENSE', 'non_operating_expenses', null, 'OVERDRAFT'],
                [self::MACHINERY, 'Plant & Machinery', 'ASSET', 'non_operating_movements'],
                [self::LOAN, 'Term Loan', 'LIABILITY', 'non_operating_movements'],
                [self::DRAWINGS, 'Drawings', 'EQUITY', 'equity_movements'],
                [self::GST, 'GST', 'LIABILITY', 'gst', null, 'GST', true],
                [self::GST_PAYMENTS, 'GST Payments / Refunds', 'LIABILITY', 'gst', null, 'GSTPAYMENTS'],
                [self::BANK, 'Farm Cheque', 'ASSET', 'current_asset', 'BANK', null, false, true],
            ],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public static function account(
        string $id,
        string $name,
        string $class,
        string $category,
        ?string $type = null,
        ?string $system = null,
        bool $isGst = false,
        bool $isDefaultBank = false,
    ): array {
        return [
            'account_id' => $id,
            'farm_id' => self::FARM_ID,
            'account_name' => $name,
            'account_class' => $class,
            'account_category' => $category,
            'account_type' => $type,
            'system_account' => $system,
            'mapped_to_account_id' => null,
            'inverted_for_user' => false,
            'report_group' => null,
            'report_group_label' => null,
            'report_group_order' => 0,
            'line_order' => 0,
            'is_gst_account' => $isGst,
            'is_default_bank_account' => $isDefaultBank,
        ];
    }

    private function seedTrackers(): void
    {
        DB::table('trackers')->insert([
            [
                'tracker_id' => self::MILK_TRACKER,
                'farm_id' => self::FARM_ID,
                'tracker_name' => 'Milk Platform',
                'tracker_type' => 'milk',
                'stock_type' => 'Milk',
                'income_account_id' => self::MILK,
                'opening_stock' => 0,
                'display_order' => 0,
            ],
            [
                'tracker_id' => self::STOCK_TRACKER,
                'farm_id' => self::FARM_ID,
                'tracker_name' => 'MA Cows',
                'tracker_type' => 'livestock',
                'stock_type' => 'MA Cows',
                'income_account_id' => null,
                'opening_stock' => 100,
                'display_order' => 1,
            ],
        ]);
    }

    /**
     * Production and payout for the milk tracker, x10,000 per kg:
     *
     *   May 2026   400 kg @ 5.00 -> paid 20 Jun, before the horizon: NOT virtual
     *   Jun 2026   100 kg @ 5.00 -> paid 20 Jul:  $500 virtual
     *   Jul 2026   200 kg @ 5.00 -> paid 20 Aug: $1000 virtual
     *   Aug 2026    50 kg deferred @ 2.00 -> paid 20 Sep: $100 virtual
     */
    private function seedMilk(): void
    {
        $rows = [
            ['2026-05-01', 400, 0],
            ['2026-06-01', 100, 0],
            ['2026-07-01', 200, 0],
            ['2026-08-01', 0, 50],
        ];

        foreach ($rows as [$month, $current, $deferred]) {
            DB::table('tracker_milk_production')->insert([
                'tracker_id' => self::MILK_TRACKER,
                'month' => $month,
                'kg_ms_current' => $current,
                'kg_ms_deferred' => $deferred,
            ]);
            DB::table('milk_payout_rates')->insert([
                'tracker_id' => self::MILK_TRACKER,
                'month' => $month,
                'advance_rate' => 5 * self::FIXED_POINT,
                'deferred_rate' => 2 * self::FIXED_POINT,
            ]);
        }
    }

    /**
     * Cash basis, x10,000, revenue credit-negative. Dates are the period
     * 2026-06-01 .. 2027-05-31 with the horizon at 30 June 2026.
     */
    private function seedTransactionLines(): int
    {
        $rows = [];
        $n = 0;
        $line = static function (string $account, string $type, string $date, float $dollars, ?string $tracker = null, ?string $tag = null) use (&$rows, &$n): void {
            $n++;
            $rows[] = [sprintf('cfafo-%03d', $n), $account, $type, $date, (int) round($dollars * self::FIXED_POINT), $tracker, $tag];
        };

        // Opening bank position: $1,000 the day before the period.
        $line(self::BANK, 'actuals', '2026-05-31', 1_000);

        // Milk tracker: the May production paid in June is a real journal.
        $line(self::MILK, 'actuals', '2026-06-20', -2_000, self::MILK_TRACKER);
        $line(self::SHED, 'actuals', '2026-06-10', 300, self::MILK_TRACKER);
        $line(self::SHED, 'forecast', '2026-09-10', 200, self::MILK_TRACKER);

        // Livestock tracker, forecast half.
        $line(self::STOCK_SALES, 'forecast', '2026-08-05', -800, self::STOCK_TRACKER);
        $line(self::GRAZING, 'forecast', '2026-08-15', 100, self::STOCK_TRACKER);

        // One line per farm section.
        $line(self::OTHER_INCOME, 'actuals', '2026-06-15', -150);
        $line(self::FERTILISER, 'forecast', '2026-07-05', 400);
        $line(self::WAGES, 'actuals', '2026-06-25', 500);
        $line(self::INTEREST_RECEIVED, 'forecast', '2026-10-01', -50);
        $line(self::INTEREST_PAID, 'forecast', '2026-10-01', 30);
        $line(self::MACHINERY, 'forecast', '2026-11-10', 5_001);
        $line(self::LOAN, 'forecast', '2026-12-05', 200);
        $line(self::DRAWINGS, 'forecast', '2027-01-15', 250);

        // GST: net owed in June (actual) and July (forecast); an actual
        // settlement in June that the handler must move to the payments line.
        $line(self::GST, 'actuals', '2026-06-28', -60);
        $line(self::GST, 'forecast', '2026-07-28', -40);
        $line(self::GST, 'actuals', '2026-06-28', 90, null, CashFlowActualsForecastSqlBuilder::TAG_GST_PAYMENT);

        // The end-of-year adjustment: in when exclude_eoy_journals=0, gone when 1.
        $line(self::WAGES, 'forecast', '2027-05-31', 900, null, CashFlowActualsForecastSqlBuilder::TAG_EOY_MANUAL);

        // Out of period on both sides, and the wrong type for its side of the
        // horizon: none of these may reach the report.
        $line(self::WAGES, 'actuals', '2026-05-15', 77_777);
        $line(self::WAGES, 'forecast', '2027-06-01', 77_777);
        $line(self::WAGES, 'forecast', '2026-06-12', 77_777);
        $line(self::WAGES, 'actuals', '2026-07-12', 77_777);

        $values = [];
        foreach ($rows as [$lineId, $accountId, $type, $date, $amount, $tracker, $tag]) {
            $values[] = sprintf(
                "('%s', 'dairy', '%s', '%s', '%s', '%s', 'cash', DATE '%s', %d, %s, %s)",
                self::FARM_ID,
                self::REGION,
                $lineId,
                $accountId,
                $type,
                $date,
                $amount,
                $tracker === null ? 'NULL' : "'{$tracker}'",
                $tag === null ? 'NULL' : "'{$tag}'",
            );
        }

        $this->db->query(sprintf(
            'INSERT INTO %s.transaction_lines'
            .' (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id, tag)'
            .' SELECT * FROM (VALUES %s) AS v ORDER BY 1',
            $this->alias,
            implode(', ', $values),
        ));

        return count($rows);
    }
}
