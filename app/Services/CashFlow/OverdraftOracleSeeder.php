<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;

/**
 * The Phase 3 parity oracle: Figured's own overdraft test scenario, seeded here.
 *
 * Phase 1 proved Cash Flow by capturing Figured's real output and diffing 84
 * cells. Overdraft interest gets the same treatment, but the oracle is cheaper
 * to obtain — `OverdraftTest::overdraftDataProvider` and
 * `OverdraftInterestTest::testBudgetInterestAnnually` already pin the expected
 * series in the Figured repo, so the numbers can be asserted without running
 * anything over there.
 *
 * The scenario is deliberately the simplest one that exercises compounding: a
 * single $1,000 expense in the first month and nothing afterwards, so the
 * closing balance sits flat at -$1,000 for twelve months. Flat is the point —
 * any growth in the interest charged can only come from interest compounding on
 * itself, which is the behaviour being tested. A farm with varying balances
 * would confound the two.
 *
 * At 5% annual the expected posted amounts, in x10,000 units, are
 * 41666, 41840, 42014, 42189, 42365, 42541, 42719, 42897, 43075, 43255, 43435,
 * 43616 — the second differs from the first by exactly the interest on the
 * first, which is the compounding made visible.
 */
final class OverdraftOracleSeeder
{
    public const string FARM_ID = 'overdraft-oracle-farm';
    public const string REGION = 'od-oracle';
    public const string ACCOUNT_ID = 'od-expense';

    /** $1,000, inflated. The whole scenario hangs off this one line. */
    private const int EXPENSE = 1_000 * 10000;

    /** Annual percentage x 10,000, as Figured stores it: 5% is 50000. */
    private const int RATE = 50000;

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
        private readonly string $appAlias,
    ) {
    }

    public function seed(string $paymentTerm = 'interest_only_monthly'): void
    {
        $this->db->query(
            "DELETE FROM {$this->alias}.transaction_lines WHERE farm_id = '".self::FARM_ID."'"
        );

        DB::table('overdrafts')->where('farm_id', self::FARM_ID)->delete();
        DB::table('farms')->where('farm_id', self::FARM_ID)->delete();
        DB::table('accounts')->where('account_id', self::ACCOUNT_ID)->delete();

        DB::table('farms')->insert([
            'farm_id' => self::FARM_ID,
            'farm_type' => 'dairy',
            'region' => self::REGION,
            // Zero, so the closing balance is entirely the expense. An opening
            // balance would still work but would stop the oracle's numbers
            // being readable straight off the page.
            'opening_balance' => 0,
        ]);

        DB::table('accounts')->insert([
            'account_id' => self::ACCOUNT_ID,
            'account_name' => 'Overdraft Oracle Expense',
            'account_class' => 'EXPENSE',
            'account_category' => 'operating_expenses',
            'report_group' => null,
            'report_group_label' => null,
            'report_group_order' => 0,
            'line_order' => 0,
            'is_gst_account' => false,
            'is_default_bank_account' => false,
        ]);

        DB::table('overdrafts')->insert([
            'farm_id' => self::FARM_ID,
            'rate' => self::RATE,
            'overdraft_limit' => 0,
            'start_date' => '2024-01-01',
            'payment_term' => $paymentTerm,
        ]);

        $expense = self::EXPENSE;
        $this->db->query(<<<SQL
            INSERT INTO {$this->alias}.transaction_lines
                (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id)
            VALUES (
                '{$this->farmId()}', 'dairy', '{$this->region()}',
                'od-oracle-1', '{$this->accountId()}', 'actuals', 'cash',
                DATE '2024-01-15', {$expense}, NULL
            )
            SQL);
    }

    /** @return list<int> Expected posted amounts in x10,000 units, months 1-12. */
    public static function expectedMonthly(): array
    {
        return [41666, 41840, 42014, 42189, 42365, 42541, 42719, 42897, 43075, 43255, 43435, 43616];
    }

    private function farmId(): string
    {
        return self::FARM_ID;
    }

    private function region(): string
    {
        return self::REGION;
    }

    private function accountId(): string
    {
        return self::ACCOUNT_ID;
    }
}
