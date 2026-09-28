<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

/**
 * Figured's `DataPipeline`, one CTE per pipe, in `DataPipelineService::$pipes`
 * order.
 *
 * The question this answers is Richard's, not a speed one: can an
 * ordering-dependent pipeline of twenty-four stages — three of which run a
 * nested report of their own — be written as composable SQL, or does the
 * ordering force materialisation between stages? So the shape is deliberately
 * the pipeline's shape. Every stage is a CTE named for its pipe number, every
 * stage reads the one before it, and the data flowing between them is
 * Figured's array — `[account_id][interval] => amount` — as a long table
 * `(account_id, interval_index, amount)`. A pipe whose gate is off emits
 * `SELECT * FROM <previous>` rather than being omitted, so the chain always
 * has every name and `duckdb:pipeline` can diff any stage against the PHP
 * transliteration of the same pipe. An ordering mistake is then caught at the
 * stage that made it.
 *
 * Gates are the same conditions Figured's `shouldHandle()` methods test,
 * evaluated in PHP because they are report options, not data. Pipes 12 and
 * 17 are pass-throughs because they are pass-throughs in Figured: both open
 * with `return false;` (FIG-16282) and the GST logic they held now arrives as
 * journals at pipe 8.
 *
 * Amounts are the lake's stored signs — revenue credit-negative, expense
 * debit-positive — until pipe 24 deflates. The three nested reports are
 * inner CTE chains over the same scan with their own fixed options, which is
 * precisely how Figured nests them (`OverdraftCalculationService` runs a
 * cash-basis consolidated sub-report; `CurrentYearEarnings` and
 * `RetainedEarnings` each run a `CurrentYearEarningsReport`).
 *
 * Not yet in this tranche, and marked as pass-throughs with the pipe number
 * so the omission is visible in the diff rather than silent: 11
 * (`ReportingGroupOffsetAccounts`) and 20 (`ReportingGroupConsolidateAccounts`)
 * need a multi-entity scan; 3 needs multi-farm trackers. The GST payment
 * schedule is NZ two-monthly with the exception-month *dates* applied but the
 * exception-month *windows* unverified against `PaymentsDates::getSchedule()`
 * — see the README.
 */
final class DataPipelineSqlBuilder
{
    private const int FIXED_POINT = 10000;

    public const string TAG_EOY = 'eoy_adjust_manual';
    public const string TAG_GST_PAYMENT = 'gst_payment';

    /** @var list<string> every stage name, in pipeline order */
    public const array STAGES = [
        'p01_empty', 'p02_scan', 'p03_mf_trackers', 'p04_mapped_in', 'p05_nesting',
        'p06_query', 'p07_cells', 'p08_merge_vj', 'p09_opening_bank', 'p10_opening_gst',
        'p11_offsets', 'p12_gst_payments', 'p13_merge_mapped', 'p14_cye', 'p15_retained',
        'p16_ytd', 'p17_contra_gst', 'p18_expected_sign', 'p19_inverse', 'p20_consolidate',
        'p21_dynamic_bank', 'p22_hide_empty', 'p23_hide_accounts', 'p24_format',
    ];

    public function __construct(
        private readonly string $alias,
        private readonly string $appAlias,
        private readonly PipelineOptions $options,
    ) {
    }

    /**
     * The whole chain, ending at `$stage` (default: the last).
     */
    public function build(string $stage = 'p24_format'): string
    {
        $ctes = [];

        $ctes['farm'] = $this->farmCte();
        $ctes['months'] = $this->monthSpineCte();
        $ctes['lines_by_day'] = $this->linesByDayCte();
        $ctes['report_accounts'] = $this->reportAccountsCte();

        $ctes['p01_empty'] = $this->p01Empty();
        $ctes['p02_scan'] = $this->scanCte($this->options);
        $ctes['p03_mf_trackers'] = $this->passThrough('p02_scan');
        $ctes['p04_mapped_in'] = $this->passThrough('p03_mf_trackers');
        $ctes['p05_nesting'] = $this->passThrough('p04_mapped_in');
        $ctes['p06_query'] = $this->passThrough('p05_nesting');
        $ctes['p07_cells'] = $this->cellsCte('p01_empty', 'p06_query', $this->options);

        // Pipe 8's inputs: the two virtual journal handlers that fire for a
        // cash flow, each over its own nested report.
        // Read three times below, so DuckDB materialises it: collapsed to days
        // it is thousands of rows rather than every line in the period.
        $ctes['inner_scan'] = "SELECT account_id, date, tag, SUM(amount) AS amount\n"
            ."FROM (\n{$this->scanCte($this->options->forOverdraftSubReport())}\n) l\n"
            .'GROUP BY account_id, date, tag';
        $ctes['inner_cells'] = $this->cellsCte('p01_empty', 'inner_scan', $this->options->forOverdraftSubReport());
        $ctes['gst_vj'] = $this->gstPaymentsRefundsVjCte();
        $ctes['inner_with_gst'] = $this->addJournalsCte('inner_cells', 'gst_vj');
        $ctes['inner_cashflow'] = $this->innerCashFlowCte('inner_with_gst');
        $ctes['od'] = $this->overdraftConfigCte();
        $ctes['od_accrual'] = $this->overdraftAccrualCte('inner_cashflow');
        $ctes['overdraft_vj'] = $this->overdraftVjCte();
        $ctes['p08_merge_vj'] = $this->mergeVjCte('p07_cells');

        $ctes['p09_opening_bank'] = $this->openingBankCte('p08_merge_vj');
        $ctes['p10_opening_gst'] = $this->openingGstCte('p09_opening_bank');
        $ctes['p11_offsets'] = $this->passThrough('p10_opening_gst');
        $ctes['p12_gst_payments'] = $this->passThrough('p11_offsets');
        $ctes['p13_merge_mapped'] = $this->mergeMappedCte('p12_gst_payments');
        $ctes['p14_cye'] = $this->currentYearEarningsCte('p13_merge_mapped');
        $ctes['p15_retained'] = $this->retainedEarningsCte('p14_cye');
        $ctes['p16_ytd'] = $this->ytdCte('p15_retained');
        $ctes['p17_contra_gst'] = $this->passThrough('p16_ytd');
        $ctes['p18_expected_sign'] = $this->expectedSignCte('p17_contra_gst');
        $ctes['p19_inverse'] = $this->inverseCte('p18_expected_sign');
        $ctes['p20_consolidate'] = $this->passThrough('p19_inverse');
        $ctes['p21_dynamic_bank'] = $this->dynamicBankCte('p20_consolidate');
        $ctes['p22_hide_empty'] = $this->passThrough('p21_dynamic_bank');
        $ctes['p23_hide_accounts'] = $this->passThrough('p22_hide_empty');
        $ctes['p24_format'] = $this->formatCte('p23_hide_accounts');

        $parts = [];
        foreach ($ctes as $name => $body) {
            $materialized = $name === 'lines_by_day' ? ' MATERIALIZED' : '';
            $parts[] = "{$name} AS{$materialized} (\n{$body}\n)";
        }

        // Pipes 2–6 are the scan — lines, not cells. Project them onto
        // intervals the way pipe 7 will, so every stage diffs the same shape.
        $select = in_array($stage, ['p02_scan', 'p03_mf_trackers', 'p04_mapped_in', 'p05_nesting', 'p06_query'], true)
            ? "SELECT l.account_id, COALESCE(m.interval_index, 1) AS interval_index, SUM(l.amount) AS amount\n"
              ."FROM ({$this->byDay($stage)}) l LEFT JOIN months m ON l.date BETWEEN m.month_start AND m.month_end\n"
              ."GROUP BY 1, 2 ORDER BY 1, 2"
            : "SELECT account_id, interval_index, amount FROM {$stage} ORDER BY account_id, interval_index";

        return "WITH RECURSIVE\n".implode(",\n\n", $parts)."\n\n".$select;
    }

    /**
     * Lines collapsed to one row per account and day before they meet the
     * month spine. A LEFT range join cannot use DuckDB's IEJoin, so bucketing
     * raw lines compares every line against every month — 500M lines over
     * 348 months is ~174 billion comparisons. Days are bounded (~11k over 30
     * years), so the range join then runs on thousands of rows at any volume.
     */
    private function byDay(string $lines): string
    {
        return "SELECT account_id, date, SUM(amount) AS amount FROM {$lines} GROUP BY account_id, date";
    }

    private function passThrough(string $previous): string
    {
        return "    SELECT * FROM {$previous}";
    }

    private function farmCte(): string
    {
        return <<<SQL
            SELECT farm_id, financial_year_end_month, country_code
            FROM {$this->appAlias}.farms
            WHERE farm_id = \$farm_id
            SQL;
    }

    /**
     * Every month in the period, with the financial year each belongs to —
     * the thing pipes 9, 10, 15 and 16 all reset on.
     */
    private function monthSpineCte(): string
    {
        return <<<SQL
            SELECT
                row_number() OVER (ORDER BY m) AS interval_index,
                CAST(m AS DATE) AS month_start,
                (CAST(m AS DATE) + INTERVAL 1 MONTH - INTERVAL 1 DAY)::DATE AS month_end,
                year(CAST(m AS DATE)) + CASE WHEN month(CAST(m AS DATE)) > f.financial_year_end_month THEN 1 ELSE 0 END AS fy,
                month(CAST(m AS DATE)) = (f.financial_year_end_month % 12) + 1 AS is_fy_start,
                f.financial_year_end_month
            FROM generate_series(
                CAST(\$period_from AS DATE),
                CAST(\$period_to AS DATE),
                INTERVAL 1 MONTH
            ) AS g(m)
            CROSS JOIN farm f
            SQL;
    }

    /**
     * The farm's lines, read once and collapsed to one row per account, day,
     * basis, type and tag. Every scan below — the report's, the overdraft
     * sub-report's, the CYE and retained-earnings sub-reports' and the account
     * list — used to read transaction_lines itself, six passes over the same
     * files; 500M lines collapse to ~59k rows here, so the rest read those.
     * Bounded to the period's financial year unless retained earnings needs
     * the whole history — see readsWholeHistory().
     *
     * The LIMIT is an optimiser fence. Without it DuckDB pushes the OR of
     * every consumer's predicate into the CTE and evaluates it per line —
     * 21 s of CPU over 500M lines that all pass, since the consumers between
     * them want everything. Each consumer filters the ~59k day rows instead.
     */
    private function linesByDayCte(): string
    {
        $bound = $this->readsWholeHistory()
            ? ''
            : "AND date >= {$this->financialYearStartOfPeriod()} AND date <= CAST(\$period_to AS DATE)";

        // farm_id and basis are partition columns, constant within a file, yet
        // grouping on them hashes both strings for every line: ~0.7 s of a
        // 3.2 s pass at 1B lines. So farm_id is the parameter, and basis a
        // literal unless an accrual report also needs the cash sub-report.
        $bases = array_values(array_unique([$this->options->basis, 'cash']));
        $basis = count($bases) === 1 ? "'{$bases[0]}' AS basis" : 'basis';
        $basisGroup = count($bases) === 1 ? '' : ', basis';
        $basisIn = "'".implode("', '", $bases)."'";

        return <<<SQL
            SELECT CAST(\$farm_id AS VARCHAR) AS farm_id, account_id, date, {$basis}, type, tag, SUM(amount) AS amount
            FROM {$this->alias}.transaction_lines
            WHERE farm_id = \$farm_id
              AND basis IN ({$basisIn})
              {$bound}
            GROUP BY account_id, date, type, tag{$basisGroup}
            LIMIT 9223372036854775807
            SQL;
    }

    /**
     * Only retained earnings needs every season the farm has; without it no
     * scan reads before the period's financial year, so the one pass stops
     * at its start.
     */
    private function readsWholeHistory(): bool
    {
        return $this->options->calculateRetained;
    }

    /**
     * The account list and the bank opening balance reach past the period on
     * both sides. They read lines_by_day when it holds the whole history, and
     * otherwise the table itself, as they did before it existed: each reads
     * one or two columns, which is cheaper than widening the one pass.
     */
    private function farmHistory(): string
    {
        return $this->readsWholeHistory() ? 'lines_by_day' : "{$this->alias}.transaction_lines";
    }

    private function financialYearStartOfPeriod(): string
    {
        return "(SELECT MIN(CASE WHEN month(CAST(\$period_from AS DATE)) > financial_year_end_month
                                THEN make_date(year(CAST(\$period_from AS DATE)), financial_year_end_month + 1, 1)
                                ELSE make_date(year(CAST(\$period_from AS DATE)) - 1, financial_year_end_month + 1, 1) END)
               FROM farm)";
    }

    /**
     * The report's account list: PrepareEmptyArray zeroes a cell for each.
     *
     * Figured is handed the list by the structure builder. Here it is every
     * account the farm has ever posted to, plus the Xero accounts its internal
     * accounts fold into, plus the system accounts the pipes write to. System
     * accounts are not farm-scoped in this PoC's dimension table.
     */
    private function reportAccountsCte(): string
    {
        return <<<SQL
            SELECT DISTINCT account_id FROM (
                SELECT tl.account_id
                FROM {$this->farmHistory()} tl
                WHERE tl.farm_id = \$farm_id
                UNION ALL
                SELECT a.mapped_to_account_id
                FROM {$this->appAlias}.accounts a
                WHERE a.mapped_to_account_id IS NOT NULL
                  AND a.account_id IN (SELECT account_id FROM {$this->farmHistory()} WHERE farm_id = \$farm_id)
                UNION ALL
                SELECT a.account_id FROM {$this->appAlias}.accounts a WHERE a.system_account IS NOT NULL AND (a.farm_id IS NULL OR a.farm_id = \$farm_id)
            ) x
            SQL;
    }

    private function p01Empty(): string
    {
        return <<<SQL
            SELECT ra.account_id, m.interval_index, CAST(0 AS BIGINT) AS amount
            FROM report_accounts ra
            CROSS JOIN months m
            SQL;
    }

    /**
     * Pipes 2, 5, 6: the Mongo `$match`, as a predicate.
     *
     * YTD widens the scan back to the start of the period's first financial
     * year; those earlier lines fall into the first interval (Figured's
     * `getUpdatedIntervalStart`). The horizon split is the same one every
     * report here uses. EOY exclusion is a tag `$nin`.
     */
    private function scanCte(PipelineOptions $o): string
    {
        $from = $o->ytd ? $this->financialYearStartOfPeriod() : 'CAST($period_from AS DATE)';

        $eoy = $o->excludeEoyJournals
            ? "AND (tl.tag IS NULL OR tl.tag <> '".self::TAG_EOY."')"
            : '';

        return <<<SQL
            SELECT
                tl.account_id,
                tl.date,
                tl.amount,
                tl.tag,
                tl.type
            FROM lines_by_day tl
            WHERE tl.farm_id = \$farm_id
              AND tl.basis = '{$o->basis}'
              AND tl.date >= {$from}
              AND tl.date <= CAST(\$period_to AS DATE)
              AND (
                    (tl.date <= CAST(\$horizon AS DATE) AND tl.type = 'actuals')
                 OR (tl.date >  CAST(\$horizon AS DATE) AND tl.type = 'forecast')
              )
              {$eoy}
            SQL;
    }

    /**
     * Pipe 7: results onto the empty array. Lines before the first interval
     * (a YTD scan) land in it.
     */
    private function cellsCte(string $empty, string $scan, PipelineOptions $o): string
    {
        return <<<SQL
            SELECT
                e.account_id,
                e.interval_index,
                e.amount + COALESCE(s.amount, 0) AS amount
            FROM {$empty} e
            LEFT JOIN (
                SELECT
                    l.account_id,
                    COALESCE(m.interval_index, 1) AS interval_index,
                    SUM(l.amount) AS amount
                FROM ({$this->byDay($scan)}) l
                LEFT JOIN months m ON l.date BETWEEN m.month_start AND m.month_end
                GROUP BY 1, 2
            ) s ON s.account_id = e.account_id AND s.interval_index = e.interval_index
            SQL;
    }

    /**
     * The GST payments/refunds virtual journal (priority 145).
     *
     * NZ, two-monthly, payments basis, FY ending June: a settlement on the
     * 28th of each odd month covering the two calendar months before it.
     * Three journal kinds, as `createJournalsForGstMovements` emits them:
     *
     * 1. the predicted payment, on the payments/refunds account at the payment
     *    date — minus any settlement already made inside that window, so an
     *    actual payment is not also predicted;
     * 2. a reversal on the GST account for each actual settlement, so the net
     *    GST line shows tax components only;
     * 3. the actual settlement itself moved onto the payments line.
     *
     * Sign: net GST owed is credit-negative; a payment is a cash outflow, so
     * the predicted amount is the window's net negated.
     */
    private function gstPaymentsRefundsVjCte(): string
    {
        $pay = self::TAG_GST_PAYMENT;

        return <<<SQL
            WITH gst_accounts AS (
                SELECT
                    (SELECT account_id FROM {$this->appAlias}.accounts WHERE system_account = 'GST' AND (farm_id IS NULL OR farm_id = \$farm_id) LIMIT 1) AS gst_id,
                    (SELECT account_id FROM {$this->appAlias}.accounts WHERE system_account = 'GSTPAYMENTS' AND (farm_id IS NULL OR farm_id = \$farm_id) LIMIT 1) AS payments_id
            ),
            -- Net GST per calendar month, tax components only, across the
            -- whole scan (the window for a July payment reaches back to May).
            net_by_month AS (
                SELECT date_trunc('month', l.date)::DATE AS ms, SUM(l.amount) AS net
                FROM inner_scan l, gst_accounts g
                WHERE l.account_id = g.gst_id AND (l.tag IS NULL OR l.tag <> '{$pay}')
                GROUP BY 1
            ),
            settlements AS (
                SELECT l.date, date_trunc('month', l.date)::DATE AS ms, l.amount
                FROM inner_scan l, gst_accounts g
                WHERE l.account_id = g.gst_id AND l.tag = '{$pay}'
            ),
            -- Payment dates in the period: 28th of odd months; the December
            -- and April payments are pushed to 15 Jan and 7 May.
            payment_dates AS (
                SELECT
                    CASE
                        WHEN month(m.month_start) = 1 AND (SELECT financial_year_end_month FROM farm) % 2 = 1 THEN make_date(year(m.month_start), 1, 15)
                        WHEN month(m.month_start) = 5 AND (SELECT financial_year_end_month FROM farm) % 2 = 1 THEN make_date(year(m.month_start), 5, 7)
                        ELSE make_date(year(m.month_start), month(m.month_start), 28)
                    END AS pay_date,
                    (m.month_start - INTERVAL 2 MONTH)::DATE AS window_start,
                    (m.month_start - INTERVAL 1 DAY)::DATE AS window_end
                FROM months m
                WHERE (month(m.month_start) % 2) = ((SELECT financial_year_end_month FROM farm) + 1) % 2
            ),
            predicted AS (
                SELECT
                    g.payments_id AS account_id,
                    pd.pay_date AS date,
                    -(COALESCE((SELECT SUM(net) FROM net_by_month n WHERE n.ms BETWEEN pd.window_start AND pd.window_end), 0))
                    - COALESCE((SELECT SUM(amount) FROM settlements s WHERE s.date BETWEEN pd.window_start AND pd.window_end), 0) AS amount
                FROM payment_dates pd, gst_accounts g
            )
            SELECT account_id, date, amount FROM predicted WHERE amount <> 0
            UNION ALL
            SELECT g.gst_id, s.date, -s.amount FROM settlements s, gst_accounts g
            UNION ALL
            SELECT g.payments_id, s.date, s.amount FROM settlements s, gst_accounts g
            SQL;
    }

    /**
     * `MergeVirtualJournals` for one handler: add each journal to the cell of
     * the interval its date falls in. Journals outside the period are dropped,
     * as Figured drops them.
     */
    private function addJournalsCte(string $cells, string $journals): string
    {
        return <<<SQL
            SELECT
                c.account_id,
                c.interval_index,
                c.amount + COALESCE(j.amount, 0) AS amount
            FROM {$cells} c
            LEFT JOIN (
                SELECT j.account_id, m.interval_index, SUM(j.amount) AS amount
                FROM {$journals} j
                JOIN months m ON j.date BETWEEN m.month_start AND m.month_end
                GROUP BY 1, 2
            ) j ON j.account_id = c.account_id AND j.interval_index = c.interval_index
            SQL;
    }

    /**
     * The overdraft handler's sub-report: `OverdraftCashFlowStructureBuilder`.
     *
     * `net_cash_movement = income - expense + gst`, with bank accounts captured
     * into a hidden section and excluded, and GST its own inverted section.
     * Opening balance is `Balance::getReport()`'s actuals/forecast branch —
     * the bank accounts' own balance up to the day before the period.
     */
    private function innerCashFlowCte(string $cells): string
    {
        return <<<SQL
            WITH classified AS (
                SELECT
                    c.interval_index,
                    c.amount,
                    a.account_class,
                    a.account_type,
                    a.system_account,
                    a.is_gst_account
                FROM {$cells} c
                JOIN {$this->appAlias}.accounts a ON a.account_id = c.account_id
            ),
            movement AS (
                SELECT
                    interval_index,
                    -- income: revenue is credit-negative, so negate; expense:
                    -- everything else that is not bank and not gst, debit-positive.
                    -SUM(CASE WHEN account_class = 'REVENUE' THEN amount ELSE 0 END)
                    - SUM(CASE WHEN account_class <> 'REVENUE'
                                AND COALESCE(account_type, '') <> 'BANK'
                                AND NOT is_gst_account
                                AND COALESCE(system_account, '') NOT IN ('GST', 'GSTPAYMENTS')
                               THEN amount ELSE 0 END)
                    - SUM(CASE WHEN is_gst_account OR COALESCE(system_account, '') IN ('GST', 'GSTPAYMENTS')
                               THEN amount ELSE 0 END) AS net_cash_movement
                FROM classified
                GROUP BY 1
            ),
            opening AS (
                SELECT COALESCE(SUM(tl.amount), 0) AS opening_balance
                FROM {$this->farmHistory()} tl
                JOIN {$this->appAlias}.accounts a ON a.account_id = tl.account_id
                WHERE tl.farm_id = \$farm_id
                  AND tl.basis = 'cash'
                  AND a.account_type = 'BANK'
                  AND tl.date < CAST(\$period_from AS DATE)
                  AND (
                        (tl.date <= CAST(\$horizon AS DATE) AND tl.type = 'actuals')
                     OR (tl.date >  CAST(\$horizon AS DATE) AND tl.type = 'forecast')
                  )
            )
            SELECT
                m.interval_index,
                m.month_end,
                o.opening_balance
                    + COALESCE(SUM(mv.net_cash_movement) OVER (ORDER BY m.interval_index
                        ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING), 0) AS opening,
                mv.net_cash_movement,
                o.opening_balance
                    + SUM(mv.net_cash_movement) OVER (ORDER BY m.interval_index
                        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS closing
            FROM months m
            JOIN movement mv ON mv.interval_index = m.interval_index
            CROSS JOIN opening o
            SQL;
    }

    /**
     * The overdraft in force for the period — the latest row starting on or
     * before the period end, as Phase 3 resolves it.
     */
    private function overdraftConfigCte(): string
    {
        return <<<SQL
            SELECT rate FROM {$this->appAlias}.overdrafts
            WHERE farm_id = \$farm_id AND start_date <= CAST(\$period_to AS DATE)
            ORDER BY start_date DESC LIMIT 1
            SQL;
    }

    /**
     * The overdraft handler (priority 150): Phase 3's recurrence, unchanged,
     * over the inner cash flow's closing row. A top-level CTE rather than a
     * nested one because `WITH RECURSIVE` only reaches the outermost chain —
     * the first version nested it and DuckDB refused the self-reference.
     * Monthly term only in this tranche.
     */
    private function overdraftAccrualCte(string $cashflow): string
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
            FROM {$cashflow} cf, od
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
            JOIN {$cashflow} cf ON cf.interval_index = a.interval_index + 1
            CROSS JOIN od
            SQL;
    }

    /** The accrual as journals on the overdraft account, posted at each interval's end. */
    private function overdraftVjCte(): string
    {
        return <<<SQL
            SELECT
                (SELECT account_id FROM {$this->appAlias}.accounts WHERE system_account = 'OVERDRAFT' AND (farm_id IS NULL OR farm_id = \$farm_id) LIMIT 1) AS account_id,
                month_end AS date,
                CAST(floor(interest) AS BIGINT) AS amount
            FROM od_accrual
            WHERE interest > 0
            SQL;
    }

    /**
     * Pipe 8 proper: both handlers' journals onto the outer cells, filtered
     * to the report's basis (both handlers emit per basis; the PoC lake is
     * one basis per line, so the filter is the scan's).
     */
    private function mergeVjCte(string $cells): string
    {
        return <<<SQL
            SELECT
                c.account_id,
                c.interval_index,
                c.amount + COALESCE(j.amount, 0) AS amount
            FROM {$cells} c
            LEFT JOIN (
                SELECT j.account_id, m.interval_index, SUM(j.amount) AS amount
                FROM (
                    SELECT account_id, date, amount FROM gst_vj
                    UNION ALL
                    SELECT account_id, date, amount FROM overdraft_vj
                ) j
                JOIN months m ON j.date BETWEEN m.month_start AND m.month_end
                GROUP BY 1, 2
            ) j ON j.account_id = c.account_id AND j.interval_index = c.interval_index
            SQL;
    }

    /**
     * Pipe 9: budget periods only. Opening bank onto the default bank account
     * and its contra onto retained earnings, first interval of each financial
     * year in the period.
     */
    private function openingBankCte(string $previous): string
    {
        if ($this->options->type !== 'budget') {
            return $this->passThrough($previous);
        }

        return <<<SQL
            SELECT
                p.account_id,
                p.interval_index,
                p.amount
                    + CASE WHEN a.is_default_bank_account AND fy_first.interval_index IS NOT NULL THEN COALESCE(ob.opening_bank, 0)
                           WHEN a.system_account = 'RETAINED_EARNINGS' AND fy_first.interval_index IS NOT NULL THEN -COALESCE(ob.opening_bank, 0)
                           ELSE 0 END AS amount
            FROM {$previous} p
            JOIN {$this->appAlias}.accounts a ON a.account_id = p.account_id
            JOIN months m ON m.interval_index = p.interval_index
            LEFT JOIN (
                SELECT fy, MIN(interval_index) AS interval_index FROM months GROUP BY fy
            ) fy_first ON fy_first.fy = m.fy AND fy_first.interval_index = p.interval_index
            LEFT JOIN {$this->appAlias}.opening_balances ob ON ob.farm_id = \$farm_id AND ob.financial_year = m.fy
            SQL;
    }

    /**
     * Pipe 10: budget, YTD, `includeOpeningBudgetGst`. Opening GST onto the
     * GST account, contra onto retained earnings, on each FY's first month
     * (only when the period actually starts a financial year).
     */
    private function openingGstCte(string $previous): string
    {
        $o = $this->options;
        if ($o->type !== 'budget' || !$o->ytd || !$o->includeOpeningBudgetGst) {
            return $this->passThrough($previous);
        }

        return <<<SQL
            SELECT
                p.account_id,
                p.interval_index,
                p.amount
                    + CASE WHEN a.system_account = 'GST' AND m.is_fy_start THEN COALESCE(ob.opening_gst, 0)
                           WHEN a.system_account = 'RETAINED_EARNINGS' AND m.is_fy_start THEN -COALESCE(ob.opening_gst, 0)
                           ELSE 0 END AS amount
            FROM {$previous} p
            JOIN {$this->appAlias}.accounts a ON a.account_id = p.account_id
            JOIN months m ON m.interval_index = p.interval_index
            LEFT JOIN {$this->appAlias}.opening_balances ob ON ob.farm_id = \$farm_id AND ob.financial_year = m.fy
            SQL;
    }

    /**
     * Pipe 13: each internal account's cells onto the Xero account it maps
     * to, then the internal row dropped. Always on.
     */
    private function mergeMappedCte(string $previous): string
    {
        return <<<SQL
            SELECT
                COALESCE(a.mapped_to_account_id, p.account_id) AS account_id,
                p.interval_index,
                SUM(p.amount) AS amount
            FROM {$previous} p
            JOIN {$this->appAlias}.accounts a ON a.account_id = p.account_id
            GROUP BY 1, 2
            SQL;
    }

    /**
     * Pipe 14: a nested YTD sub-report of `allincome - allexpenses`, its
     * total inverted and written onto the current-year-earnings account.
     * The sub-report's scan is the outer one widened to the FY start.
     */
    private function currentYearEarningsCte(string $previous): string
    {
        if (!$this->options->calculateCurrentYearEarnings) {
            return $this->passThrough($previous);
        }

        $ytdScan = $this->scanCte($this->options->withYtd());

        return <<<SQL
            WITH cye_scan AS (
            {$ytdScan}
            ),
            cye_by_month AS (
                SELECT
                    COALESCE(m.interval_index, 1) AS interval_index,
                    SUM(CASE WHEN a.account_class = 'REVENUE' THEN l.amount ELSE 0 END) AS income,
                    SUM(CASE WHEN a.account_class = 'EXPENSE' THEN l.amount ELSE 0 END) AS expenses
                FROM ({$this->byDay('cye_scan')}) l
                JOIN {$this->appAlias}.accounts a ON a.account_id = l.account_id
                LEFT JOIN months m ON l.date BETWEEN m.month_start AND m.month_end
                GROUP BY 1
            ),
            cye AS (
                SELECT
                    m.interval_index,
                    -(SUM(COALESCE(b.income, 0)) OVER (ORDER BY m.interval_index ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
                      - SUM(COALESCE(b.expenses, 0)) OVER (ORDER BY m.interval_index ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)) AS amount
                FROM months m
                LEFT JOIN cye_by_month b ON b.interval_index = m.interval_index
            )
            SELECT
                p.account_id,
                p.interval_index,
                CASE WHEN a.system_account = 'CURRENT_YEAR_EARNINGS' THEN c.amount ELSE p.amount END AS amount
            FROM {$previous} p
            JOIN {$this->appAlias}.accounts a ON a.account_id = p.account_id
            LEFT JOIN cye c ON c.interval_index = p.interval_index
            SQL;
    }

    /**
     * Pipe 15: retained earnings. A nested yearly sub-report of net profit
     * per season from the farm's first transaction to the period's last
     * season; at each interval, the seasons before its FY are summed and
     * inverted, added to a running total of what was posted directly to the
     * RE account. Mirrors `RetainedEarnings::handle()` term for term.
     */
    private function retainedEarningsCte(string $previous): string
    {
        if (!$this->options->calculateRetained) {
            return $this->passThrough($previous);
        }

        $eoy = $this->options->excludeEoyJournals
            ? "AND (tl.tag IS NULL OR tl.tag <> '".self::TAG_EOY."')"
            : '';

        return <<<SQL
            WITH season_profit AS (
                SELECT
                    year(tl.date) + CASE WHEN month(tl.date) > f.financial_year_end_month THEN 1 ELSE 0 END AS season,
                    SUM(CASE WHEN a.account_class = 'REVENUE' THEN tl.amount ELSE 0 END)
                      - SUM(CASE WHEN a.account_class = 'EXPENSE' THEN tl.amount ELSE 0 END) AS net_profit
                FROM lines_by_day tl
                JOIN {$this->appAlias}.accounts a ON a.account_id = tl.account_id
                CROSS JOIN farm f
                WHERE tl.farm_id = \$farm_id
                  AND tl.basis = '{$this->options->basis}'
                  AND tl.date <= CAST(\$period_to AS DATE)
                  AND (
                        (tl.date <= CAST(\$horizon AS DATE) AND tl.type = 'actuals')
                     OR (tl.date >  CAST(\$horizon AS DATE) AND tl.type = 'forecast')
                  )
                  {$eoy}
                GROUP BY 1
            ),
            per_interval AS (
                SELECT
                    m.interval_index,
                    COALESCE((SELECT SUM(net_profit) FROM season_profit s WHERE s.season < m.fy), 0) AS retained_before_fy
                FROM months m
            ),
            re_running AS (
                SELECT
                    p.interval_index,
                    SUM(p.amount) OVER (ORDER BY p.interval_index ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS posted_running
                FROM {$previous} p
                JOIN {$this->appAlias}.accounts a ON a.account_id = p.account_id
                WHERE a.system_account = 'RETAINED_EARNINGS'
            )
            SELECT
                p.account_id,
                p.interval_index,
                CASE WHEN a.system_account = 'RETAINED_EARNINGS'
                     THEN CAST(r.posted_running - pi.retained_before_fy AS BIGINT)
                     ELSE p.amount END AS amount
            FROM {$previous} p
            JOIN {$this->appAlias}.accounts a ON a.account_id = p.account_id
            LEFT JOIN per_interval pi ON pi.interval_index = p.interval_index
            LEFT JOIN re_running r ON r.interval_index = p.interval_index
            SQL;
    }

    /**
     * Pipe 16: YTD — a running sum per account across intervals, reset at
     * each season start when `ytd_type = season`; retained and current-year
     * earnings are skipped because they are already balances.
     */
    private function ytdCte(string $previous): string
    {
        if (!$this->options->ytd) {
            return $this->passThrough($previous);
        }

        $partition = $this->options->ytdType === 'season' ? 'p.account_id, m.fy' : 'p.account_id';

        return <<<SQL
            SELECT
                p.account_id,
                p.interval_index,
                CASE WHEN a.system_account IN ('RETAINED_EARNINGS', 'CURRENT_YEAR_EARNINGS') THEN p.amount
                     ELSE SUM(p.amount) OVER (PARTITION BY {$partition} ORDER BY p.interval_index
                                              ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
                END AS amount
            FROM {$previous} p
            JOIN {$this->appAlias}.accounts a ON a.account_id = p.account_id
            JOIN months m ON m.interval_index = p.interval_index
            SQL;
    }

    /** Pipe 18. */
    private function expectedSignCte(string $previous): string
    {
        if (!$this->options->showExpectedSign) {
            return $this->passThrough($previous);
        }

        return <<<SQL
            SELECT
                p.account_id,
                p.interval_index,
                CASE WHEN a.inverted_for_user THEN -p.amount ELSE p.amount END AS amount
            FROM {$previous} p
            JOIN {$this->appAlias}.accounts a ON a.account_id = p.account_id
            SQL;
    }

    /** Pipe 19. */
    private function inverseCte(string $previous): string
    {
        if (!$this->options->inverse) {
            return $this->passThrough($previous);
        }

        return "    SELECT account_id, interval_index, -amount AS amount FROM {$previous}";
    }

    /**
     * Pipe 21: a negative default-bank cell becomes zero, and the Figured
     * liability account carries its inverse. Off for reporting groups.
     */
    private function dynamicBankCte(string $previous): string
    {
        if (!$this->options->dynamicBankAccount) {
            return $this->passThrough($previous);
        }

        return <<<SQL
            WITH bank_negatives AS (
                SELECT p.interval_index, p.amount
                FROM {$previous} p
                JOIN {$this->appAlias}.accounts a ON a.account_id = p.account_id
                WHERE a.is_default_bank_account AND p.amount < 0
            )
            SELECT
                p.account_id,
                p.interval_index,
                CASE WHEN a.is_default_bank_account AND p.amount < 0 THEN 0
                     WHEN a.system_account = 'LIABILITY' THEN -COALESCE(bn.amount, 0)
                     ELSE p.amount END AS amount
            FROM {$previous} p
            JOIN {$this->appAlias}.accounts a ON a.account_id = p.account_id
            LEFT JOIN bank_negatives bn ON bn.interval_index = p.interval_index
            SQL;
    }

    /** Pipe 24: last, always. */
    private function formatCte(string $previous): string
    {
        $fp = self::FIXED_POINT;

        return "    SELECT account_id, interval_index, amount / {$fp}.0 AS amount FROM {$previous}";
    }
}
