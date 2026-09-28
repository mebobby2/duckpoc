<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;

/**
 * A reporting group at volume: a parent over N child entities, each carrying
 * the scale farm's thirty-year shape at about `$linesPerFarm` lines, on a
 * chart of accounts of its own.
 *
 * On top of the per-farm lines, the group inputs `ReportingGroupSeeder`
 * exercises by hand, generated for every year:
 *
 * - odd child k lends to child k+1 each August, with both bank legs, and tops
 *   the loan up each March through an internal account mapped onto it;
 * - every child's wages and sales consolidate onto one group line each;
 * - odd children carry an overdraft, even ones do not;
 * - year ends are mixed — June, March, September — and every child runs on the
 *   parent's June.
 */
final class ReportingGroupScaleSeeder
{
    public const string REGION = 'rg-scale-group';

    private const int FIXED_POINT = 10000;

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
    ) {
    }

    public static function parentId(int $farms, int $linesPerFarm): string
    {
        return sprintf('rg-%dx%s', $farms, PipelineScaleSeeder::label($linesPerFarm));
    }

    /** @return list<string> */
    public static function childIds(int $farms, int $linesPerFarm): array
    {
        $parent = self::parentId($farms, $linesPerFarm);

        return array_map(static fn (int $k): string => sprintf('%s-%02d', $parent, $k), range(1, $farms));
    }

    /** @return int lines written across every child */
    public function seed(int $farms, int $linesPerFarm): int
    {
        $parent = self::parentId($farms, $linesPerFarm);
        $children = self::childIds($farms, $linesPerFarm);

        $this->clear($parent, $children);

        DB::table('farms')->insert($this->farm($parent, 6));
        DB::table('accounts')->insert([
            $this->account($parent, "{$parent}-wages", 'Wages (group)', 'EXPENSE', 'operating_expenses'),
            $this->account($parent, "{$parent}-sales", 'Milk Sales (group)', 'REVENUE', 'other_income'),
        ]);

        $lines = new PipelineScaleSeeder($this->db, $this->alias);
        $total = 0;
        foreach ($children as $i => $child) {
            $k = $i + 1;
            DB::table('farms')->insert($this->farm($child, [6, 6, 3, 6, 9][$i % 5]));
            DB::table('accounts')->insert($this->chart($child));
            DB::table('reporting_group_farms')->insert(['parent_farm_id' => $parent, 'child_farm_id' => $child]);
            DB::table('consolidated_accounts')->insert([
                ['parent_farm_id' => $parent, 'old_account_id' => "{$child}-wages", 'new_account_id' => "{$parent}-wages"],
                ['parent_farm_id' => $parent, 'old_account_id' => "{$child}-sales", 'new_account_id' => "{$parent}-sales"],
            ]);
            DB::table('gst_settings')->insert(['farm_id' => $child, 'sales_tax_period' => 'TWOMONTHS', 'sales_tax_basis' => 'PAYMENTS']);
            DB::table('opening_balances')->insert([
                ['farm_id' => $child, 'financial_year' => 2024, 'opening_bank' => (30_000 + 2_000 * $k) * self::FIXED_POINT, 'opening_gst' => -2_000 * self::FIXED_POINT],
                ['farm_id' => $child, 'financial_year' => 2025, 'opening_bank' => (35_000 + 2_000 * $k) * self::FIXED_POINT, 'opening_gst' => -2_500 * self::FIXED_POINT],
            ]);
            if ($k % 2 === 1) {
                DB::table('overdrafts')->insert([
                    'farm_id' => $child, 'rate' => 50000, 'overdraft_limit' => 100_000 * self::FIXED_POINT,
                    'start_date' => PipelineScaleSeeder::PERIOD_FROM, 'payment_term' => 'interest_only_monthly',
                ]);
            }

            $total += $lines->writeLines($child, self::REGION, $linesPerFarm, [
                'sales' => "{$child}-sales",
                'wages' => "{$child}-wages",
                'fertiliser' => "{$child}-fertiliser",
                'fertiliser_internal' => "{$child}-fert-internal",
                'depreciation' => "{$child}-depr",
                'loan' => "{$child}-loan",
                'gst' => "{$child}-gst",
                'bank' => "{$child}-bank",
                'bank_2' => "{$child}-bank-2",
                'machinery' => "{$child}-machinery",
            ]);
        }

        for ($i = 0; $i + 1 < count($children); $i += 2) {
            $total += $this->seedTransfer($parent, $children[$i], $children[$i + 1], 10_000 + 1_000 * ($i + 1));
        }

        return $total;
    }

    /**
     * An inter-entity loan: accounts on both sides, the merged-account row,
     * and every year's lines — August's advance with its bank legs, March's
     * top-up through the lender's internal account.
     *
     * @return int lines written
     */
    private function seedTransfer(string $parent, string $lender, string $borrower, int $dollars): int
    {
        $to = "{$lender}-loan-to-{$borrower}";
        $toInternal = "{$to}-internal";
        $from = "{$borrower}-loan-from-{$lender}";

        DB::table('accounts')->insert([
            $this->account($lender, $to, 'Loan to '.$borrower, 'ASSET', 'non_operating_movements'),
            $this->account($lender, $toInternal, 'Loan to '.$borrower.' (planning)', 'ASSET', 'non_operating_movements', mappedTo: $to),
            $this->account($borrower, $from, 'Loan from '.$lender, 'LIABILITY', 'non_operating_movements'),
        ]);
        DB::table('merged_accounts')->insert([
            'parent_farm_id' => $parent,
            'from_farm_id' => $lender,
            'from_account_id' => $to,
            'to_farm_id' => $borrower,
            'to_account_id' => $from,
        ]);

        $amount = $dollars * self::FIXED_POINT;
        $topUp = 1_000 * self::FIXED_POINT;
        $first = PipelineScaleSeeder::FIRST_YEAR;
        $last = PipelineScaleSeeder::LAST_YEAR;
        $horizon = PipelineScaleSeeder::HORIZON;
        $region = self::REGION;
        $legs = [
            [$lender, $to, 8, $amount],
            [$lender, "{$lender}-bank", 8, -$amount],
            [$borrower, $from, 8, -$amount],
            [$borrower, "{$borrower}-bank", 8, $amount],
            [$lender, $toInternal, 3, $topUp],
            [$borrower, $from, 3, -$topUp],
        ];

        $selects = [];
        foreach ($legs as $n => [$farm, $account, $month, $value]) {
            $day = $month === 8 ? 12 : 14;
            $selects[] = <<<SQL
                SELECT '{$farm}', 'dairy', '{$region}', '{$account}-leg{$n}-' || y, '{$account}',
                       CASE WHEN make_date(y, {$month}, {$day}) <= DATE '{$horizon}' THEN 'actuals' ELSE 'forecast' END,
                       'cash', make_date(y, {$month}, {$day}), {$value}, NULL, NULL
                FROM generate_series({$first}, {$last}) AS yy(y)
                SQL;
        }

        $this->db->query(
            "INSERT INTO {$this->alias}.transaction_lines\n"
            ."    (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id, tag)\n"
            .implode("\nUNION ALL\n", $selects)
        );

        return count($legs) * ($last - $first + 1);
    }

    /** @param list<string> $children */
    private function clear(string $parent, array $children): void
    {
        foreach ($children as $child) {
            $this->db->query("DELETE FROM {$this->alias}.transaction_lines WHERE farm_id = '".str_replace("'", "''", $child)."'");
        }

        $farms = [$parent, ...$children];
        foreach (['overdrafts', 'opening_balances', 'gst_settings', 'accounts', 'farms'] as $table) {
            DB::table($table)->whereIn('farm_id', $farms)->delete();
        }
        foreach (['reporting_group_farms', 'merged_accounts', 'consolidated_accounts'] as $table) {
            DB::table($table)->where('parent_farm_id', $parent)->delete();
        }
    }

    /** @return array<string, mixed> */
    private function farm(string $farmId, int $fyEnd): array
    {
        return [
            'farm_id' => $farmId,
            'farm_type' => 'dairy',
            'region' => self::REGION,
            'opening_balance' => 0,
            'financial_year_end_month' => $fyEnd,
            'country_code' => 'NZ',
        ];
    }

    /**
     * The scale farm's accounts as one entity's own: the same roles
     * `PipelineOracleSeeder` gives its global ones, each farm-scoped.
     *
     * @return list<array<string, mixed>>
     */
    private function chart(string $farmId): array
    {
        $p = $farmId;

        return [
            $this->account($p, "{$p}-sales", 'Milk Sales', 'REVENUE', 'other_income'),
            $this->account($p, "{$p}-wages", 'Wages', 'EXPENSE', 'operating_expenses'),
            $this->account($p, "{$p}-fertiliser", 'Fertiliser', 'EXPENSE', 'operating_expenses'),
            $this->account($p, "{$p}-fert-internal", 'Fertiliser (planning)', 'EXPENSE', 'operating_expenses', mappedTo: "{$p}-fertiliser"),
            $this->account($p, "{$p}-bank", 'Farm Cheque', 'ASSET', 'current_asset', type: 'BANK', isDefaultBank: true),
            $this->account($p, "{$p}-bank-2", 'Savings', 'ASSET', 'current_asset', type: 'BANK'),
            $this->account($p, "{$p}-depr", 'Depreciation', 'EXPENSE', 'operating_expenses', type: 'DEPRECIATION'),
            $this->account($p, "{$p}-gst", 'GST', 'LIABILITY', 'gst', system: 'GST', isGst: true),
            $this->account($p, "{$p}-gst-payments", 'GST Payments / Refunds', 'LIABILITY', 'gst', system: 'GSTPAYMENTS'),
            $this->account($p, "{$p}-re", 'Retained Earnings', 'EQUITY', 'equity_movements', system: 'RETAINED_EARNINGS'),
            $this->account($p, "{$p}-cye", 'Current Year Earnings', 'EQUITY', 'equity_movements', system: 'CURRENT_YEAR_EARNINGS'),
            $this->account($p, "{$p}-od-liability", 'Bank Overdraft', 'LIABILITY', 'current_liability', system: 'LIABILITY'),
            $this->account($p, "{$p}-loan", 'Term Loan', 'LIABILITY', 'non_operating_movements', inverted: true),
            $this->account($p, "{$p}-od-interest", 'Overdraft Interest', 'EXPENSE', 'non_operating_expenses', system: 'OVERDRAFT'),
            $this->account($p, "{$p}-machinery", 'Plant & Machinery', 'ASSET', 'non_operating_movements'),
        ];
    }

    /** @return array<string, mixed> */
    private function account(
        string $farmId,
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
    ): array {
        return [
            'account_id' => $id,
            'farm_id' => $farmId,
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
    }
}
