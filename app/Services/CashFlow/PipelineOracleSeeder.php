<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;

/**
 * The farm that exercises every pipe in Figured's `DataPipeline`.
 *
 * Phase 3's oracle farm holds one transaction line, which is why the overdraft
 * port looked finished: none of the seventeen pipes that run after the virtual
 * journals merge had anything to act on. This farm carries an input for each
 * of them — see the README's pipe table for which pipe reads which — so that a
 * stage that is skipped or mis-ordered changes a number.
 *
 * Deterministic and small on purpose. Every amount is chosen so a pipe's
 * effect is visible on the page: the EOY journal is a round $9,000 that either
 * appears in June or does not; the internal fertiliser account carries $400 a
 * month that must vanish into the Xero fertiliser line; the bank-to-bank
 * transfers are $5,000 that must not move net cash at all.
 *
 * Financial year ends 30 June, NZ, GST two-monthly on a payments basis —
 * the most common NZ farm configuration, and the one whose payment schedule
 * has both exception months (April → 7 May, December → 15 January).
 */
final class PipelineOracleSeeder
{
    public const string FARM_ID = 'pipeline-oracle-farm';
    public const string REGION = 'pipeline-oracle';

    /** The report year: FY2025 is 2024-07-01 .. 2025-06-30. */
    public const string PERIOD_FROM = '2024-07-01';
    public const string PERIOD_TO = '2025-06-30';
    /** Actuals through December; forecast from January. */
    public const string HORIZON = '2024-12-31';

    public const string SALES = 'pl-sales';
    public const string WAGES = 'pl-wages';
    public const string FERTILISER = 'pl-fertiliser';
    public const string FERTILISER_INTERNAL = 'pl-fert-internal';
    public const string BANK = 'pl-bank';
    public const string BANK_2 = 'pl-bank-2';
    public const string DEPRECIATION = 'pl-depr';
    public const string GST = 'pl-gst';
    public const string GST_PAYMENTS = 'pl-gst-payments';
    public const string RETAINED_EARNINGS = 'pl-re';
    public const string CURRENT_YEAR_EARNINGS = 'pl-cye';
    public const string OD_LIABILITY = 'pl-od-liability';
    public const string LOAN = 'pl-loan';
    /** Where the overdraft handler's journals land — Figured's internal OVERDRAFT account. */
    public const string OD_INTEREST = 'pl-od-interest';
    public const string MACHINERY = 'pl-machinery';

    public const string TAG_EOY = 'eoy_adjust_manual';
    /** Marks an actual settlement with the tax office, as opposed to the GST component of a sale or purchase. */
    public const string TAG_GST_PAYMENT = 'gst_payment';

    private const int FIXED_POINT = 10000;

    /** @var list<string> */
    private const array ACCOUNT_IDS = [
        self::SALES, self::WAGES, self::FERTILISER, self::FERTILISER_INTERNAL,
        self::BANK, self::BANK_2, self::DEPRECIATION, self::GST, self::GST_PAYMENTS,
        self::RETAINED_EARNINGS, self::CURRENT_YEAR_EARNINGS, self::OD_LIABILITY, self::LOAN, self::OD_INTEREST, self::MACHINERY,
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
        $this->seedSettings();

        return $this->seedTransactionLines();
    }

    private function clear(): void
    {
        $this->db->query("DELETE FROM {$this->alias}.transaction_lines WHERE farm_id = '".self::FARM_ID."'");

        foreach (['overdrafts', 'opening_balances', 'gst_settings'] as $table) {
            DB::table($table)->where('farm_id', self::FARM_ID)->delete();
        }

        DB::table('accounts')->whereIn('account_id', self::ACCOUNT_IDS)->delete();
        DB::table('farms')->where('farm_id', self::FARM_ID)->delete();
    }

    private function seedFarm(): void
    {
        DB::table('farms')->insert([
            'farm_id' => self::FARM_ID,
            'farm_type' => 'dairy',
            'region' => self::REGION,
            // Unused by the pipeline port: opening comes from opening_balances
            // and the bank accounts, as Balance::getReport() computes it.
            'opening_balance' => 0,
            'financial_year_end_month' => 6,
            'country_code' => 'NZ',
        ]);
    }

    private function seedAccounts(): void
    {
        $account = static fn (
            string $id,
            string $name,
            string $class,
            string $category,
            ?string $type = null,
            ?string $system = null,
            ?string $mappedTo = null,
            bool $inverted = false,
            bool $isGst = false,
            bool $isDefaultBank = false,
        ): array => [
            'account_id' => $id,
            'account_name' => $name,
            'account_class' => $class,
            'account_category' => $category,
            'account_type' => $type,
            'system_account' => $system,
            'mapped_to_account_id' => $mappedTo,
            'inverted_for_user' => $inverted,
            'report_group' => null,
            'report_group_label' => null,
            'report_group_order' => 0,
            'line_order' => 0,
            'is_gst_account' => $isGst,
            'is_default_bank_account' => $isDefaultBank,
        ];

        DB::table('accounts')->insert([
            $account(self::SALES, 'Milk Sales', 'REVENUE', 'other_income'),
            $account(self::WAGES, 'Wages', 'EXPENSE', 'operating_expenses'),
            $account(self::FERTILISER, 'Fertiliser', 'EXPENSE', 'operating_expenses'),
            // Pipe 13: an internal Figured account whose cells fold into
            // Fertiliser and whose own row is then dropped.
            $account(self::FERTILISER_INTERNAL, 'Fertiliser (planning)', 'EXPENSE', 'operating_expenses', mappedTo: self::FERTILISER),
            // Balance::getReport() sums every BANK-type account; pipe 21 acts on the default one.
            $account(self::BANK, 'Farm Cheque', 'ASSET', 'current_asset', type: 'BANK', isDefaultBank: true),
            $account(self::BANK_2, 'Savings', 'ASSET', 'current_asset', type: 'BANK'),
            // Balance::getReport() subtracts DEPRECIATION-type balances on a cash basis.
            $account(self::DEPRECIATION, 'Depreciation', 'EXPENSE', 'operating_expenses', type: 'DEPRECIATION'),
            $account(self::GST, 'GST', 'LIABILITY', 'gst', system: 'GST', isGst: true),
            // The payments/refunds line. Figured's is a synthetic id unless
            // the farm is on MYOB; a real row here so the seed stays plain.
            $account(self::GST_PAYMENTS, 'GST Payments / Refunds', 'LIABILITY', 'gst', system: 'GSTPAYMENTS'),
            $account(self::RETAINED_EARNINGS, 'Retained Earnings', 'EQUITY', 'equity_movements', system: 'RETAINED_EARNINGS'),
            $account(self::CURRENT_YEAR_EARNINGS, 'Current Year Earnings', 'EQUITY', 'equity_movements', system: 'CURRENT_YEAR_EARNINGS'),
            // Pipe 21 moves a negative default-bank balance here.
            $account(self::OD_LIABILITY, 'Bank Overdraft', 'LIABILITY', 'current_liability', system: 'LIABILITY'),
            // Pipe 18: shown to the user with its sign flipped.
            $account(self::LOAN, 'Term Loan', 'LIABILITY', 'non_operating_movements', inverted: true),
            // Pipe 8: the overdraft VJ posts interest here, priority 150.
            $account(self::OD_INTEREST, 'Overdraft Interest', 'EXPENSE', 'non_operating_expenses', system: 'OVERDRAFT'),
            // A capital purchase large enough to push the farm into overdraft
            // for part of the forecast half — without it the recurrence at pipe
            // 8 passes parity on a row of zeros.
            $account(self::MACHINERY, 'Plant & Machinery', 'ASSET', 'non_operating_movements'),
        ]);
    }

    private function seedSettings(): void
    {
        DB::table('gst_settings')->insert([
            'farm_id' => self::FARM_ID,
            'sales_tax_period' => 'TWOMONTHS',
            'sales_tax_basis' => 'PAYMENTS',
        ]);

        // Pipes 9 and 10: the user-entered opening position for the report
        // year, and for the prior year so a YTD run that starts mid-year has
        // one to reach back to. Opening GST is negative: owed to the IRD.
        DB::table('opening_balances')->insert([
            ['farm_id' => self::FARM_ID, 'financial_year' => 2024, 'opening_bank' => 42_000 * self::FIXED_POINT, 'opening_gst' => -2_500 * self::FIXED_POINT],
            ['farm_id' => self::FARM_ID, 'financial_year' => 2025, 'opening_bank' => 50_000 * self::FIXED_POINT, 'opening_gst' => -3_000 * self::FIXED_POINT],
        ]);

        DB::table('overdrafts')->insert([
            'farm_id' => self::FARM_ID,
            'rate' => 50000,
            'overdraft_limit' => 100_000 * self::FIXED_POINT,
            'start_date' => self::PERIOD_FROM,
            'payment_term' => 'interest_only_monthly',
        ]);
    }

    /**
     * Every line the pipes read, in x10,000 units, cash basis. Revenue is
     * stored as a credit (negative) and expense as a debit (positive), as the
     * lake stores them.
     *
     * @return int rows written
     */
    private function seedTransactionLines(): int
    {
        $rows = [];
        $n = 0;
        $line = static function (string $account, string $type, string $date, float $dollars, ?string $tag = null) use (&$rows, &$n): void {
            $n++;
            $rows[] = [sprintf('pl-%04d', $n), $account, $type, $date, (int) round($dollars * self::FIXED_POINT), $tag];
        };

        // Prior seasons, so RetainedEarnings (pipe 15) has net profit to
        // accumulate: FY2023 +$20,000, FY2024 +$30,000.
        $line(self::SALES, 'actuals', '2022-09-15', -50_000);
        $line(self::WAGES, 'actuals', '2022-09-20', 30_000);
        $line(self::SALES, 'actuals', '2023-09-15', -70_000);
        $line(self::WAGES, 'actuals', '2023-09-20', 40_000);

        // The bank's position at the start of the report year, as Xero holds it:
        // the sum of the account's own journal lines. This is what
        // Balance::getReport() reads for an actuals/forecast period — the
        // opening_balances row is the *budget* path's input, not this one's.
        $line(self::BANK, 'actuals', '2024-06-30', 8_000);

        // FY2025, month by month. Actuals through December, forecast after.
        foreach (range(0, 11) as $i) {
            $month = date('Y-m', strtotime(self::PERIOD_FROM." +{$i} month"));
            $type = $month <= '2024-12' ? 'actuals' : 'forecast';
            $seasonal = [1.4, 1.3, 1.1, 0.9, 0.7, 0.6, 0.6, 0.7, 0.9, 1.1, 1.3, 1.4][$i];

            $line(self::SALES, $type, "{$month}-15", -round(30_000 * $seasonal, 2));
            $line(self::WAGES, $type, "{$month}-20", 12_000);
            $line(self::FERTILISER, $type, "{$month}-10", 3_000);
            // Pipe 13: folds into Fertiliser.
            $line(self::FERTILISER_INTERNAL, $type, "{$month}-11", 400);
            // Net GST for the month: collected on sales less paid on purchases,
            // roughly 15% of the net. Negative = owed to the IRD.
            $line(self::GST, $type, "{$month}-28", -round(0.15 * (30_000 * $seasonal - 15_400), 2));
            $line(self::DEPRECIATION, $type, "{$month}-28", 1_500);
            // A loan repayment; the account is shown inverted to the user.
            $line(self::LOAN, $type, "{$month}-05", 2_000);
        }

        // A tractor in January: the balance goes overdrawn through autumn
        // and climbs back out as the spring milk cheques arrive.
        $line(self::MACHINERY, 'forecast', '2025-01-20', 200_000);

        // Actual settlements with the IRD in the actuals half — the GST VJ
        // must take these off the net GST line and show them on the payments
        // line, exactly once. Two-monthly, due the 28th, Dec pushed to 15 Jan.
        $line(self::GST, 'actuals', '2024-08-28', 4_100, self::TAG_GST_PAYMENT);
        $line(self::GST, 'actuals', '2024-10-28', 3_800, self::TAG_GST_PAYMENT);

        // Bank-to-bank transfers: must not move net cash movement at all.
        $line(self::BANK, 'actuals', '2024-09-03', -5_000);
        $line(self::BANK_2, 'actuals', '2024-09-03', 5_000);
        $line(self::BANK_2, 'forecast', '2025-03-03', -5_000);
        $line(self::BANK, 'forecast', '2025-03-03', 5_000);

        // An end-of-year adjustment: in the report when excludeEoyJournals is
        // off, gone when it is on.
        $line(self::WAGES, 'forecast', '2025-06-30', 9_000, self::TAG_EOY);

        $values = [];
        foreach ($rows as [$lineId, $accountId, $type, $date, $amount, $tag]) {
            $values[] = sprintf(
                "('%s', 'dairy', '%s', '%s', '%s', '%s', 'cash', DATE '%s', %d, NULL, %s)",
                self::FARM_ID,
                self::REGION,
                $lineId,
                $accountId,
                $type,
                $date,
                $amount,
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
