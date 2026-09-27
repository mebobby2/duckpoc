<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use App\Services\CashFlow\Definition\CashFlowActualsForecastReportDefinition;
use App\Services\CashFlow\Definition\ReportSection;
use App\Services\CashFlow\Definition\SectionAmountExpression;
use App\Services\CashFlow\Definition\TrackerSection;

/**
 * Figured's actuals-plus-forecast Cash Flow as ONE DuckDB statement.
 *
 * The request being reproduced is
 * `/reports/data/cash_flow?type=actualsForecast&period=2027&actuals_horizon=…
 * &display=monthly&group_by=tracker&exclude_eoy_journals=1&with_total=1`.
 * Everything that request makes Figured do at report time happens inside
 * this statement, in the order Figured does it:
 *
 *   1. the period — the requested from and to dates, one column per
 *      calendar month they touch (Figured's `period=2027` is one such
 *      range: the financial year ending on the farm's balance date);
 *   2. the scan — journal lines in the period, actuals to the horizon and
 *      forecast after it, end-of-year adjustments dropped by tag;
 *   3. the virtual journals — the three handlers a cash flow fires, each a
 *      CTE over the scan: milk tracker income from production x payout,
 *      the GST payments/refunds schedule, and overdraft interest as a
 *      recurrence (`WITH RECURSIVE`, because month N's interest changes
 *      month N+1's charge base);
 *   4. the sections — one grouped pass over scan + virtual journals, split
 *      per tracker and per farm category, sign rules applied;
 *   5. the calculation rows, the opening/closing running balance, the
 *      Total column, and the per-tracker blocks, all as further CTEs.
 *
 * The result is long: one row per (scope, column), where scope is `farm`
 * for the consolidated report or a tracker id for that tracker's block, and
 * the column is a month or `Total`. The viewer only pivots.
 *
 * Runtime values are the four named parameters (`$farm_id`, `$period_from`,
 * `$period_to`, `$horizon`, `$basis`). Option gates are compiled into the SQL text, like
 * `DataPipelineSqlBuilder`, so a gate that is off leaves no trace in the plan.
 * Nothing in the text depends on which farm or how many trackers.
 */
final class CashFlowActualsForecastSqlBuilder
{
    private const int FIXED_POINT = 10000;

    public const string TAG_EOY_MANUAL = 'eoy_adjust_manual';
    public const string TAG_EOY_SYSTEM = 'eoy_adjust_system';
    public const string TAG_GST_PAYMENT = 'gst_payment';

    /** Fonterra-style: production in month M is paid on the 20th of M+1. */
    private const string MILK_PAYMENT_OFFSET = 'INTERVAL 1 MONTH + INTERVAL 19 DAY';

    public function __construct(
        private readonly CashFlowActualsForecastReportDefinition $definition,
        private readonly string $alias,
        private readonly string $appAlias,
        private readonly CashFlowActualsForecastOptions $options,
    ) {
    }

    public function build(): string
    {
        return $this->statement($this->reportCtes(), $this->finalSelect());
    }

    /**
     * The virtual journals the statement synthesised, for display.
     */
    public function buildVirtualJournalsSql(): string
    {
        $fp = self::FIXED_POINT;

        $ctes = $this->virtualJournalCtes();

        return $this->statement($ctes, <<<SQL
            SELECT
                v.source,
                v.date,
                v.account_id,
                a.account_name,
                a.account_category,
                v.tracker_id,
                v.amount / {$fp}.0 AS amount_dollars
            FROM virtual_journals v
            JOIN {$this->appAlias}.accounts a ON a.account_id = v.account_id
            ORDER BY v.date, v.source, v.tracker_id
            LIMIT \$row_limit
            SQL);
    }

    public function buildVirtualJournalSummarySql(): string
    {
        $fp = self::FIXED_POINT;

        return $this->statement($this->virtualJournalCtes(), <<<SQL
            SELECT v.source, count(*) AS n, SUM(v.amount) / {$fp}.0 AS net_dollars
            FROM virtual_journals v
            GROUP BY v.source
            ORDER BY v.source
            SQL);
    }

    /**
     * The journal lines the scan consumed, for display. Same predicate as
     * the scan, so the listing is what fed the numbers.
     */
    public function buildSourceRowsSql(): string
    {
        $fp = self::FIXED_POINT;

        return $this->statement($this->periodCtes(), <<<SQL
            SELECT
                tl.line_id,
                tl.date,
                tl.type,
                a.account_name,
                a.account_class,
                a.account_category,
                tl.tracker_id,
                tl.tag,
                tl.amount AS amount_raw,
                tl.amount / {$fp}.0 AS amount_dollars
            FROM {$this->alias}.transaction_lines tl
            JOIN {$this->appAlias}.accounts a ON a.account_id = tl.account_id
            CROSS JOIN period p
            {$this->scanPredicate()}
            ORDER BY tl.date
            LIMIT \$row_limit
            SQL);
    }

    public function buildSourceRowCountSql(): string
    {
        $fp = self::FIXED_POINT;

        return $this->statement($this->periodCtes(), <<<SQL
            SELECT
                count(*) AS n,
                count(tl.tracker_id) AS n_tracker_tagged,
                SUM(tl.amount) / {$fp}.0 AS net_dollars,
                min(p.period_from) AS period_from,
                max(p.period_to) AS period_to
            FROM {$this->alias}.transaction_lines tl
            CROSS JOIN period p
            {$this->scanPredicate()}
            SQL);
    }

    /**
     * @param array<string, string> $ctes name => body, in dependency order
     */
    private function statement(array $ctes, string $select): string
    {
        $parts = [];
        foreach ($ctes as $name => $body) {
            $materialized = in_array($name, ['scan', 'report_lines'], true) ? ' AS MATERIALIZED' : ' AS';
            $parts[] = "{$name}{$materialized} (\n{$body}\n)";
        }

        return "WITH RECURSIVE\n".implode(",\n\n", $parts)."\n\n".$select;
    }

    /**
     * @return array<string, string>
     */
    private function periodCtes(): array
    {
        return [
            'farm' => $this->farmCte(),
            'period' => $this->periodCte(),
            'months' => $this->monthSpineCte(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function virtualJournalCtes(): array
    {
        return $this->periodCtes() + [
            'scan' => $this->scanCte(),
            'opening' => $this->openingCte(),
            'farm_trackers' => $this->farmTrackersCte(),
            'milk_vj' => $this->milkVjCte(),
            'gst_accounts' => $this->gstAccountsCte(),
            'gst_net_by_month' => $this->gstNetByMonthCte(),
            'gst_settlements' => $this->gstSettlementsCte(),
            'gst_payment_dates' => $this->gstPaymentDatesCte(),
            'gst_predicted' => $this->gstPredictedCte(),
            'gst_vj' => $this->gstVjCte(),
            'od' => $this->overdraftConfigCte(),
            'inner_lines' => $this->innerLinesCte(),
            'inner_cashflow' => $this->innerCashFlowCte(),
            'od_accrual' => $this->overdraftAccrualCte(),
            'overdraft_vj' => $this->overdraftVjCte(),
            'virtual_journals' => $this->virtualJournalsCte(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function reportCtes(): array
    {
        $ctes = $this->virtualJournalCtes() + [
            'report_lines' => $this->reportLinesCte(),
            'tracker_agg' => $this->trackerAggCte(),
            'farm_agg' => $this->farmAggCte(),
            'tracker_rollup' => $this->trackerRollupCte(),
            'sections' => $this->sectionsCte(),
        ];

        $previous = 'sections';
        foreach ($this->definition->calculationRows() as $row) {
            $name = 'calc_'.$row->field;
            $ctes[$name] = sprintf("    SELECT *, %s AS %s\n    FROM %s", $row->formula, $row->field, $previous);
            $previous = $name;
        }

        $ctes['with_balances'] = $this->runningBalanceCte($previous);
        $ctes['with_limits'] = $this->overdraftLimitsCte('with_balances');
        $ctes['farm_rows'] = $this->farmRowsCte('with_limits');

        if ($this->options->groupByTracker) {
            $ctes['tracker_grid'] = $this->trackerGridCte();

            $previous = 'tracker_grid';
            foreach ($this->definition->trackerCalculationRows() as $row) {
                $name = 'tracker_calc_'.$row->field;
                $ctes[$name] = sprintf("    SELECT *, %s AS %s\n    FROM %s", $row->formula, $row->field, $previous);
                $previous = $name;
            }

            $ctes['tracker_rows'] = $this->trackerRowsCte($previous);
        }

        return $ctes;
    }

    private function finalSelect(): string
    {
        $union = $this->options->groupByTracker
            ? "UNION ALL\n    SELECT * FROM tracker_rows\n"
            : '';

        return "SELECT * FROM (\n    SELECT * FROM farm_rows\n    {$union}) report\n"
            ."ORDER BY (scope = 'farm') DESC, display_order, scope, interval_index";
    }

    private function farmCte(): string
    {
        return <<<SQL
            SELECT farm_id, financial_year_end_month
            FROM {$this->appAlias}.farms
            WHERE farm_id = \$farm_id
            SQL;
    }

    private function periodCte(): string
    {
        return <<<SQL
            SELECT
                CAST(\$period_from AS DATE) AS period_from,
                CAST(\$period_to AS DATE) AS period_to,
                CAST(\$horizon AS DATE) AS horizon
            SQL;
    }

    /**
     * Every calendar month the period touches, typed the way Figured types a
     * column: an interval wholly on or before the horizon is actuals, wholly
     * after is forecast, and one the horizon splits is `actualsForecast`.
     *
     * A period that starts or ends mid-month gets a short first or last
     * column: the scan already stops at the period's dates, and `month_end`
     * and the column type are clamped to them too. `month_start` stays the
     * calendar month, because the sections join on
     * `date_trunc('month', date)` and the GST windows count back from it.
     */
    private function monthSpineCte(): string
    {
        return <<<SQL
            SELECT
                row_number() OVER (ORDER BY g.m) AS interval_index,
                g.m::DATE AS month_start,
                least((g.m + INTERVAL 1 MONTH - INTERVAL 1 DAY)::DATE, p.period_to) AS month_end,
                strftime(g.m, '%Y-%m') AS month,
                CASE
                    WHEN least((g.m + INTERVAL 1 MONTH - INTERVAL 1 DAY)::DATE, p.period_to) <= p.horizon THEN 'actuals'
                    WHEN greatest(g.m::DATE, p.period_from) > p.horizon THEN 'forecast'
                    ELSE 'actualsForecast'
                END AS column_type
            FROM period p, generate_series(date_trunc('month', p.period_from), p.period_to, INTERVAL 1 MONTH) AS g(m)
            SQL;
    }

    /**
     * `BuildAggregationPipeline`'s `$match`: farm, basis, period, the horizon
     * split, and — with `exclude_eoy_journals=1` — the end-of-year adjustment
     * tags dropped with a `$nin`.
     */
    private function scanPredicate(): string
    {
        $eoy = $this->options->excludeEoyJournals
            ? sprintf("  AND (tl.tag IS NULL OR tl.tag NOT IN ('%s', '%s'))", self::TAG_EOY_MANUAL, self::TAG_EOY_SYSTEM)
            : '';

        return <<<SQL
            WHERE tl.farm_id = \$farm_id
              AND tl.basis = \$basis
              AND tl.date BETWEEN CAST(\$period_from AS DATE) AND CAST(\$period_to AS DATE)
              AND (
                    (tl.date <= CAST(\$horizon AS DATE) AND tl.type = 'actuals')
                 OR (tl.date >  CAST(\$horizon AS DATE) AND tl.type = 'forecast')
              )
            {$eoy}
            SQL;
    }

    /**
     * The scan, summed to one row per (date, account, tracker, tag).
     *
     * Every reader of it only ever sums amounts within those keys, so
     * nothing is lost, and the materialised result is the size of the
     * farm's chart x calendar rather than its line count: 500M lines on
     * the benchmark farm become a few thousand rows. Materialising the raw
     * lines instead cost 18.6 s of an 81.5 s run over the whole farm.
     */
    private function scanCte(): string
    {
        return <<<SQL
            SELECT tl.date, tl.account_id, tl.tracker_id, tl.tag, CAST(SUM(tl.amount) AS BIGINT) AS amount
            FROM {$this->alias}.transaction_lines tl
            {$this->scanPredicate()}
            GROUP BY tl.date, tl.account_id, tl.tracker_id, tl.tag
            SQL;
    }

    /**
     * `OpeningClosingBalance` on an actuals/forecast period: the bank
     * accounts' own balance up to the day before the period, which is what
     * `Balance::getReport()` reads. Not the farm's typed-in opening figure —
     * that is the budget path.
     */
    private function openingCte(): string
    {
        return <<<SQL
            SELECT COALESCE(SUM(tl.amount), 0) AS opening_balance
            FROM {$this->alias}.transaction_lines tl
            JOIN {$this->appAlias}.accounts a ON a.account_id = tl.account_id
            WHERE tl.farm_id = \$farm_id
              AND tl.basis = \$basis
              AND a.account_type = 'BANK'
              AND tl.date < CAST(\$period_from AS DATE)
              AND (
                    (tl.date <= CAST(\$horizon AS DATE) AND tl.type = 'actuals')
                 OR (tl.date >  CAST(\$horizon AS DATE) AND tl.type = 'forecast')
              )
            SQL;
    }

    private function farmTrackersCte(): string
    {
        return <<<SQL
            SELECT tracker_id, tracker_name, tracker_type, CAST(display_order AS INTEGER) AS display_order, income_account_id
            FROM {$this->appAlias}.trackers
            WHERE farm_id = \$farm_id
            SQL;
    }

    /**
     * The milk tracker handler: forecast milk income that no invoice exists
     * for yet, synthesised from production x payout per tracker. Figured's
     * `MilkTrackerVirtualJournalService` does this per tracker, per season,
     * with the milk company's payment calendar; here it is one join, and the
     * payment lag is the one constant. Only payments falling after the
     * horizon are virtual — before it the income is a real journal.
     */
    private function milkVjCte(): string
    {
        $offset = self::MILK_PAYMENT_OFFSET;

        return <<<SQL
            SELECT
                t.income_account_id AS account_id,
                t.tracker_id,
                (mp.month + {$offset})::DATE AS date,
                -(CAST(mp.kg_ms_current AS BIGINT) * r.advance_rate
                  + CAST(mp.kg_ms_deferred AS BIGINT) * r.deferred_rate) AS amount
            FROM farm_trackers t
            JOIN {$this->appAlias}.tracker_milk_production mp ON mp.tracker_id = t.tracker_id
            JOIN {$this->appAlias}.milk_payout_rates r ON r.tracker_id = t.tracker_id AND r.month = mp.month
            CROSS JOIN period p
            WHERE t.tracker_type = 'milk'
              AND t.income_account_id IS NOT NULL
              AND (mp.kg_ms_current > 0 OR mp.kg_ms_deferred > 0)
              AND (mp.month + {$offset})::DATE > p.horizon
              AND (mp.month + {$offset})::DATE <= p.period_to
            SQL;
    }

    private function gstAccountsCte(): string
    {
        return <<<SQL
            SELECT
                (SELECT account_id FROM {$this->appAlias}.accounts
                 WHERE system_account = 'GST' AND farm_id = \$farm_id LIMIT 1) AS gst_id,
                (SELECT account_id FROM {$this->appAlias}.accounts
                 WHERE system_account = 'GSTPAYMENTS' AND farm_id = \$farm_id LIMIT 1) AS payments_id
            SQL;
    }

    /** Net GST per month, tax components only — settlements carry the payment tag and are handled apart. */
    private function gstNetByMonthCte(): string
    {
        $pay = self::TAG_GST_PAYMENT;

        return <<<SQL
            SELECT date_trunc('month', l.date)::DATE AS ms, SUM(l.amount) AS net
            FROM scan l, gst_accounts g
            WHERE l.account_id = g.gst_id AND (l.tag IS NULL OR l.tag <> '{$pay}')
            GROUP BY 1
            SQL;
    }

    private function gstSettlementsCte(): string
    {
        $pay = self::TAG_GST_PAYMENT;

        return <<<SQL
            SELECT l.date, l.amount
            FROM scan l, gst_accounts g
            WHERE l.account_id = g.gst_id AND l.tag = '{$pay}'
            SQL;
    }

    /**
     * NZ two-monthly GST on a payments basis: a return period ends every
     * second month counted from the balance date and is paid on the 28th
     * of the month after — except the period ending November, paid
     * 15 January, and the one ending March, paid 7 May. A payment month's
     * window is therefore the two calendar months before it.
     */
    private function gstPaymentDatesCte(): string
    {
        return <<<SQL
            SELECT
                CASE
                    WHEN month(m.month_start) = 12 THEN make_date(year(m.month_start) + 1, 1, 15)
                    WHEN month(m.month_start) = 4  THEN make_date(year(m.month_start), 5, 7)
                    ELSE make_date(year(m.month_start), month(m.month_start), 28)
                END AS pay_date,
                (m.month_start - INTERVAL 2 MONTH)::DATE AS window_start,
                (m.month_start - INTERVAL 1 DAY)::DATE AS window_end
            FROM months m, farm f
            WHERE month(m.month_start) % 2 = (f.financial_year_end_month + 1) % 2
            SQL;
    }

    /**
     * The predicted settlement for each payment date after the horizon: the
     * window's net GST, negated into a cash outflow, less anything already
     * paid against that window. Before the horizon the settlement is a real
     * journal, so nothing is predicted there.
     */
    private function gstPredictedCte(): string
    {
        return <<<SQL
            SELECT
                g.payments_id AS account_id,
                pd.pay_date AS date,
                -COALESCE((SELECT SUM(n.net) FROM gst_net_by_month n
                           WHERE n.ms BETWEEN pd.window_start AND pd.window_end), 0)
                - COALESCE((SELECT SUM(s.amount) FROM gst_settlements s
                            WHERE s.date > pd.window_end AND s.date <= pd.pay_date), 0) AS amount
            FROM gst_payment_dates pd, gst_accounts g, period p
            WHERE pd.pay_date > p.horizon
            SQL;
    }

    /**
     * The three journal kinds `createJournalsForGstMovements` emits: the
     * prediction, and — for each actual settlement — a reversal off the GST
     * account and the same amount onto the payments/refunds line.
     */
    private function gstVjCte(): string
    {
        return <<<SQL
            SELECT account_id, date, amount FROM gst_predicted WHERE amount <> 0
            UNION ALL
            SELECT g.gst_id, s.date, -s.amount FROM gst_settlements s, gst_accounts g
            UNION ALL
            SELECT g.payments_id, s.date, s.amount FROM gst_settlements s, gst_accounts g
            SQL;
    }

    /** The overdraft in force for the period: the latest configuration starting on or before its end. */
    private function overdraftConfigCte(): string
    {
        return <<<SQL
            SELECT od.rate, od.overdraft_limit
            FROM {$this->appAlias}.overdrafts od, period p
            WHERE od.farm_id = \$farm_id AND od.start_date <= p.period_to
            ORDER BY od.start_date DESC
            LIMIT 1
            SQL;
    }

    /**
     * The overdraft handler's sub-report — `OverdraftCashFlowStructureBuilder`
     * — sees the scan plus the other handlers' journals, bank accounts
     * excluded. Figured runs that sub-report with its own virtual-journal
     * pass, which is why the benchmark's overdraft node has milk and GST
     * children of its own; here they are simply the CTEs above.
     */
    private function innerLinesCte(): string
    {
        return <<<SQL
            SELECT l.date, l.amount
            FROM (
                SELECT date, account_id, amount FROM scan
                UNION ALL
                SELECT date, account_id, amount FROM milk_vj
                UNION ALL
                SELECT date, account_id, amount FROM gst_vj
            ) l
            JOIN {$this->appAlias}.accounts a ON a.account_id = l.account_id
            CROSS JOIN period p
            WHERE COALESCE(a.account_type, '') <> 'BANK'
              AND l.date BETWEEN p.period_from AND p.period_to
            SQL;
    }

    /**
     * The cash position before interest. Negated once for every account:
     * revenue is credit-negative and expense debit-positive, so `-amount` is
     * cash movement for all of them (see `OverdraftSqlBuilder` for the bug
     * that branching on class produces).
     */
    private function innerCashFlowCte(): string
    {
        return <<<SQL
            SELECT
                m.interval_index,
                m.month_end,
                o.opening_balance
                    + SUM(COALESCE(v.net, 0)) OVER (ORDER BY m.interval_index
                        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS closing
            FROM months m
            LEFT JOIN (
                SELECT date_trunc('month', l.date)::DATE AS ms, -SUM(l.amount) AS net
                FROM inner_lines l
                GROUP BY 1
            ) v ON v.ms = m.month_start
            CROSS JOIN opening o
            SQL;
    }

    /**
     * Phase 3's recurrence, unchanged: interest charges against the closing
     * balance net of interest already accrued, so month N feeds month N+1.
     * `cum` keeps full precision; only the posted amount is truncated.
     * Monthly posting term only.
     */
    private function overdraftAccrualCte(): string
    {
        return <<<SQL
            SELECT
                cf.interval_index,
                cf.month_end,
                CAST(cf.closing AS DOUBLE) AS principal,
                CASE WHEN cf.closing < 0
                     THEN -cf.closing * (od.rate / 10000.0 / 100.0) / 12.0
                     ELSE 0 END AS interest,
                CASE WHEN cf.closing < 0
                     THEN -cf.closing * (od.rate / 10000.0 / 100.0) / 12.0
                     ELSE 0 END AS cum
            FROM inner_cashflow cf, od
            WHERE cf.interval_index = 1
            UNION ALL
            SELECT
                cf.interval_index,
                cf.month_end,
                cf.closing - a.cum AS principal,
                CASE WHEN (cf.closing - a.cum) < 0
                     THEN -(cf.closing - a.cum) * (od.rate / 10000.0 / 100.0) / 12.0
                     ELSE 0 END AS interest,
                a.cum + CASE WHEN (cf.closing - a.cum) < 0
                             THEN -(cf.closing - a.cum) * (od.rate / 10000.0 / 100.0) / 12.0
                             ELSE 0 END AS cum
            FROM od_accrual a
            JOIN inner_cashflow cf ON cf.interval_index = a.interval_index + 1
            CROSS JOIN od
            SQL;
    }

    private function overdraftVjCte(): string
    {
        return <<<SQL
            SELECT
                (SELECT account_id FROM {$this->appAlias}.accounts
                 WHERE system_account = 'OVERDRAFT' AND farm_id = \$farm_id LIMIT 1) AS account_id,
                a.month_end AS date,
                CAST(floor(a.interest) AS BIGINT) AS amount
            FROM od_accrual a
            WHERE a.interest > 0
            SQL;
    }

    private function virtualJournalsCte(): string
    {
        return <<<SQL
            SELECT 'milk_tracker' AS source, account_id, tracker_id, date, amount FROM milk_vj
            UNION ALL
            SELECT 'gst_payments_refunds', account_id, CAST(NULL AS VARCHAR), date, amount FROM gst_vj
            UNION ALL
            SELECT 'overdraft', account_id, CAST(NULL AS VARCHAR), date, amount FROM overdraft_vj
            SQL;
    }

    /**
     * `MergeVirtualJournals`: the scan plus every handler's journals, bucketed
     * by month and classified by the account. Bank lines are the hidden
     * `bank_movements` section — read, never summed — so they leave here.
     */
    private function reportLinesCte(): string
    {
        return <<<SQL
            SELECT
                date_trunc('month', l.date)::DATE AS month_start,
                l.tracker_id,
                l.amount,
                a.account_class,
                a.account_category
            FROM (
                SELECT date, account_id, tracker_id, amount FROM scan
                UNION ALL
                SELECT date, account_id, tracker_id, amount FROM virtual_journals
            ) l
            JOIN {$this->appAlias}.accounts a ON a.account_id = l.account_id
            CROSS JOIN period p
            WHERE COALESCE(a.account_type, '') <> 'BANK'
              AND l.date BETWEEN p.period_from AND p.period_to
            SQL;
    }

    /**
     * One row per (month, tracker): the milk, livestock and crop structure
     * builders' per-tracker income and costs sections, as a GROUP BY. The
     * tracker count is a cardinality here, not a loop.
     */
    private function trackerAggCte(): string
    {
        $expressions = [];
        foreach ($this->definition->trackerSections() as $section) {
            $expressions[] = '    '.$this->trackerSectionExpression($section);
        }

        $classes = implode(', ', array_map(
            static fn (TrackerSection $s): string => "'{$s->accountClass}'",
            $this->definition->trackerSections(),
        ));

        return "    SELECT\n"
            ."        l.month_start,\n"
            ."        l.tracker_id,\n"
            .implode(",\n", $expressions)."\n"
            ."    FROM report_lines l\n"
            ."    WHERE l.tracker_id IS NOT NULL AND l.account_class IN ({$classes})\n"
            .'    GROUP BY 1, 2';
    }

    /** Farm-level sections: every line no tracker section claimed. */
    private function farmAggCte(): string
    {
        $expressions = [];
        foreach ($this->definition->farmSections() as $section) {
            $expressions[] = '    '.$this->farmSectionExpression($section);
        }

        $classes = implode(', ', array_map(
            static fn (TrackerSection $s): string => "'{$s->accountClass}'",
            $this->definition->trackerSections(),
        ));

        return "    SELECT\n"
            ."        l.month_start,\n"
            .implode(",\n", $expressions)."\n"
            ."    FROM report_lines l\n"
            ."    WHERE l.tracker_id IS NULL OR l.account_class NOT IN ({$classes})\n"
            .'    GROUP BY 1';
    }

    /**
     * `implode(' + ', $trackerGrossProfitIds)` — Figured's formula string
     * with one term per tracker — as a SUM over the tracker groups.
     */
    private function trackerRollupCte(): string
    {
        $rollup = null;
        foreach ($this->definition->trackerCalculationRows() as $row) {
            if ($row->field === $this->definition->trackerRollupField()) {
                $rollup = $row->formula;
            }
        }

        $total = $this->definition->trackerRollupTotalField();

        return <<<SQL
            SELECT
                t.month_start,
                SUM({$rollup}) AS {$total},
                COUNT(*) AS tracker_count
            FROM tracker_agg t
            GROUP BY t.month_start
            SQL;
    }

    private function sectionsCte(): string
    {
        $total = $this->definition->trackerRollupTotalField();

        $selected = [
            "        COALESCE(r.{$total}, 0) AS {$total}",
            '        COALESCE(r.tracker_count, 0) AS tracker_count',
        ];
        foreach ($this->definition->farmSections() as $section) {
            $selected[] = "        COALESCE(f.{$section->field}, 0) AS {$section->field}";
        }

        return "    SELECT\n"
            ."        m.interval_index,\n"
            ."        m.month,\n"
            ."        m.column_type,\n"
            .implode(",\n", $selected)."\n"
            ."    FROM months m\n"
            ."    LEFT JOIN farm_agg f ON f.month_start = m.month_start\n"
            .'    LEFT JOIN tracker_rollup r ON r.month_start = m.month_start';
    }

    private function runningBalanceCte(string $from): string
    {
        $fp = self::FIXED_POINT;
        $source = $this->definition->runningBalanceSource();

        return <<<SQL
            SELECT
                c.*,
                o.opening_balance / {$fp}.0
                    + COALESCE(SUM(c.{$source}) OVER months_before, 0) AS opening,
                o.opening_balance / {$fp}.0
                    + SUM(c.{$source}) OVER months_through AS closing
            FROM {$from} c
            CROSS JOIN opening o
            WINDOW
                months_before  AS (ORDER BY c.interval_index
                                   ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING),
                months_through AS (ORDER BY c.interval_index
                                   ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
            SQL;
    }

    /**
     * `OverdraftLimits`' two dynamic rows when `with_overdraft=1`; the same
     * two columns as NULL otherwise, so the result shape never changes.
     */
    private function overdraftLimitsCte(string $from): string
    {
        $fp = self::FIXED_POINT;

        if (!$this->options->withOverdraft) {
            return <<<SQL
                SELECT
                    b.*,
                    CAST(NULL AS DOUBLE) AS overdraft_limit,
                    CAST(NULL AS DOUBLE) AS overdraft_headroom
                FROM {$from} b
                SQL;
        }

        return <<<SQL
            SELECT
                b.*,
                od.overdraft_limit / {$fp}.0 AS overdraft_limit,
                b.closing + od.overdraft_limit / {$fp}.0 AS overdraft_headroom
            FROM {$from} b
            LEFT JOIN od ON TRUE
            SQL;
    }

    /**
     * The consolidated rows in the long output shape, plus — with
     * `with_total=1` — the Total column: flows summed, opening from the
     * first month, closing from the last, the overdraft rows blank.
     */
    private function farmRowsCte(string $from): string
    {
        $fields = $this->definition->totalledFields();

        $monthly = implode(",\n", array_map(
            static fn (string $f): string => "        b.{$f}",
            $fields,
        ));

        $summed = implode(",\n", array_map(
            static fn (string $f): string => "        SUM(b.{$f})",
            $fields,
        ));

        $rows = <<<SQL
            SELECT
                'farm' AS scope,
                CAST(NULL AS VARCHAR) AS tracker_name,
                CAST(NULL AS VARCHAR) AS tracker_type,
                0 AS display_order,
                b.interval_index,
                b.month,
                b.column_type,
                CAST(NULL AS DOUBLE) AS tracker_income,
                CAST(NULL AS DOUBLE) AS tracker_costs,
                CAST(NULL AS DOUBLE) AS tracker_gross_profit,
                b.tracker_count,
                {$monthly},
                b.opening,
                b.closing,
                b.overdraft_limit,
                b.overdraft_headroom
            FROM {$from} b
            SQL;

        if (!$this->options->withTotal) {
            return $rows;
        }

        return $rows."\n".<<<SQL
            UNION ALL
            SELECT
                'farm',
                CAST(NULL AS VARCHAR),
                CAST(NULL AS VARCHAR),
                0,
                (SELECT max(interval_index) + 1 FROM months),
                'Total',
                'total',
                CAST(NULL AS DOUBLE),
                CAST(NULL AS DOUBLE),
                CAST(NULL AS DOUBLE),
                max(b.tracker_count),
                {$summed},
                arg_min(b.opening, b.interval_index),
                arg_max(b.closing, b.interval_index),
                CAST(NULL AS DOUBLE),
                CAST(NULL AS DOUBLE)
            FROM {$from} b
            SQL;
    }

    /**
     * Every tracker in every month, so a block is a complete grid rather
     * than a ragged one. The trackers table is small; the aggregate it joins
     * to is already grouped.
     */
    private function trackerGridCte(): string
    {
        $selected = [];
        foreach ($this->definition->trackerSections() as $section) {
            $selected[] = "        COALESCE(a.{$section->field}, 0) AS {$section->field}";
        }

        return "    SELECT\n"
            ."        m.interval_index,\n"
            ."        m.month,\n"
            ."        m.column_type,\n"
            ."        t.tracker_id,\n"
            ."        t.tracker_name,\n"
            ."        t.tracker_type,\n"
            ."        t.display_order,\n"
            .implode(",\n", $selected)."\n"
            ."    FROM months m\n"
            ."    CROSS JOIN farm_trackers t\n"
            ."    LEFT JOIN tracker_agg a\n"
            ."           ON a.month_start = m.month_start\n"
            .'          AND a.tracker_id = t.tracker_id';
    }

    /** The tracker blocks in the long output shape, with their own Total column. */
    private function trackerRowsCte(string $from): string
    {
        $fields = $this->definition->totalledFields();

        $nulls = implode(",\n", array_map(
            static fn (string $f): string => "        CAST(NULL AS DOUBLE) AS {$f}",
            $fields,
        ));

        $nullsUntyped = implode(",\n", array_map(
            static fn (string $f): string => '        CAST(NULL AS DOUBLE)',
            $fields,
        ));

        $rows = <<<SQL
            SELECT
                t.tracker_id AS scope,
                t.tracker_name,
                t.tracker_type,
                t.display_order,
                t.interval_index,
                t.month,
                t.column_type,
                t.tracker_income,
                t.tracker_costs,
                t.tracker_gross_profit,
                CAST(NULL AS BIGINT) AS tracker_count,
                {$nulls},
                CAST(NULL AS DOUBLE) AS opening,
                CAST(NULL AS DOUBLE) AS closing,
                CAST(NULL AS DOUBLE) AS overdraft_limit,
                CAST(NULL AS DOUBLE) AS overdraft_headroom
            FROM {$from} t
            SQL;

        if (!$this->options->withTotal) {
            return $rows;
        }

        return $rows."\n".<<<SQL
            UNION ALL
            SELECT
                t.tracker_id,
                t.tracker_name,
                t.tracker_type,
                t.display_order,
                (SELECT max(interval_index) + 1 FROM months),
                'Total',
                'total',
                SUM(t.tracker_income),
                SUM(t.tracker_costs),
                SUM(t.tracker_gross_profit),
                CAST(NULL AS BIGINT),
                {$nullsUntyped},
                CAST(NULL AS DOUBLE),
                CAST(NULL AS DOUBLE),
                CAST(NULL AS DOUBLE),
                CAST(NULL AS DOUBLE)
            FROM {$from} t
            GROUP BY 1, 2, 3, 4
            SQL;
    }

    private function trackerSectionExpression(TrackerSection $section): string
    {
        return sprintf(
            "COALESCE(SUM(CASE WHEN l.account_class = '%s' THEN %s END), 0) / %d.0 AS %s",
            $section->accountClass,
            SectionAmountExpression::for($section->signRule),
            self::FIXED_POINT,
            $section->field,
        );
    }

    private function farmSectionExpression(ReportSection $section): string
    {
        return sprintf(
            "COALESCE(SUM(CASE WHEN l.account_category = '%s' THEN %s END), 0) / %d.0 AS %s",
            $section->category,
            SectionAmountExpression::for($section->signRule),
            self::FIXED_POINT,
            $section->field,
        );
    }
}
