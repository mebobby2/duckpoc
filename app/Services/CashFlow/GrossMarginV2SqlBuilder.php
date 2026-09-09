<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

/**
 * Gross Margin V2 — the hierarchical, mixed-enterprise report, as one query.
 *
 * V1 produced a flat table per tracker, for one tracker type. The real report
 * is a tree over *mixed* types:
 *
 *     Income
 *       Dairy Income      -> Milk Production - Current Year / Deferred
 *       Livestock Income  -> Sales - Bobby Calves / R2 Heifers / MA Cows / Bulls
 *       Other
 *     Income Total
 *     Direct Costs ...
 *     Gross Margin
 *
 * Figured needs two reports for this, because milk and livestock quantities
 * come from different services and the two structure builders exclude each
 * other. Hence: "it's a totally different query for milk vs livestock, so
 * running it concurrently is hard in the old way."
 *
 * Three things make it one query here:
 *
 * 1. **GROUPING SETS.** Line items, section subtotals and the grand total are
 *    three different aggregation levels of the same scan. Emitting them in one
 *    pass is what removes the stitching — `GROUPING()` then labels which level
 *    each row is, so the view can render a tree without the query being run
 *    once per level.
 * 2. **The hierarchy is data.** `accounts.report_group` says which section an
 *    account belongs to, so adding an enterprise type is a row, not a branch.
 * 3. **Quantities are unioned, not branched.** Milk (kg MS, a flow) and
 *    livestock (head, a running balance from movements) genuinely have
 *    different shapes, so they are computed separately — but as two CTEs in
 *    one statement, normalised to (tracker, month, unit, quantity). Different
 *    shapes, same query.
 */
final class GrossMarginV2SqlBuilder
{
    private const int FIXED_POINT = 10000;

    public function __construct(
        /** The DuckLake catalog — journal facts. */
        private readonly string $alias,
        /** The attached MySQL database — accounts, trackers, quantities. */
        private readonly string $appAlias,
    ) {
    }

    public function build(): string
    {
        $fp = self::FIXED_POINT;

        return <<<SQL
            WITH months AS (
                SELECT
                    m.month_start::DATE AS month_start,
                    strftime(m.month_start, '%Y-%m') AS month,
                    row_number() OVER (ORDER BY m.month_start) AS interval_index,
                    -- The Actual/Forecast label the real report shows beneath
                    -- each column header.
                    CASE WHEN m.month_start <= date_trunc('month', CAST(\$horizon AS DATE))
                         THEN 'Actual' ELSE 'Forecast' END AS column_basis
                FROM generate_series(
                    date_trunc('month', CAST(\$period_from AS DATE)),
                    date_trunc('month', CAST(\$period_to AS DATE)),
                    INTERVAL 1 MONTH
                ) AS m(month_start)
            ),

            -- The accounts this report can place, and the only dimension
            -- columns it needs. Tiny, and it doubles as the scope filter below.
            account_scope AS (
                SELECT
                    account_id,
                    account_name,
                    account_class,
                    report_group,
                    report_group_label,
                    report_group_order,
                    line_order
                FROM {$this->appAlias}.accounts
                WHERE report_group IS NOT NULL
            ),

            -- Collapse the fact table to one row per (month, account) BEFORE
            -- any dimension column is attached.
            --
            -- The previous shape materialised the joined lines — every journal
            -- row carrying its account's name, class and labels — and a
            -- 12-month window on the 500M-row farm made that 125M rows wide
            -- enough to exceed a 13.4 GiB memory limit, spilling ~38k local
            -- reads and writes. Aggregating first leaves ~600 rows, because
            -- SUM is associative: summing per account and then per group gives
            -- the same totals as summing the raw lines once.
            --
            -- MATERIALIZED belongs here rather than on the raw scan. `levels`
            -- is read twice below (once for the margin, once for the rows), so
            -- without it DuckDB is free to re-derive this CTE and scan the lake
            -- twice. Pinning the small aggregate guarantees one scan.
            monthly_by_account AS MATERIALIZED (
                SELECT
                    date_trunc('month', tl.date)::DATE AS month_start,
                    tl.account_id,
                    SUM(tl.amount) AS amount_raw
                FROM {$this->alias}.transaction_lines tl
                {$this->factScopePredicate()}
                GROUP BY 1, 2
            ),

            -- Section is derived, not stored: every `*_income` group rolls up
            -- to Income and every `*_costs` group to Direct Costs. Keeping it
            -- derived means a new enterprise type needs no schema change.
            --
            -- Revenue is stored as a credit, so REVENUE-class accounts are
            -- flipped here. Flipping the per-account subtotal is equivalent to
            -- flipping each line: account_class is a property of the account,
            -- so every line inside one of these groups shares the same sign.
            classified AS (
                SELECT
                    f.month_start,
                    f.account_id,
                    a.account_name,
                    a.account_class,
                    a.report_group,
                    a.report_group_label,
                    a.report_group_order,
                    a.line_order,
                    CASE WHEN a.account_class = 'REVENUE' THEN -f.amount_raw ELSE f.amount_raw END AS amount,
                    CASE WHEN a.report_group LIKE '%_income' THEN 'income' ELSE 'costs' END AS report_section,
                    CASE WHEN a.report_group LIKE '%_income' THEN 1 ELSE 2 END AS report_section_order
                FROM monthly_by_account f
                JOIN account_scope a ON a.account_id = f.account_id
            ),

            -- Line items, group subtotals AND section totals from ONE scan.
            levels AS (
                SELECT
                    c.month_start,
                    c.report_section,
                    c.report_group,
                    c.account_id,
                    any_value(c.report_section_order) AS report_section_order,
                    any_value(c.report_group_label) AS report_group_label,
                    any_value(c.report_group_order) AS report_group_order,
                    any_value(c.account_name) AS account_name,
                    any_value(c.line_order) AS line_order,
                    SUM(c.amount) / 10000.0 AS amount,
                    GROUPING(c.account_id) AS g_account,
                    GROUPING(c.report_group) AS g_group
                FROM classified c
                GROUP BY GROUPING SETS (
                    (c.month_start, c.report_section, c.report_group, c.account_id),
                    (c.month_start, c.report_section, c.report_group),
                    (c.month_start, c.report_section)
                )
            ),

            -- Gross Margin is Income minus Direct Costs. Not a GROUPING SETS
            -- level — it is a difference between two of them — so it is
            -- derived from the section totals and unioned in as its own row.
            gross_margin AS (
                SELECT
                    month_start,
                    SUM(CASE WHEN report_section = 'income' THEN amount ELSE -amount END) AS amount
                FROM levels
                WHERE g_group = 1
                GROUP BY 1
            ),

            rows_out AS (
                SELECT
                    month_start,
                    report_section,
                    report_group,
                    account_id,
                    report_section_order,
                    CASE
                        WHEN g_group = 1 THEN CASE WHEN report_section = 'income' THEN 'Income' ELSE 'Direct Costs' END
                        ELSE report_group_label
                    END AS label,
                    -- Section totals sort after every group inside them.
                    CASE WHEN g_group = 1 THEN 99 ELSE report_group_order END AS report_group_order,
                    -- any_value() still returns a name where account_id is
                    -- NULL, so blank it explicitly rather than let an
                    -- arbitrary line item's label appear as a heading.
                    CASE WHEN g_account = 1 THEN NULL ELSE account_name END AS account_name,
                    CASE WHEN g_account = 1 THEN 0 ELSE line_order END AS line_order,
                    amount,
                    -- 0 line item, 1 group subtotal, 2 section total.
                    CAST(g_account + g_group AS INTEGER) AS level
                FROM levels

                UNION ALL

                SELECT
                    month_start,
                    'margin' AS report_section,
                    NULL AS report_group,
                    NULL AS account_id,
                    3 AS report_section_order,
                    'Gross Margin' AS label,
                    0 AS report_group_order,
                    NULL AS account_name,
                    0 AS line_order,
                    amount,
                    3 AS level
                FROM gross_margin
            ),

            -- Milk: a flow, measured directly.
            milk_units AS (
                SELECT
                    mp.month::DATE AS month_start,
                    SUM(mp.kg_ms_current + mp.kg_ms_deferred) AS quantity
                FROM {$this->appAlias}.tracker_milk_production mp
                JOIN {$this->appAlias}.trackers t ON t.tracker_id = mp.tracker_id
                WHERE t.farm_id = \$farm_id
                GROUP BY 1
            ),

            -- Livestock: a running balance, so it needs the whole history
            -- before the period, exactly as in V1. Unfiltered by date on
            -- purpose; the period filter is applied when joining the spine.
            stock_running AS (
                SELECT
                    mv.tracker_id,
                    mv.month::DATE AS month_start,
                    t.opening_stock
                        + SUM(mv.purchases + mv.births - mv.sales - mv.deaths)
                          OVER (PARTITION BY mv.tracker_id ORDER BY mv.month
                                ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS closing_head
                FROM {$this->appAlias}.tracker_stock_movements mv
                JOIN {$this->appAlias}.trackers t ON t.tracker_id = mv.tracker_id
                WHERE t.farm_id = \$farm_id
            ),

            stock_units AS (
                SELECT month_start, SUM(closing_head) AS quantity
                FROM stock_running
                GROUP BY 1
            )

            SELECT
                m.interval_index,
                m.month,
                m.column_basis,
                r.report_section,
                r.report_group,
                r.label,
                r.account_id,
                r.account_name,
                r.level,
                r.amount,
                mu.quantity AS kg_ms,
                su.quantity AS head,
                -- Per-unit margin against the denominator that suits the
                -- enterprise: kg MS for dairy, head for livestock. Deliberately
                -- absent for Other, section totals and Gross Margin, where
                -- mixing denominators would produce a meaningless figure.
                CASE
                    WHEN r.report_group LIKE 'dairy%' THEN r.amount / NULLIF(mu.quantity, 0)
                    WHEN r.report_group LIKE 'livestock%' THEN r.amount / NULLIF(su.quantity, 0)
                END AS per_unit
            FROM months m
            JOIN rows_out r ON r.month_start = m.month_start
            LEFT JOIN milk_units mu ON mu.month_start = m.month_start
            LEFT JOIN stock_units su ON su.month_start = m.month_start
            ORDER BY r.report_section_order, r.report_group_order, r.level, r.line_order, m.interval_index
            SQL;
    }

    /**
     * The scope predicate for the fact table alone.
     *
     * Same rows as `inScopePredicate()`, expressed without a join: the
     * semi-join against `account_scope` stands in for `a.report_group IS NOT
     * NULL`, which is equivalent because `account_id` is unique in `accounts`.
     * Keeping the dimension out of the scan is what lets the aggregate run
     * before any string column is touched.
     */
    private function factScopePredicate(): string
    {
        return <<<SQL
            WHERE tl.farm_id   = \$farm_id
                  AND tl.basis     = \$basis
                  AND tl.account_id IN (SELECT account_id FROM account_scope)
                  AND tl.date BETWEEN CAST(\$period_from AS DATE) AND CAST(\$period_to AS DATE)
                  AND (
                        (tl.date <= CAST(\$horizon AS DATE) AND tl.type = 'actuals')
                     OR (tl.date >  CAST(\$horizon AS DATE) AND tl.type = 'forecast')
                  )
            SQL;
    }

    /**
     * The one definition of "which journal lines feed this report".
     *
     * Shared with the source-row diagnostics, so the listing cannot show rows
     * the report did not consume. Note `report_group IS NOT NULL`: this report
     * only knows how to place accounts that declare a section, so accounts
     * belonging to other reports on this lake are out of scope by definition
     * rather than by omission.
     */
    private function inScopePredicate(): string
    {
        return <<<SQL
            WHERE tl.farm_id   = \$farm_id
                  AND tl.basis     = \$basis
                  AND a.report_group IS NOT NULL
                  AND tl.date BETWEEN CAST(\$period_from AS DATE) AND CAST(\$period_to AS DATE)
                  AND (
                        (tl.date <= CAST(\$horizon AS DATE) AND tl.type = 'actuals')
                     OR (tl.date >  CAST(\$horizon AS DATE) AND tl.type = 'forecast')
                  )
            SQL;
    }

    /**
     * The journal lines the report consumed, for display.
     *
     * Carries `report_group` so a reader can see which section each line fed —
     * the mapping that makes the hierarchy work is otherwise invisible.
     */
    public function buildSourceRowsSql(): string
    {
        $fp = self::FIXED_POINT;

        return <<<SQL
            SELECT
                tl.line_id,
                tl.date,
                tl.type,
                a.account_name,
                a.account_class,
                a.report_group,
                a.report_group_label,
                tl.tracker_id,
                t.tracker_name,
                t.tracker_type,
                tl.amount AS amount_raw,
                tl.amount / {$fp}.0 AS amount_dollars
            FROM {$this->alias}.transaction_lines tl
            JOIN {$this->appAlias}.accounts a ON a.account_id = tl.account_id
            LEFT JOIN {$this->appAlias}.trackers t ON t.tracker_id = tl.tracker_id
            {$this->inScopePredicate()}
            -- `date` only. Adding `line_id` as a tie-breaker forced DuckDB to
            -- read it for every row in scope to resolve the sort, and it is
            -- ~80% of each file's bytes (unique per row, so barely
            -- compressible). On a 20.8M-row window that turned a 500-row debug
            -- listing into a 35-second column scan, while the report itself
            -- never touches the column at all.
            ORDER BY tl.date
            LIMIT \$row_limit
            SQL;
    }

    public function buildSourceRowCountSql(): string
    {
        $fp = self::FIXED_POINT;

        return <<<SQL
            SELECT
                count(*) AS n,
                count(DISTINCT a.report_group) AS n_groups,
                count(DISTINCT tl.tracker_id) AS n_trackers,
                sum(tl.amount) / {$fp}.0 AS net_dollars
            FROM {$this->alias}.transaction_lines tl
            JOIN {$this->appAlias}.accounts a ON a.account_id = tl.account_id
            {$this->inScopePredicate()}
            SQL;
    }
}
