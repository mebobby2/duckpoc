<?php

declare(strict_types=1);

namespace App\Services\Insights;

/**
 * The one place the report business logic lives: every rule that turns
 * journals into cash-basis report lines, for any number of farms.
 *
 * Both consumers build on these CTEs and add only their own presentation:
 * the single-farm cash flow (InsightsCashFlowSqlBuilder) buckets the lines by
 * month into sections, and Portfolio Modelling (PortfolioModellingSqlBuilder)
 * buckets them by season and applies assumptions. Neither classifies,
 * filters or synthesises a line itself, so a change to a rule here reaches
 * both, and `insights:portfolio --check-cashflow` proves they still agree.
 *
 * The rules, in the order the cash flow phase's Figured reproduction applies
 * them:
 *
 *   1. the scan: cash-basis lines, actuals to the horizon and forecast after,
 *      summed to (farm, date, account) on raw columns so the column store can
 *      aggregate inside the scan;
 *   2. opening: each farm's bank balance the day before its window;
 *   3. milk virtual journals: production x price for cheques after the
 *      horizon;
 *   4. GST virtual journals: two-monthly settlements predicted after the
 *      horizon, and recorded settlements moved to the payments line;
 *   5. report lines: ledger plus virtual journals, bank lines out, each line
 *      carrying its account's category, inside its farm's window.
 *
 * The consumer supplies `report_window(farm_id, window_start, window_end)`,
 * one row per farm, and binds `:farm_ids`, `:horizon`, `:period_from`,
 * `:period_to` (the widest window) and `:opening_before` (the latest window
 * start).
 */
final class ReportLinesSqlBuilder
{
    /** Fonterra-style: production in month M is paid on the 20th of M+1. */
    private const string MILK_PAYMENT_OFFSET = "INTERVAL '1 month 19 days'";

    /**
     * @param string $reportWindowBody the consumer's `report_window` CTE body
     * @return array<string, string> name => body, in dependency order
     */
    public function ctes(string $reportWindowBody): array
    {
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
     * The farm filter on the journal table. The farm list goes through a
     * subquery rather than straight into `= ANY`: a literal array of 250
     * farms made AlloyDB drop the column store and scan the heap, where the
     * subquery's array is evaluated once at run time and the columnar scan
     * applies it.
     */
    public static function journalFarmFilter(string $alias = 'tl'): string
    {
        return "{$alias}.farm_id = ANY (ARRAY(SELECT unnest(CAST(:farm_ids AS INTEGER[]))))";
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
            SQL;
    }

    /**
     * The ledger summed to (farm, month, account), and GST settlements kept
     * at their own dates.
     *
     * Month, not day: every reader buckets by month or season, and on the
     * 250-farm practice grouping by day left 3M groups out of 21M lines, whose
     * gather and sort were 5 of the statement's 9 seconds. Grouping on an
     * expression gives up the column store's in-scan aggregation, but each
     * worker still pre-aggregates its share, and the groups are 7x fewer.
     * Settlements are matched against payment dates, so they keep the day;
     * there are a few per farm per year.
     */
    private function scanCte(): string
    {
        $s = InsightsSchema::SCHEMA;
        $tag = InsightsPracticeSeeder::TAG_GST_PAYMENT;
        $where = $this->scanPredicate();

        return <<<SQL
            SELECT tl.farm_id, date_trunc('month', tl.date)::DATE AS date, tl.account_id, CAST(NULL AS TEXT) AS tag, CAST(SUM(tl.net_amount) AS BIGINT) AS amount
            FROM {$s}.transaction_lines tl
            {$where}
              AND (tl.tag IS NULL OR tl.tag <> '{$tag}')
            GROUP BY 1, 2, 3
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
}
