<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;
use InvalidArgumentException;

/**
 * A farm's position (what it owns, what it owes, its net worth) at chosen
 * month ends, in one or more versions of the forecast, from the same report
 * lines as its profit and loss and cash flow, accumulated from the day the
 * ledger opens (`$period_from`) to `$period_to`:
 *
 *   - bank: the cash flow's own balance, every cash line's effect on cash
 *     (shown as an overdraft when below zero);
 *   - livestock: the herd valuation at the month end;
 *   - fixed assets: the accrual balance of the fixed asset accounts: land and
 *     buildings, and everything else (plant and machinery, bought through
 *     capital purchases): opening value, purchases and sales, depreciation;
 *   - term loans: the accrual balance of the term liability accounts;
 *   - GST: owed to or refundable from Inland Revenue, on the payments basis
 *     the cash flow keeps it on;
 *   - money owed to the farm and by it: for every other account, what the
 *     accrual books have recognised and the cash books not yet seen (income
 *     earned not yet received; costs incurred not yet paid), excluding GST,
 *     which the farm settles with Inland Revenue on payment.
 *
 * Because every item comes from those lines, the change in net worth between
 * two dates is exactly net profit (livestock valuation change included) plus
 * the equity movements (drawings, opening balances) between them; each point
 * carries that reconciliation, and `unexplained` is what is left over.
 */
final class PositionSqlBuilder
{
    private const int FIXED_POINT = 10000;

    /**
     * @param non-empty-list<ReportSide> $sides
     * @param non-empty-array<string, list<string>> $points each side's key => the month ends (Y-m-d) it is shown at
     */
    public function __construct(
        private readonly int $farmId,
        private readonly array $sides,
        private readonly array $points,
    ) {
        foreach ($sides as $side) {
            if ($side->monthOffset !== 0 || $side->actualsOnly) {
                throw new InvalidArgumentException('A position is read at its own dates; use the date, not an offset.');
            }
        }
        foreach ($points as $dates) {
            foreach ($dates as $date) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                    throw new InvalidArgumentException("A position date is Y-m-d, not {$date}.");
                }
            }
        }
    }

    public function build(): string
    {
        $fp = self::FIXED_POINT;
        $ctes = [];
        $series = [];
        foreach ($this->sides as $side) {
            $k = $side->key;
            $ctes[] = "{$k}_accrual AS (\n{$this->accrual($side)}\n)";
            $ctes[] = "{$k}_cash AS (\n{$this->cash($side)}\n)";
            $series[] = $this->series($k);
        }
        $ctes = implode(",\n", $ctes);
        $series = implode("\n    UNION ALL\n    ", $series);
        $points = implode(', ', array_merge(...array_map(
            static fn (string $key, array $dates): array => array_map(static fn (string $d): string => "('{$key}', DATE '{$d}')", $dates),
            array_keys($this->points),
            $this->points,
        )));

        return <<<SQL
            WITH
            {$ctes},
            series AS (
                {$series}
            ),
            points(side, as_at) AS (
                VALUES {$points}
            ),
            shown AS (
                SELECT s.*,
                       LAG(s.net_worth) OVER previous AS previous_net_worth,
                       s.profit_to_date - LAG(s.profit_to_date) OVER previous AS profit_since,
                       s.equity_to_date - LAG(s.equity_to_date) OVER previous AS equity_since,
                       LAG(s.as_at) OVER previous AS since
                FROM series s
                JOIN points p ON p.side = s.side AND p.as_at = s.as_at
                WINDOW previous AS (PARTITION BY s.side ORDER BY s.as_at)
            )
            SELECT side, strftime(as_at, '%Y-%m-%d') AS as_at,
                   round(greatest(bank, 0) / {$fp}, 2) AS bank,
                   round(livestock / {$fp}, 2) AS livestock,
                   round(land_buildings / {$fp}, 2) AS land_buildings,
                   round(plant_machinery / {$fp}, 2) AS plant_machinery,
                   round(receivables / {$fp}, 2) AS owed_to_farm,
                   round((greatest(bank, 0) + livestock + land_buildings + plant_machinery + receivables) / {$fp}, 2) AS total_assets,
                   round(greatest(-bank, 0) / {$fp}, 2) AS overdraft,
                   round(term_loans / {$fp}, 2) AS term_loans,
                   round(payables / {$fp}, 2) AS owed_by_farm,
                   round(gst_owed / {$fp}, 2) AS gst_owed,
                   round((greatest(-bank, 0) + term_loans + payables + gst_owed) / {$fp}, 2) AS total_liabilities,
                   round(net_worth / {$fp}, 2) AS net_worth,
                   CASE WHEN since IS NOT NULL THEN strftime(since, '%Y-%m-%d') END AS since,
                   round(profit_since / {$fp}, 2) AS net_profit_since,
                   round(equity_since / {$fp}, 2) AS equity_movements_since,
                   round((net_worth - previous_net_worth) / {$fp}, 2) AS net_worth_change_since,
                   round((net_worth - previous_net_worth - profit_since - equity_since) / {$fp}, 2) AS unexplained
            FROM shown
            ORDER BY side, as_at
            SQL;
    }

    /**
     * One side's month-end balances from the ledger's opening: cumulative
     * sums of the accrual and cash flows, by what each account is.
     */
    private function series(string $k): string
    {
        return <<<SQL
            SELECT '{$k}' AS side, m.as_at,
                   -SUM(COALESCE(c.all_lines, 0)) OVER running_{$k} AS bank,
                   COALESCE(st.value, 0) AS livestock,
                   SUM(COALESCE(a.land, 0)) OVER running_{$k} AS land_buildings,
                   SUM(COALESCE(a.plant, 0)) OVER running_{$k} AS plant_machinery,
                   -SUM(COALESCE(a.loans, 0)) OVER running_{$k} AS term_loans,
                   -SUM(COALESCE(c.gst, 0)) OVER running_{$k} AS gst_owed,
                   -SUM(COALESCE(a.income, 0) - COALESCE(c.income, 0)) OVER running_{$k} AS receivables,
                   SUM(COALESCE(a.other, 0) - COALESCE(c.other, 0)) OVER running_{$k} AS payables,
                   -SUM(COALESCE(a.profit, 0)) OVER running_{$k} AS profit_to_date,
                   -SUM(COALESCE(a.equity, 0)) OVER running_{$k} AS equity_to_date,
                   -SUM(COALESCE(c.all_lines, 0)) OVER running_{$k} + COALESCE(st.value, 0)
                       + SUM(COALESCE(a.land, 0) + COALESCE(a.plant, 0)) OVER running_{$k}
                       + SUM(COALESCE(a.loans, 0)) OVER running_{$k}
                       + SUM(COALESCE(c.gst, 0)) OVER running_{$k}
                       - SUM(COALESCE(a.income, 0) - COALESCE(c.income, 0)) OVER running_{$k}
                       - SUM(COALESCE(a.other, 0) - COALESCE(c.other, 0)) OVER running_{$k} AS net_worth
            FROM (
                SELECT DISTINCT CAST(ms + INTERVAL '1 month' - INTERVAL '1 day' AS DATE) AS as_at, ms
                FROM (SELECT ms FROM {$k}_accrual UNION SELECT ms FROM {$k}_cash)
            ) m
            LEFT JOIN (
                SELECT ms,
                       SUM(amount) FILTER (WHERE what = 'flow' AND account_type = 'FIXED' AND category = 'Land & Buildings') AS land,
                       SUM(amount) FILTER (WHERE what = 'flow' AND account_type = 'FIXED' AND category IS DISTINCT FROM 'Land & Buildings') AS plant,
                       SUM(amount) FILTER (WHERE what = 'flow' AND account_type = 'TERMLIAB') AS loans,
                       SUM(amount) FILTER (WHERE what = 'flow' AND counted AND grp IN ('income', 'non_operating_income')) AS income,
                       SUM(amount) FILTER (WHERE what = 'flow' AND counted AND grp NOT IN ('income', 'non_operating_income')) AS other,
                       SUM(amount) FILTER (WHERE what = 'flow' AND grp IN ({$this->profitAndLossGroups()})) AS profit,
                       SUM(amount) FILTER (WHERE what = 'flow' AND account_type = 'EQUITY') AS equity
                FROM {$k}_accrual
                GROUP BY ms
            ) a ON a.ms = m.ms
            LEFT JOIN (
                SELECT ms, SUM(amount) AS all_lines,
                       SUM(amount) FILTER (WHERE NOT counted) AS gst,
                       SUM(amount) FILTER (WHERE counted AND grp IN ('income', 'non_operating_income')) AS income,
                       SUM(amount) FILTER (WHERE counted AND grp NOT IN ('income', 'non_operating_income')) AS other
                FROM {$k}_cash
                GROUP BY ms
            ) c ON c.ms = m.ms
            LEFT JOIN (SELECT ms, amount AS value FROM {$k}_accrual WHERE what = 'livestock') st ON st.ms = m.ms
            WINDOW running_{$k} AS (ORDER BY m.as_at)
            SQL;
    }

    /**
     * The side's accrual lines on every account but the bank and payables,
     * by month and what the account is, and the herd's value at each month
     * end. `counted` marks the accounts whose accrual and cash timings
     * differ by money owed: not GST, and not the livestock valuation, whose
     * other side is the herd itself.
     */
    private function accrual(ReportSide $side): string
    {
        $lines = new ReportLinesSqlBuilder([$this->farmId], ReportBasis::Accrual, $side->horizonParameter, $side->edits, overdraftInterest: true, balanceSheetAccounts: true);

        return ReportLinesSqlBuilder::statement(
            $lines->ctes(ReportLinesSqlBuilder::periodWindow()),
            <<<'SQL'
                SELECT 'flow' AS what, CAST(date_trunc('month', l.date) AS DATE) AS ms, a.account_type, l.grp, l.category,
                       a.system_account IS DISTINCT FROM 'GST' AND a.system_account IS DISTINCT FROM 'GSTPAYMENTS'
                           AND l.category IS DISTINCT FROM 'Livestock Valuation Change' AS counted,
                       CAST(SUM(l.amount) AS DOUBLE) AS amount
                FROM report_lines l
                JOIN accounts a ON a.farm_id = l.farm_id AND a.accountid = l.account_id
                GROUP BY ALL
                UNION ALL
                SELECT 'livestock', ms, NULL, NULL, NULL, false, CAST(SUM(value) AS DOUBLE)
                FROM stock_values
                GROUP BY ms
                SQL,
        );
    }

    /** The side's cash lines, by month and what the account is; the bank moves by their opposite. */
    private function cash(ReportSide $side): string
    {
        $lines = new ReportLinesSqlBuilder([$this->farmId], ReportBasis::Cash, $side->horizonParameter, $side->edits, overdraftInterest: true);

        return ReportLinesSqlBuilder::statement(
            $lines->ctes(ReportLinesSqlBuilder::periodWindow()),
            <<<'SQL'
                SELECT CAST(date_trunc('month', l.date) AS DATE) AS ms, a.account_type, l.grp, l.category,
                       a.system_account IS DISTINCT FROM 'GST' AND a.system_account IS DISTINCT FROM 'GSTPAYMENTS' AS counted,
                       CAST(SUM(l.amount) AS DOUBLE) AS amount
                FROM report_lines l
                JOIN accounts a ON a.farm_id = l.farm_id AND a.accountid = l.account_id
                GROUP BY ALL
                SQL,
        );
    }

    private function profitAndLossGroups(): string
    {
        return implode(', ', array_map(static fn (string $g): string => "'{$g}'", ReportBasis::PROFIT_AND_LOSS_GROUPS));
    }
}
