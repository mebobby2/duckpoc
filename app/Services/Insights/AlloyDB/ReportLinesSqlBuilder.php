<?php

declare(strict_types=1);

namespace App\Services\Insights\AlloyDB;

use App\Services\Insights\ReportBasis;

/**
 * The one place the report business logic lives: every rule that turns
 * journals into report lines, on either basis, for any number of farms.
 *
 * Every consumer builds on these CTEs and adds only its own presentation:
 * the single-farm cash flow (InsightsCashFlowSqlBuilder) and profit and loss
 * (InsightsProfitLossSqlBuilder) bucket the lines by month into sections,
 * and Portfolio Modelling (PortfolioModellingSqlBuilder) buckets them by
 * season and applies assumptions. None classifies, filters or synthesises a
 * line itself, so a change to a rule here reaches all of them, and
 * `insights:portfolio --check-single` proves they still agree.
 *
 * Cash basis, in the order the cash flow phase's Figured reproduction
 * applies the rules:
 *
 *   1. the scan: cash-basis lines, actuals to the horizon and forecast after,
 *      bank legs out, summed to (farm, month, account);
 *   2. opening: each farm's bank balance the day before its window;
 *   3. milk virtual journals: production x price for cheques after the
 *      horizon;
 *   4. GST virtual journals: two-monthly settlements predicted after the
 *      horizon, and recorded settlements moved to the payments line;
 *   5. report lines: ledger plus virtual journals, each line carrying its
 *      account's category, inside its farm's window.
 *
 * Accrual basis, the profit and loss:
 *
 *   1. the scan: accrual-basis lines on profit and loss accounts only, so
 *      payables, GST, bank, loans, capital and drawings never leave the
 *      column store;
 *   2. milk virtual journals: production x price in the month produced, for
 *      production not yet invoiced by the horizon;
 *   3. livestock valuation: each class's head at every month end, valued at
 *      its season's rate per head; the month's change in value is a non-cash
 *      income line, the virtual journal Figured computes in PHP;
 *   4. report lines: ledger plus virtual journals inside each farm's window.
 *
 * The consumer supplies `report_window(farm_id, window_start, window_end)`,
 * one row per farm, and binds `:farm_ids`, `:horizon`, `:period_from` and
 * `:period_to` (the widest window), plus `:opening_before` (the latest
 * window start) on cash basis.
 */
final class ReportLinesSqlBuilder
{
    /** Fonterra-style: production in month M is paid on the 20th of M+1. */
    private const string MILK_PAYMENT_OFFSET = "INTERVAL '1 month 19 days'";

    public function __construct(
        public readonly ReportBasis $basis = ReportBasis::Cash,
    ) {
    }

    /**
     * @param string $reportWindowBody the consumer's `report_window` CTE body
     * @return array<string, string> name => body, in dependency order
     */
    public function ctes(string $reportWindowBody): array
    {
        if ($this->basis === ReportBasis::Accrual) {
            return [
                'farm' => $this->farmCte(),
                'report_window' => $reportWindowBody,
                'accounts' => $this->accountsCte(),
                'scan' => $this->accrualScanCte(),
                'milk_vj' => $this->accrualMilkVjCte(),
                'stock_net' => $this->stockNetCte(),
                'stock_heads' => $this->stockHeadsCte(),
                'stock_values' => $this->stockValuesCte(),
                'valuation_vj' => $this->valuationVjCte(),
                'report_lines' => $this->accrualReportLinesCte(),
            ];
        }

        return [
            'farm' => $this->farmCte(),
            'report_window' => $reportWindowBody,
            'accounts' => $this->accountsCte(),
            'scan' => $this->scanCte(),
            'opening' => $this->openingCte(),
            'milk_vj' => $this->milkVjCte(),
            'gst_accounts' => $this->gstAccountsCte(),
            'gst_net_by_month' => $this->gstNetByMonthCte(),
            'gst_settlements' => $this->gstSettlementsCte(),
            'gst_payment_dates' => $this->gstPaymentDatesCte(),
            'gst_window_net' => $this->gstWindowNetCte(),
            'gst_predicted' => $this->gstPredictedCte(),
            'gst_vj' => $this->gstVjCte(),
            'report_lines' => $this->reportLinesCte(),
        ];
    }

    /** CTEs whose result every later reader re-reads, so they run once. */
    public static function materialized(): array
    {
        return ['accounts', 'scan', 'report_lines'];
    }

    /**
     * @param array<string, string> $ctes name => body, in dependency order
     */
    public static function statement(array $ctes, string $select): string
    {
        $parts = [];
        foreach ($ctes as $name => $body) {
            $materialized = in_array($name, self::materialized(), true) ? ' AS MATERIALIZED' : ' AS';
            $parts[] = "{$name}{$materialized} (\n{$body}\n)";
        }

        return "WITH\n".implode(",\n\n", $parts)."\n\n".$select;
    }

    /**
     * The cash flow phase's AlloyDB spelling of the horizon split: the `OR`
     * form lets the column store evaluate it, where the CASE form does not.
     */
    public static function horizonPredicate(string $alias = 'tl'): string
    {
        return "(({$alias}.date <= CAST(:horizon AS DATE) AND {$alias}.type = 'actuals') OR ({$alias}.date > CAST(:horizon AS DATE) AND {$alias}.type = 'forecast'))";
    }

    /**
     * The farm filter on the journal table, as a semi-join on the farm list.
     *
     * Two simpler spellings each lost a column-store benefit on the 250-farm
     * practice. A literal `= ANY ('{...}')` array makes AlloyDB skip the
     * column store and read the index. `= ANY (ARRAY(subquery))` keeps the
     * columnar scan, but the planner guesses such an array holds ten
     * elements, expects 850K rows instead of 21M, and groups them all in one
     * process: 3.3 s. As a semi-join the farm list has a known size, and each
     * worker aggregates its own share: 1.3 s.
     */
    public static function journalFarmFilter(string $alias = 'tl'): string
    {
        return "{$alias}.farm_id IN (SELECT unnest(CAST(:farm_ids AS INTEGER[])))";
    }

    private function farmCte(): string
    {
        $s = InsightsSchema::SCHEMA;

        return <<<SQL
            SELECT f.id AS farm_id, f.financial_year_end_month AS fy_month, f.financial_year_end_day AS fy_day
            FROM {$s}.farms f
            WHERE f.id = ANY (CAST(:farm_ids AS INTEGER[])) AND f._valid_to IS NULL
            SQL;
    }

    /**
     * Each farm's chart with its category. Joined on the account id alone
     * with the farm as a filter: a two-column join estimate multiplies the
     * two selectivities, which put this CTE at one row, and the planner then
     * joined every scanned group to it in a nested loop.
     */
    private function accountsCte(): string
    {
        $s = InsightsSchema::SCHEMA;

        return <<<SQL
            SELECT xa.farm_id, xa.accountid, xa.type AS account_type, xa.system_account,
                   c."group" AS grp, c.system_category_name AS category
            FROM {$s}.xero_accounts xa
            JOIN {$s}.category_xero_account cxa ON cxa.xero_account_id = xa.accountid
            JOIN {$s}.categories c ON c.id = cxa.category_id
            WHERE xa.farm_id = ANY (CAST(:farm_ids AS INTEGER[]))
              AND cxa.farm_id = xa.farm_id
              AND xa._valid_to IS NULL AND c._valid_to IS NULL
            SQL;
    }

    /**
     * Literal casts rather than a join to a parameters CTE: a filter the scan
     * can see is one the column store applies before it hands rows up.
     *
     * Bank legs leave here rather than in `report_lines`: they are a third of
     * the cash lines, every reader drops them, and the opening balance reads
     * them separately. Filtered in the column store they are never grouped,
     * which took the 250-farm statement from 1.44 s to 1.21 s.
     */
    private function scanPredicate(): string
    {
        $horizon = self::horizonPredicate();
        $farms = self::journalFarmFilter();

        return <<<SQL
            WHERE {$farms}
                  AND tl.basis = 'cash'
                  AND tl.date BETWEEN CAST(:period_from AS DATE) AND CAST(:period_to AS DATE)
                  AND {$horizon}
                  AND tl.account_id <> ALL (ARRAY(SELECT accountid FROM accounts WHERE account_type = 'BANK'))
            SQL;
    }

    /**
     * The ledger summed to (farm, month, account), and GST settlements kept
     * at their own dates.
     *
     * Month, not day: every reader buckets by month or season, and on the
     * 250-farm practice grouping by day left 3M groups out of 21M lines. The
     * month is the stored `month` column rather than date_trunc(date):
     * grouping on an expression stops the column store aggregating inside
     * its scan, and the planner then gathered all 21M lines into one process.
     * Settlements are matched against payment dates, so they keep the day;
     * there are a few per farm per year.
     */
    private function scanCte(): string
    {
        $s = InsightsSchema::SCHEMA;
        $tag = InsightsPracticeSeeder::TAG_GST_PAYMENT;
        $where = $this->scanPredicate();

        return <<<SQL
            SELECT tl.farm_id, tl.month AS date, tl.account_id, CAST(NULL AS TEXT) AS tag, CAST(SUM(tl.net_amount) AS BIGINT) AS amount
            FROM {$s}.transaction_lines tl
            {$where}
              AND (tl.tag IS NULL OR tl.tag <> '{$tag}')
            GROUP BY tl.farm_id, tl.month, tl.account_id
            UNION ALL
            SELECT tl.farm_id, tl.date, tl.account_id, '{$tag}', CAST(SUM(tl.net_amount) AS BIGINT)
            FROM {$s}.transaction_lines tl
            {$where}
              AND tl.tag = '{$tag}'
            GROUP BY tl.farm_id, tl.date, tl.account_id
            SQL;
    }

    /**
     * The bank balance the day before each farm's window. One scan of the
     * farms' bank lines up to the latest window start, the bank ids filtering
     * inside it, then each farm cut at its own start.
     */
    private function openingCte(): string
    {
        $s = InsightsSchema::SCHEMA;
        $horizon = self::horizonPredicate();
        $farms = self::journalFarmFilter();

        return <<<SQL
            SELECT w.farm_id, COALESCE(SUM(b.amount), 0) AS opening_balance
            FROM report_window w
            LEFT JOIN (
                SELECT tl.farm_id, tl.date, CAST(SUM(tl.net_amount) AS BIGINT) AS amount
                FROM {$s}.transaction_lines tl
                WHERE {$farms}
                  AND tl.basis = 'cash'
                  AND tl.date < CAST(:opening_before AS DATE)
                  AND tl.account_id = ANY (ARRAY(SELECT accountid FROM accounts WHERE account_type = 'BANK'))
                  AND {$horizon}
                GROUP BY tl.farm_id, tl.date
            ) b ON b.farm_id = w.farm_id AND b.date < w.window_start
            GROUP BY w.farm_id
            SQL;
    }

    private function milkVjCte(): string
    {
        $s = InsightsSchema::SCHEMA;
        $offset = self::MILK_PAYMENT_OFFSET;

        return <<<SQL
            SELECT mt.farm_id, mt.income_accountid AS account_id,
                   (mp.transaction_date + {$offset})::DATE AS date,
                   -(CAST(mp.production AS BIGINT) * pr.price) AS amount
            FROM {$s}.milk_productions mp
            JOIN {$s}.milk_trackers mt ON mt.id = mp.milk_tracker_id AND mt._valid_to IS NULL
            JOIN {$s}.milk_tracker_prices pr ON pr.milk_tracker_id = mt.id AND pr.month = mp.transaction_date
            WHERE mp.farm_id = ANY (CAST(:farm_ids AS INTEGER[]))
              AND mp._valid_to IS NULL AND mp.budget_id = 0 AND mp.production > 0
              AND (mp.transaction_date + {$offset})::DATE > CAST(:horizon AS DATE)
              AND (mp.transaction_date + {$offset})::DATE <= CAST(:period_to AS DATE)
            SQL;
    }

    private function gstAccountsCte(): string
    {
        return <<<SQL
            SELECT farm_id,
                   max(accountid) FILTER (WHERE system_account = 'GST') AS gst_id,
                   max(accountid) FILTER (WHERE system_account = 'GSTPAYMENTS') AS payments_id
            FROM accounts
            GROUP BY farm_id
            SQL;
    }

    /** Net GST per month, tax components only; settlements carry the tag and are handled apart. */
    private function gstNetByMonthCte(): string
    {
        return <<<SQL
            SELECT l.farm_id, date_trunc('month', l.date)::DATE AS ms, SUM(l.amount) AS net
            FROM scan l
            JOIN gst_accounts g ON g.farm_id = l.farm_id AND l.account_id = g.gst_id
            WHERE l.tag IS NULL
            GROUP BY 1, 2
            SQL;
    }

    private function gstSettlementsCte(): string
    {
        $tag = InsightsPracticeSeeder::TAG_GST_PAYMENT;

        return <<<SQL
            SELECT l.farm_id, l.date, l.amount
            FROM scan l
            JOIN gst_accounts g ON g.farm_id = l.farm_id AND l.account_id = g.gst_id
            WHERE l.tag = '{$tag}'
            SQL;
    }

    /**
     * NZ two-monthly GST on a payments basis: a return ends every second
     * month from the balance date and is paid on the 28th of the month
     * after, except November's (15 January) and March's (7 May). A payment
     * month's window is the two months before it.
     */
    private function gstPaymentDatesCte(): string
    {
        return <<<SQL
            SELECT w.farm_id,
                   CASE
                       WHEN EXTRACT(MONTH FROM m) = 12 THEN make_date(EXTRACT(YEAR FROM m)::int + 1, 1, 15)
                       WHEN EXTRACT(MONTH FROM m) = 4 THEN make_date(EXTRACT(YEAR FROM m)::int, 5, 7)
                       ELSE make_date(EXTRACT(YEAR FROM m)::int, EXTRACT(MONTH FROM m)::int, 28)
                   END AS pay_date,
                   (m - INTERVAL '2 months')::DATE AS window_start,
                   (m - INTERVAL '1 day')::DATE AS window_end
            FROM report_window w
            JOIN farm f ON f.farm_id = w.farm_id
            CROSS JOIN generate_series(date_trunc('month', w.window_start), w.window_end, INTERVAL '1 month') AS m
            WHERE EXTRACT(MONTH FROM m)::int % 2 = (f.fy_month + 1) % 2
            SQL;
    }

    private function gstWindowNetCte(): string
    {
        return <<<SQL
            SELECT pd.farm_id, pd.pay_date, pd.window_end, COALESCE(SUM(n.net), 0) AS net
            FROM gst_payment_dates pd
            LEFT JOIN gst_net_by_month n ON n.farm_id = pd.farm_id AND n.ms BETWEEN pd.window_start AND pd.window_end
            GROUP BY 1, 2, 3
            SQL;
    }

    /**
     * The predicted settlement after the horizon: the window's net GST as a
     * cash movement, less anything already paid against it.
     */
    private function gstPredictedCte(): string
    {
        return <<<SQL
            SELECT w.farm_id, g.payments_id AS account_id, w.pay_date AS date,
                   -w.net - COALESCE(SUM(st.amount), 0) AS amount
            FROM gst_window_net w
            JOIN gst_accounts g ON g.farm_id = w.farm_id
            LEFT JOIN gst_settlements st ON st.farm_id = w.farm_id AND st.date > w.window_end AND st.date <= w.pay_date
            WHERE w.pay_date > CAST(:horizon AS DATE)
            GROUP BY w.farm_id, g.payments_id, w.pay_date, w.net
            SQL;
    }

    private function gstVjCte(): string
    {
        return <<<SQL
            SELECT farm_id, account_id, date, amount FROM gst_predicted WHERE amount <> 0
            UNION ALL
            SELECT st.farm_id, g.gst_id, st.date, -st.amount FROM gst_settlements st JOIN gst_accounts g ON g.farm_id = st.farm_id
            UNION ALL
            SELECT st.farm_id, g.payments_id, st.date, st.amount FROM gst_settlements st JOIN gst_accounts g ON g.farm_id = st.farm_id
            SQL;
    }

    /**
     * Ledger plus virtual journals, bank lines out (they are the balance, not
     * a movement), each in its farm's window with its account's category.
     * Amounts keep the ledger's sign: revenue credit-negative, everything
     * else debit-positive.
     */
    private function reportLinesCte(): string
    {
        return <<<SQL
            SELECT l.farm_id, l.date, l.account_id, l.amount, a.grp, a.category
            FROM (
                SELECT farm_id, date, account_id, amount FROM scan
                UNION ALL
                SELECT farm_id, date, account_id, amount FROM milk_vj
                UNION ALL
                SELECT farm_id, date, account_id, amount FROM gst_vj
            ) l
            JOIN accounts a ON a.farm_id = l.farm_id AND a.accountid = l.account_id
            JOIN report_window w ON w.farm_id = l.farm_id
            WHERE a.account_type <> 'BANK'
              AND l.date BETWEEN date_trunc('month', w.window_start)::DATE AND w.window_end
            SQL;
    }

    private static function profitAndLossGroups(): string
    {
        return implode(', ', array_map(static fn (string $g): string => "'{$g}'", ReportBasis::PROFIT_AND_LOSS_GROUPS));
    }

    /**
     * Accrual lines on profit and loss accounts, summed to (farm, month,
     * account). The balance-sheet legs (payables, GST, bank, loans, capital)
     * are most of the accrual lines and no reader of a P&L wants them, so the
     * column store drops them in its scan.
     *
     * Written as an exclusion, not `= ANY` over the P&L accounts: the planner
     * guesses an array from a subquery holds ten elements, so the inclusion
     * looked 600x more selective than it is and the 7M surviving lines were
     * grouped in one process (1.8 s). An exclusion it estimates as keeping
     * most rows, and each worker aggregates its own share.
     */
    private function accrualScanCte(): string
    {
        $s = InsightsSchema::SCHEMA;
        $horizon = self::horizonPredicate();
        $farms = self::journalFarmFilter();
        $groups = self::profitAndLossGroups();

        return <<<SQL
            SELECT tl.farm_id, tl.month AS date, tl.account_id, CAST(SUM(tl.net_amount) AS BIGINT) AS amount
            FROM {$s}.transaction_lines tl
            WHERE {$farms}
                  AND tl.basis = 'accrual'
                  AND tl.date BETWEEN CAST(:period_from AS DATE) AND CAST(:period_to AS DATE)
                  AND {$horizon}
                  AND tl.account_id <> ALL (ARRAY(SELECT accountid FROM accounts WHERE grp NOT IN ({$groups})))
            GROUP BY tl.farm_id, tl.month, tl.account_id
            SQL;
    }

    /**
     * On accrual, milk is income in the month it is produced. Production
     * whose month closes after the horizon has no invoice yet, so it is
     * priced here, dated to the month like the ledger it sits beside.
     */
    private function accrualMilkVjCte(): string
    {
        $s = InsightsSchema::SCHEMA;

        return <<<SQL
            SELECT mt.farm_id, mt.income_accountid AS account_id,
                   mp.transaction_date AS date,
                   -(CAST(mp.production AS BIGINT) * pr.price) AS amount
            FROM {$s}.milk_productions mp
            JOIN {$s}.milk_trackers mt ON mt.id = mp.milk_tracker_id AND mt._valid_to IS NULL
            JOIN {$s}.milk_tracker_prices pr ON pr.milk_tracker_id = mt.id AND pr.month = mp.transaction_date
            WHERE mp.farm_id = ANY (CAST(:farm_ids AS INTEGER[]))
              AND mp._valid_to IS NULL AND mp.budget_id = 0 AND mp.production > 0
              AND (mp.transaction_date + INTERVAL '1 month' - INTERVAL '1 day')::DATE > CAST(:horizon AS DATE)
              AND mp.transaction_date <= CAST(:period_to AS DATE)
            SQL;
    }

    /**
     * Each class's net movement per month: arrivals (opening, purchase,
     * birth) in, departures (sale, death) out. Actual movements to the
     * horizon, forecast after, the same split as the journals.
     */
    private function stockNetCte(): string
    {
        $s = InsightsSchema::SCHEMA;

        return <<<SQL
            SELECT st.farm_id, st.tracker_id, st.stock_class_uuid,
                   date_trunc('month', st.transaction_date)::DATE AS ms,
                   SUM(CASE WHEN st.transition IN ('opening', 'purchase', 'birth') THEN st.quantity ELSE -st.quantity END) AS net
            FROM {$s}.stock_transactions st
            WHERE st.farm_id = ANY (CAST(:farm_ids AS INTEGER[]))
              AND st._valid_to IS NULL AND st.budget_id = 0
              AND st.transaction_date <= CAST(:period_to AS DATE)
              AND ((st.transaction_date <= CAST(:horizon AS DATE) AND st.type = 'actual')
                OR (st.transaction_date > CAST(:horizon AS DATE) AND st.type = 'forecast'))
            GROUP BY 1, 2, 3, 4
            SQL;
    }

    /**
     * Head on hand at every month end, from each class's first movement to
     * its farm's window end, as a running sum, so a month with no movement
     * still carries its head.
     */
    private function stockHeadsCte(): string
    {
        return <<<SQL
            SELECT c.farm_id, c.tracker_id, c.stock_class_uuid, m::DATE AS ms,
                   SUM(COALESCE(n.net, 0)) OVER (PARTITION BY c.farm_id, c.tracker_id, c.stock_class_uuid ORDER BY m) AS head
            FROM (
                SELECT farm_id, tracker_id, stock_class_uuid, min(ms) AS first_ms
                FROM stock_net
                GROUP BY 1, 2, 3
            ) c
            JOIN report_window w ON w.farm_id = c.farm_id
            CROSS JOIN LATERAL generate_series(c.first_ms, w.window_end, INTERVAL '1 month') AS m
            LEFT JOIN stock_net n ON n.farm_id = c.farm_id AND n.tracker_id = c.tracker_id
                                 AND n.stock_class_uuid = c.stock_class_uuid AND n.ms = m::DATE
            SQL;
    }

    /** Each farm's livestock value at every month end: head x its season's value per head. */
    private function stockValuesCte(): string
    {
        $s = InsightsSchema::SCHEMA;

        return <<<SQL
            SELECT h.farm_id, h.ms, SUM(h.head * v.value_per_head) AS value
            FROM stock_heads h
            JOIN farm f ON f.farm_id = h.farm_id
            JOIN {$s}.stock_class_valuations v
              ON v.farm_id = h.farm_id AND v.tracker_id = h.tracker_id AND v.stock_class_uuid = h.stock_class_uuid
             AND v.season = EXTRACT(YEAR FROM h.ms)::int + CASE WHEN EXTRACT(MONTH FROM h.ms)::int > f.fy_month THEN 1 ELSE 0 END
            GROUP BY 1, 2
            SQL;
    }

    /**
     * The month's change in livestock value, posted to the farm's virtual
     * valuation account. A rise is income, so it is credit-negative like any
     * revenue line.
     */
    private function valuationVjCte(): string
    {
        return <<<SQL
            SELECT v.farm_id, a.accountid AS account_id, v.ms AS date, CAST(round(-(v.value - v.previous)) AS BIGINT) AS amount
            FROM (
                SELECT farm_id, ms, value, LAG(value) OVER (PARTITION BY farm_id ORDER BY ms) AS previous
                FROM stock_values
            ) v
            JOIN accounts a ON a.farm_id = v.farm_id AND a.category = 'Livestock Valuation Change'
            WHERE v.previous IS NOT NULL
            SQL;
    }

    /** Ledger plus virtual journals on profit and loss accounts, inside each farm's window. */
    private function accrualReportLinesCte(): string
    {
        $groups = self::profitAndLossGroups();

        return <<<SQL
            SELECT l.farm_id, l.date, l.account_id, l.amount, a.grp, a.category
            FROM (
                SELECT farm_id, date, account_id, amount FROM scan
                UNION ALL
                SELECT farm_id, date, account_id, amount FROM milk_vj
                UNION ALL
                SELECT farm_id, date, account_id, amount FROM valuation_vj
            ) l
            JOIN accounts a ON a.farm_id = l.farm_id AND a.accountid = l.account_id
            JOIN report_window w ON w.farm_id = l.farm_id
            WHERE a.grp IN ({$groups})
              AND l.date BETWEEN date_trunc('month', w.window_start)::DATE AND w.window_end
            SQL;
    }
}
