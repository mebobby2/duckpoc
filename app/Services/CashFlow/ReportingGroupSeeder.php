<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;

/**
 * A reporting group that exercises pipes 11 and 20 and `CombineReports`.
 *
 * A parent with no lines of its own and two child entities, each with its
 * own chart of accounts, as two Xero organisations would have. Every input is
 * there to change a number when a pipe mishandles it:
 *
 * - A lends B $20,000 in August and tops it up $1,000 in March through an
 *   internal planning account mapped onto A's loan. Pipe 11 must move A's
 *   whole loan balance, alias included, onto B's liability, where it cancels,
 *   and zero both of A's rows. It runs inside the overdraft sub-report as well,
 *   so A's interest is charged as if the loan never left its bank.
 * - Both children's wages consolidate onto one group line at pipe 20.
 * - B's own financial year ends in March; the parent's ends in June, and
 *   every child runs on the parent's. B's May lines therefore sit in a
 *   different season than B alone would put them in, which moves them between
 *   retained and current-year earnings.
 * - Only A has an overdraft, so the recurrence runs for one entity beside one
 *   that has none.
 */
final class ReportingGroupSeeder
{
    public const string PARENT = 'rg-parent';
    public const string CHILD_A = 'rg-child-a';
    public const string CHILD_B = 'rg-child-b';

    /** @var list<string> */
    public const array CHILDREN = [self::CHILD_A, self::CHILD_B];

    public const string REGION = 'rg-oracle';

    public const string GROUP_WAGES = 'rg-wages';
    public const string A_LOAN = 'rga-loan-to-b';
    public const string A_LOAN_INTERNAL = 'rga-loan-to-b-internal';
    public const string B_LOAN = 'rgb-loan-from-a';

    private const int FIXED_POINT = 10000;

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
    ) {
    }

    public function seed(): int
    {
        $this->clear();
        $this->seedFarms();
        $this->seedAccounts();
        $this->seedGroup();
        $this->seedSettings();

        return $this->seedTransactionLines();
    }

    private function clear(): void
    {
        $farms = [self::PARENT, ...self::CHILDREN];
        $this->db->query(sprintf(
            "DELETE FROM %s.transaction_lines WHERE farm_id IN ('%s')",
            $this->alias,
            implode("', '", $farms),
        ));

        foreach (['overdrafts', 'opening_balances', 'gst_settings', 'accounts', 'farms'] as $table) {
            DB::table($table)->whereIn('farm_id', $farms)->delete();
        }
        DB::table('reporting_group_farms')->where('parent_farm_id', self::PARENT)->delete();
        DB::table('merged_accounts')->where('parent_farm_id', self::PARENT)->delete();
        DB::table('consolidated_accounts')->where('parent_farm_id', self::PARENT)->delete();
    }

    private function seedFarms(): void
    {
        $farm = static fn (string $id, int $fyEnd): array => [
            'farm_id' => $id,
            'farm_type' => 'dairy',
            'region' => self::REGION,
            'opening_balance' => 0,
            'financial_year_end_month' => $fyEnd,
            'country_code' => 'NZ',
        ];

        DB::table('farms')->insert([
            $farm(self::PARENT, 6),
            $farm(self::CHILD_A, 6),
            $farm(self::CHILD_B, 3),
        ]);
    }

    private function seedAccounts(): void
    {
        $account = static fn (
            string $farmId,
            string $id,
            string $name,
            string $class,
            string $category,
            ?string $type = null,
            ?string $system = null,
            ?string $mappedTo = null,
            bool $isGst = false,
            bool $isDefaultBank = false,
        ): array => [
            'account_id' => $id,
            'farm_id' => $farmId,
            'account_name' => $name,
            'account_class' => $class,
            'account_category' => $category,
            'account_type' => $type,
            'system_account' => $system,
            'mapped_to_account_id' => $mappedTo,
            'inverted_for_user' => false,
            'report_group' => null,
            'report_group_label' => null,
            'report_group_order' => 0,
            'line_order' => 0,
            'is_gst_account' => $isGst,
            'is_default_bank_account' => $isDefaultBank,
        ];

        $chart = static fn (string $farmId, string $p): array => [
            $account($farmId, "{$p}-sales", 'Milk Sales', 'REVENUE', 'other_income'),
            $account($farmId, "{$p}-wages", 'Wages', 'EXPENSE', 'operating_expenses'),
            $account($farmId, "{$p}-bank", 'Farm Cheque', 'ASSET', 'current_asset', type: 'BANK', isDefaultBank: true),
            $account($farmId, "{$p}-gst", 'GST', 'LIABILITY', 'gst', system: 'GST', isGst: true),
            $account($farmId, "{$p}-gst-payments", 'GST Payments / Refunds', 'LIABILITY', 'gst', system: 'GSTPAYMENTS'),
            $account($farmId, "{$p}-re", 'Retained Earnings', 'EQUITY', 'equity_movements', system: 'RETAINED_EARNINGS'),
            $account($farmId, "{$p}-cye", 'Current Year Earnings', 'EQUITY', 'equity_movements', system: 'CURRENT_YEAR_EARNINGS'),
            $account($farmId, "{$p}-od-liability", 'Bank Overdraft', 'LIABILITY', 'current_liability', system: 'LIABILITY'),
            $account($farmId, "{$p}-od-interest", 'Overdraft Interest', 'EXPENSE', 'non_operating_expenses', system: 'OVERDRAFT'),
        ];

        DB::table('accounts')->insert([
            ...$chart(self::CHILD_A, 'rga'),
            $account(self::CHILD_A, 'rga-fertiliser', 'Fertiliser', 'EXPENSE', 'operating_expenses'),
            $account(self::CHILD_A, 'rga-fert-internal', 'Fertiliser (planning)', 'EXPENSE', 'operating_expenses', mappedTo: 'rga-fertiliser'),
            $account(self::CHILD_A, self::A_LOAN, 'Loan to B', 'ASSET', 'non_operating_movements'),
            $account(self::CHILD_A, self::A_LOAN_INTERNAL, 'Loan to B (planning)', 'ASSET', 'non_operating_movements', mappedTo: self::A_LOAN),
            $account(self::CHILD_A, 'rga-machinery', 'Plant & Machinery', 'ASSET', 'non_operating_movements'),

            ...$chart(self::CHILD_B, 'rgb'),
            $account(self::CHILD_B, self::B_LOAN, 'Loan from A', 'LIABILITY', 'non_operating_movements'),

            // The consolidated line: the group's, not either child's.
            $account(self::PARENT, self::GROUP_WAGES, 'Wages (group)', 'EXPENSE', 'operating_expenses'),
        ]);
    }

    private function seedGroup(): void
    {
        DB::table('reporting_group_farms')->insert([
            ['parent_farm_id' => self::PARENT, 'child_farm_id' => self::CHILD_A],
            ['parent_farm_id' => self::PARENT, 'child_farm_id' => self::CHILD_B],
        ]);

        DB::table('merged_accounts')->insert([
            'parent_farm_id' => self::PARENT,
            'from_farm_id' => self::CHILD_A,
            'from_account_id' => self::A_LOAN,
            'to_farm_id' => self::CHILD_B,
            'to_account_id' => self::B_LOAN,
        ]);

        DB::table('consolidated_accounts')->insert([
            ['parent_farm_id' => self::PARENT, 'old_account_id' => 'rga-wages', 'new_account_id' => self::GROUP_WAGES],
            ['parent_farm_id' => self::PARENT, 'old_account_id' => 'rgb-wages', 'new_account_id' => self::GROUP_WAGES],
        ]);
    }

    private function seedSettings(): void
    {
        foreach (self::CHILDREN as $farmId) {
            DB::table('gst_settings')->insert(['farm_id' => $farmId, 'sales_tax_period' => 'TWOMONTHS', 'sales_tax_basis' => 'PAYMENTS']);
        }

        DB::table('opening_balances')->insert([
            ['farm_id' => self::CHILD_A, 'financial_year' => 2024, 'opening_bank' => 42_000 * self::FIXED_POINT, 'opening_gst' => -2_500 * self::FIXED_POINT],
            ['farm_id' => self::CHILD_A, 'financial_year' => 2025, 'opening_bank' => 50_000 * self::FIXED_POINT, 'opening_gst' => -3_000 * self::FIXED_POINT],
            ['farm_id' => self::CHILD_B, 'financial_year' => 2024, 'opening_bank' => 15_000 * self::FIXED_POINT, 'opening_gst' => -1_000 * self::FIXED_POINT],
            ['farm_id' => self::CHILD_B, 'financial_year' => 2025, 'opening_bank' => 18_000 * self::FIXED_POINT, 'opening_gst' => -1_200 * self::FIXED_POINT],
        ]);

        DB::table('overdrafts')->insert([
            'farm_id' => self::CHILD_A,
            'rate' => 50000,
            'overdraft_limit' => 100_000 * self::FIXED_POINT,
            'start_date' => PipelineOracleSeeder::PERIOD_FROM,
            'payment_term' => 'interest_only_monthly',
        ]);
    }

    /** @return int rows written */
    private function seedTransactionLines(): int
    {
        $rows = [];
        $line = static function (string $farmId, string $account, string $type, string $date, float $dollars, ?string $tag = null) use (&$rows): void {
            $rows[] = [$farmId, sprintf('%s-%04d', $farmId, count($rows) + 1), $account, $type, $date, (int) round($dollars * self::FIXED_POINT), $tag];
        };

        $a = self::CHILD_A;
        $b = self::CHILD_B;

        // Prior seasons for retained earnings.
        $line($a, 'rga-sales', 'actuals', '2022-09-15', -50_000);
        $line($a, 'rga-wages', 'actuals', '2022-09-20', 30_000);
        $line($a, 'rga-sales', 'actuals', '2023-09-15', -70_000);
        $line($a, 'rga-wages', 'actuals', '2023-09-20', 40_000);
        // B's May lines: season 2023 and 2024 on the parent's June year, but
        // 2024 and 2025 on B's own March year. The 2024 one is therefore
        // retained in the group's report and current-year in B's own.
        $line($b, 'rgb-sales', 'actuals', '2023-05-15', -25_000);
        $line($b, 'rgb-wages', 'actuals', '2023-05-20', 10_000);
        $line($b, 'rgb-sales', 'actuals', '2024-05-15', -32_000);
        $line($b, 'rgb-wages', 'actuals', '2024-05-20', 12_000);

        $line($a, 'rga-bank', 'actuals', '2024-06-30', 8_000);
        $line($b, 'rgb-bank', 'actuals', '2024-06-30', 3_000);

        foreach (range(0, 11) as $i) {
            $month = date('Y-m', strtotime(PipelineOracleSeeder::PERIOD_FROM." +{$i} month"));
            $type = $month <= '2024-12' ? 'actuals' : 'forecast';
            $seasonal = [1.4, 1.3, 1.1, 0.9, 0.7, 0.6, 0.6, 0.7, 0.9, 1.1, 1.3, 1.4][$i];

            $line($a, 'rga-sales', $type, "{$month}-15", -round(30_000 * $seasonal, 2));
            $line($a, 'rga-wages', $type, "{$month}-20", 12_000);
            $line($a, 'rga-fertiliser', $type, "{$month}-10", 3_000);
            $line($a, 'rga-fert-internal', $type, "{$month}-11", 400);
            $line($a, 'rga-gst', $type, "{$month}-28", -round(0.15 * (30_000 * $seasonal - 15_400), 2));

            $line($b, 'rgb-sales', $type, "{$month}-16", -round(18_000 * $seasonal, 2));
            $line($b, 'rgb-wages', $type, "{$month}-21", 8_000);
            $line($b, 'rgb-gst', $type, "{$month}-27", -round(0.15 * (18_000 * $seasonal - 8_000), 2));
        }

        // The inter-entity loan, both sides, and a planned top-up through A's
        // internal alias — each leg with its bank side, as Xero records it.
        $line($a, self::A_LOAN, 'actuals', '2024-08-12', 20_000);
        $line($a, 'rga-bank', 'actuals', '2024-08-12', -20_000);
        $line($b, self::B_LOAN, 'actuals', '2024-08-12', -20_000);
        $line($b, 'rgb-bank', 'actuals', '2024-08-12', 20_000);
        $line($a, self::A_LOAN_INTERNAL, 'forecast', '2025-03-12', 1_000);
        $line($b, self::B_LOAN, 'forecast', '2025-03-12', -1_000);

        // A's tractor sends A into overdraft for part of the forecast.
        $line($a, 'rga-machinery', 'forecast', '2025-01-20', 200_000);

        // GST settlements for A; an EOY adjustment for B.
        $line($a, 'rga-gst', 'actuals', '2024-08-28', 4_100, PipelineOracleSeeder::TAG_GST_PAYMENT);
        $line($a, 'rga-gst', 'actuals', '2024-10-28', 3_800, PipelineOracleSeeder::TAG_GST_PAYMENT);
        $line($b, 'rgb-wages', 'forecast', '2025-06-30', 9_000, PipelineOracleSeeder::TAG_EOY);

        $values = [];
        foreach ($rows as [$farmId, $lineId, $accountId, $type, $date, $amount, $tag]) {
            $values[] = sprintf(
                "('%s', 'dairy', '%s', '%s', '%s', '%s', 'cash', DATE '%s', %d, NULL, %s)",
                $farmId,
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
            .' SELECT * FROM (VALUES %s) AS v ORDER BY 1, 8',
            $this->alias,
            implode(', ', $values),
        ));

        return count($rows);
    }
}
