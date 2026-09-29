<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;
use InvalidArgumentException;

/**
 * The one place the DuckDB report business logic lives: every rule that
 * turns journals into report lines, on either basis, for any number of
 * farms. The DuckDB twin of the AlloyDB PoC's builder, rule for rule: same
 * steps, same order, same CTE names, so the two can be read side by side.
 *
 * Every consumer builds on these CTEs and adds only its own presentation:
 * the single-farm cash flow and profit and loss (InsightsMonthlyReportSqlBuilder)
 * bucket the lines by month, and Portfolio Modelling
 * (PortfolioModellingSqlBuilder) buckets them by season and applies
 * assumptions. None classifies, filters or synthesises a line itself, and
 * `insights:duckdb:portfolio --check-single` proves they still agree.
 *
 * Cash basis:
 *
 *   1. the scan: cash-basis lake lines, actuals to the horizon and forecast
 *      after, bank legs out, summed to (farm, month, account);
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
 *   1. the scan: accrual lake lines on profit and loss accounts only;
 *   2. milk virtual journals: production x price in the month produced, for
 *      production not yet invoiced by the horizon;
 *   3. livestock valuation: each class's head at every month end, valued at
 *      its season's rate per head; the month's change is non-cash income;
 *   4. report lines: ledger plus virtual journals inside each farm's window.
 *
 * Only the journal lines come from the lake; every other table is Figured's
 * MySQL shape, read through the attached MySQL database. The farm list is
 * written into the statement as literals rather than bound: it has to reach
 * the lake scan as a constant for DuckDB to prune row groups and files on it.
 * The consumer supplies `report_window(farm_id, window_start, window_end)`
 * and binds `$horizon`, `$period_from`, `$period_to` and, on cash basis,
 * `$opening_before`.
 */
final class ReportLinesSqlBuilder
{
    /** Fonterra-style: production in month M is paid on the 20th of M+1. */
    private const string MILK_PAYMENT_OFFSET = "INTERVAL '1 month' + INTERVAL '19 days'";

    private const string TAG_GST_PAYMENT = 'gst_payment';

    /** @var non-empty-list<int> */
    private readonly array $farmIds;

    /**
     * @param list<int> $farmIds
     */
    public function __construct(
        array $farmIds,
        public readonly ReportBasis $basis = ReportBasis::Cash,
    ) {
        if ($farmIds === []) {
            throw new InvalidArgumentException('A report needs at least one farm.');
        }
        $this->farmIds = array_values(array_map('intval', $farmIds));
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

    /** CTEs every later reader re-reads, so they run once. */
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

    public static function horizonPredicate(string $alias = 'tl', string $actual = 'actuals'): string
    {
        return "(({$alias}.date <= CAST(\$horizon AS DATE) AND {$alias}.type = '{$actual}') OR ({$alias}.date > CAST(\$horizon AS DATE) AND {$alias}.type = 'forecast'))";
    }

    /**
     * The farm list as literals, with its range beside it: the IN list is
     * the filter, and the BETWEEN is what DuckDB can check against each row
     * group's min/max statistics to skip the other farms' data without
     * reading it.
     */
    public function farmFilter(string $column): string
    {
        $list = implode(', ', $this->farmIds);

        return sprintf('%s BETWEEN %d AND %d AND %s IN (%s)', $column, min($this->farmIds), max($this->farmIds), $column, $list);
    }

    private static function mysql(string $table): string
    {
        return InsightsDuckDb::table($table);
    }

    private function farmCte(): string
    {
        $farms = self::mysql('farms');

        return <<<SQL
            SELECT f.id AS farm_id, CAST(f.financial_year_end_month AS INTEGER) AS fy_month, CAST(f.financial_year_end_day AS INTEGER) AS fy_day
            FROM {$farms} f
            WHERE {$this->farmFilter('f.id')} AND f._valid_to IS NULL
            SQL;
    }

    private function accountsCte(): string
    {
        $xa = self::mysql('xero_accounts');
        $cxa = self::mysql('category_xero_account');
        $c = self::mysql('categories');

        return <<<SQL
            SELECT xa.farm_id, xa.accountid, xa.type AS account_type, xa.system_account,
                   c."group" AS grp, c.system_category_name AS category
            FROM {$xa} xa
            JOIN {$cxa} cxa ON cxa.xero_account_id = xa.accountid AND cxa.farm_id = xa.farm_id
            JOIN {$c} c ON c.id = cxa.category_id
            WHERE {$this->farmFilter('xa.farm_id')}
              AND xa._valid_to IS NULL AND c._valid_to IS NULL
            SQL;
    }

    private function scanPredicate(string $basis): string
    {
        $horizon = self::horizonPredicate();

        return <<<SQL
            WHERE {$this->farmFilter('tl.farm_id')}
                  AND tl.basis = '{$basis}'
                  AND tl.date BETWEEN CAST(\$period_from AS DATE) AND CAST(\$period_to AS DATE)
                  AND {$horizon}
            SQL;
    }

    /**
     * The ledger summed to (farm, month, account), bank legs out, and GST
     * settlements kept at their own dates for the payment calendar.
     */
    private function scanCte(): string
    {
        $lines = InsightsDuckDb::lines();
        $tag = self::TAG_GST_PAYMENT;
        $where = $this->scanPredicate('cash');

        return <<<SQL
            SELECT tl.farm_id, tl.month AS date, tl.account_id, CAST(NULL AS VARCHAR) AS tag, CAST(SUM(tl.net_amount) AS BIGINT) AS amount
            FROM {$lines} tl
            {$where}
              AND (tl.tag IS NULL OR tl.tag <> '{$tag}')
              AND tl.account_id NOT IN (SELECT accountid FROM accounts WHERE account_type = 'BANK')
            GROUP BY tl.farm_id, tl.month, tl.account_id
            UNION ALL
            SELECT tl.farm_id, tl.date, tl.account_id, '{$tag}', CAST(SUM(tl.net_amount) AS BIGINT)
            FROM {$lines} tl
            {$where}
              AND tl.tag = '{$tag}'
              AND tl.account_id NOT IN (SELECT accountid FROM accounts WHERE account_type = 'BANK')
            GROUP BY tl.farm_id, tl.date, tl.account_id
            SQL;
    }

    /** The bank balance the day before each farm's window. */
    private function openingCte(): string
    {
        $lines = InsightsDuckDb::lines();
        $horizon = self::horizonPredicate();

        return <<<SQL
            SELECT w.farm_id, COALESCE(SUM(b.amount), 0) AS opening_balance
            FROM report_window w
            LEFT JOIN (
                SELECT tl.farm_id, tl.date, CAST(SUM(tl.net_amount) AS BIGINT) AS amount
                FROM {$lines} tl
                WHERE {$this->farmFilter('tl.farm_id')}
                  AND tl.basis = 'cash'
                  AND tl.date < CAST(\$opening_before AS DATE)
                  AND tl.account_id IN (SELECT accountid FROM accounts WHERE account_type = 'BANK')
                  AND {$horizon}
                GROUP BY tl.farm_id, tl.date
            ) b ON b.farm_id = w.farm_id AND b.date < w.window_start
            GROUP BY w.farm_id
            SQL;
    }

    private function milkVjCte(): string
    {
        $mp = self::mysql('milk_productions');
        $mt = self::mysql('milk_trackers');
        $pr = self::mysql('milk_tracker_prices');
        $offset = self::MILK_PAYMENT_OFFSET;

        return <<<SQL
            SELECT mt.farm_id, mt.income_accountid AS account_id,
                   CAST(mp.transaction_date + {$offset} AS DATE) AS date,
                   -(CAST(mp.production AS BIGINT) * pr.price) AS amount
            FROM {$mp} mp
            JOIN {$mt} mt ON mt.id = mp.milk_tracker_id AND mt._valid_to IS NULL
            JOIN {$pr} pr ON pr.milk_tracker_id = mt.id AND pr.month = mp.transaction_date
            WHERE {$this->farmFilter('mp.farm_id')}
              AND mp._valid_to IS NULL AND mp.budget_id = 0 AND mp.production > 0
              AND CAST(mp.transaction_date + {$offset} AS DATE) > CAST(\$horizon AS DATE)
              AND CAST(mp.transaction_date + {$offset} AS DATE) <= CAST(\$period_to AS DATE)
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

    private function gstNetByMonthCte(): string
    {
        return <<<SQL
            SELECT l.farm_id, CAST(date_trunc('month', l.date) AS DATE) AS ms, SUM(l.amount) AS net
            FROM scan l
            JOIN gst_accounts g ON g.farm_id = l.farm_id AND l.account_id = g.gst_id
            WHERE l.tag IS NULL
            GROUP BY 1, 2
            SQL;
    }

    private function gstSettlementsCte(): string
    {
        $tag = self::TAG_GST_PAYMENT;

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
     * after, except November's (15 January) and March's (7 May).
     */
    private function gstPaymentDatesCte(): string
    {
        return <<<SQL
            SELECT w.farm_id,
                   CASE
                       WHEN month(m.m) = 12 THEN make_date(year(m.m) + 1, 1, 15)
                       WHEN month(m.m) = 4 THEN make_date(year(m.m), 5, 7)
                       ELSE make_date(year(m.m), month(m.m), 28)
                   END AS pay_date,
                   CAST(m.m - INTERVAL '2 months' AS DATE) AS window_start,
                   CAST(m.m - INTERVAL '1 day' AS DATE) AS window_end
            FROM report_window w
            JOIN farm f ON f.farm_id = w.farm_id
            CROSS JOIN LATERAL generate_series(CAST(date_trunc('month', w.window_start) AS TIMESTAMP), CAST(w.window_end AS TIMESTAMP), INTERVAL '1 month') AS m(m)
            WHERE month(m.m) % 2 = (f.fy_month + 1) % 2
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

    private function gstPredictedCte(): string
    {
        return <<<SQL
            SELECT w.farm_id, g.payments_id AS account_id, w.pay_date AS date,
                   -w.net - COALESCE(SUM(st.amount), 0) AS amount
            FROM gst_window_net w
            JOIN gst_accounts g ON g.farm_id = w.farm_id
            LEFT JOIN gst_settlements st ON st.farm_id = w.farm_id AND st.date > w.window_end AND st.date <= w.pay_date
            WHERE w.pay_date > CAST(\$horizon AS DATE)
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
              AND l.date BETWEEN CAST(date_trunc('month', w.window_start) AS DATE) AND w.window_end
            SQL;
    }

    private static function profitAndLossGroups(): string
    {
        return implode(', ', array_map(static fn (string $g): string => "'{$g}'", ReportBasis::PROFIT_AND_LOSS_GROUPS));
    }

    /** Accrual lake lines on profit and loss accounts, summed to (farm, month, account). */
    private function accrualScanCte(): string
    {
        $lines = InsightsDuckDb::lines();
        $groups = self::profitAndLossGroups();
        $where = $this->scanPredicate('accrual');

        return <<<SQL
            SELECT tl.farm_id, tl.month AS date, tl.account_id, CAST(SUM(tl.net_amount) AS BIGINT) AS amount
            FROM {$lines} tl
            {$where}
              AND tl.account_id NOT IN (SELECT accountid FROM accounts WHERE grp NOT IN ({$groups}))
            GROUP BY tl.farm_id, tl.month, tl.account_id
            SQL;
    }

    private function accrualMilkVjCte(): string
    {
        $mp = self::mysql('milk_productions');
        $mt = self::mysql('milk_trackers');
        $pr = self::mysql('milk_tracker_prices');

        return <<<SQL
            SELECT mt.farm_id, mt.income_accountid AS account_id,
                   mp.transaction_date AS date,
                   -(CAST(mp.production AS BIGINT) * pr.price) AS amount
            FROM {$mp} mp
            JOIN {$mt} mt ON mt.id = mp.milk_tracker_id AND mt._valid_to IS NULL
            JOIN {$pr} pr ON pr.milk_tracker_id = mt.id AND pr.month = mp.transaction_date
            WHERE {$this->farmFilter('mp.farm_id')}
              AND mp._valid_to IS NULL AND mp.budget_id = 0 AND mp.production > 0
              AND CAST(mp.transaction_date + INTERVAL '1 month' - INTERVAL '1 day' AS DATE) > CAST(\$horizon AS DATE)
              AND mp.transaction_date <= CAST(\$period_to AS DATE)
            SQL;
    }

    private function stockNetCte(): string
    {
        $st = self::mysql('stock_transactions');
        $horizon = self::horizonPredicate('st', 'actual');

        return <<<SQL
            SELECT st.farm_id, st.tracker_id, st.stock_class_uuid,
                   CAST(date_trunc('month', st.date) AS DATE) AS ms,
                   SUM(CASE WHEN st.transition IN ('opening', 'purchase', 'birth') THEN st.quantity ELSE -st.quantity END) AS net
            FROM (
                SELECT farm_id, tracker_id, stock_class_uuid, transaction_date AS date, type, transition, quantity
                FROM {$st}
                WHERE {$this->farmFilter('farm_id')} AND _valid_to IS NULL AND budget_id = 0
                  AND transaction_date <= CAST(\$period_to AS DATE)
            ) st
            WHERE {$horizon}
            GROUP BY 1, 2, 3, 4
            SQL;
    }

    private function stockHeadsCte(): string
    {
        return <<<SQL
            SELECT c.farm_id, c.tracker_id, c.stock_class_uuid, CAST(m.m AS DATE) AS ms,
                   SUM(COALESCE(n.net, 0)) OVER (PARTITION BY c.farm_id, c.tracker_id, c.stock_class_uuid ORDER BY m.m) AS head
            FROM (
                SELECT farm_id, tracker_id, stock_class_uuid, min(ms) AS first_ms
                FROM stock_net
                GROUP BY 1, 2, 3
            ) c
            JOIN report_window w ON w.farm_id = c.farm_id
            CROSS JOIN LATERAL generate_series(CAST(c.first_ms AS TIMESTAMP), CAST(w.window_end AS TIMESTAMP), INTERVAL '1 month') AS m(m)
            LEFT JOIN stock_net n ON n.farm_id = c.farm_id AND n.tracker_id = c.tracker_id
                                 AND n.stock_class_uuid = c.stock_class_uuid AND n.ms = CAST(m.m AS DATE)
            SQL;
    }

    private function stockValuesCte(): string
    {
        $v = self::mysql('stock_class_valuations');

        return <<<SQL
            SELECT h.farm_id, h.ms, SUM(h.head * v.value_per_head) AS value
            FROM stock_heads h
            JOIN farm f ON f.farm_id = h.farm_id
            JOIN {$v} v
              ON v.farm_id = h.farm_id AND v.tracker_id = h.tracker_id AND v.stock_class_uuid = h.stock_class_uuid
             AND v.season = year(h.ms) + CASE WHEN month(h.ms) > f.fy_month THEN 1 ELSE 0 END
            GROUP BY 1, 2
            SQL;
    }

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
              AND l.date BETWEEN CAST(date_trunc('month', w.window_start) AS DATE) AND w.window_end
            SQL;
    }
}
