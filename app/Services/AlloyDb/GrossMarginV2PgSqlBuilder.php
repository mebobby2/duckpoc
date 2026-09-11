<?php

declare(strict_types=1);

namespace App\Services\AlloyDb;

/**
 * Gross Margin V2 for PostgreSQL 17 / AlloyDB.
 *
 * A deliberately faithful port of `GrossMarginV2SqlBuilder`, kept structurally
 * identical so a timing difference is attributable to the engine rather than to
 * a rewrite. Same nine CTEs, same GROUPING SETS, same aggregate-before-join
 * ordering that fixed the 38k-operation disk spill on the DuckDB side.
 *
 * What actually had to change is small, which is the interesting finding:
 *
 * - `strftime(x, '%Y-%m')`      -> `to_char(x, 'YYYY-MM')`
 * - `INTERVAL 1 MONTH`          -> `INTERVAL '1 month'`
 * - `month(x)`                  -> `EXTRACT(MONTH FROM x)`
 * - `$param`                    -> `:param` (PDO named placeholders)
 *
 * Everything load-bearing is standard SQL both engines implement natively:
 * `GROUPING SETS`, `GROUPING()`, `any_value()` (PostgreSQL 16+),
 * `WITH ... AS MATERIALIZED` (PostgreSQL 12+), and window frames.
 *
 * The one structural difference is that `transaction_lines` and `accounts` sit
 * in the same database here, so there is no federated join to MySQL — on the
 * DuckDB side that join crosses an attached-database boundary on every report.
 */
final class GrossMarginV2PgSqlBuilder
{
    private const int FIXED_POINT = 10000;

    public function build(): string
    {
        return <<<'SQL'
            WITH months AS (
                SELECT
                    m.month_start::DATE AS month_start,
                    to_char(m.month_start, 'YYYY-MM') AS month,
                    row_number() OVER (ORDER BY m.month_start) AS interval_index,
                    CASE WHEN m.month_start <= date_trunc('month', CAST(:horizon AS DATE))
                         THEN 'Actual' ELSE 'Forecast' END AS column_basis
                FROM generate_series(
                    date_trunc('month', CAST(:period_from AS DATE)),
                    date_trunc('month', CAST(:period_to AS DATE)),
                    INTERVAL '1 month'
                ) AS m(month_start)
            ),

            account_scope AS (
                SELECT
                    account_id,
                    account_name,
                    account_class,
                    report_group,
                    report_group_label,
                    report_group_order,
                    line_order
                FROM accounts
                WHERE report_group IS NOT NULL
            ),

            -- Collapse the facts to one row per (month, account) before any
            -- dimension column is attached. Joining names onto every journal
            -- line first and aggregating afterwards builds an intermediate wide
            -- enough to exhaust memory and spill; this leaves ~600 rows.
            monthly_by_account AS MATERIALIZED (
                SELECT
                    date_trunc('month', tl.date)::DATE AS month_start,
                    tl.account_id,
                    SUM(tl.amount) AS amount_raw
                FROM transaction_lines tl
                WHERE tl.farm_id = :farm_id
                  AND tl.basis   = :basis
                  AND tl.account_id IN (SELECT account_id FROM account_scope)
                  AND tl.date BETWEEN CAST(:period_from2 AS DATE) AND CAST(:period_to2 AS DATE)
                  AND (
                        (tl.date <= CAST(:horizon2 AS DATE) AND tl.type = 'actuals')
                     OR (tl.date >  CAST(:horizon3 AS DATE) AND tl.type = 'forecast')
                  )
                GROUP BY 1, 2
            ),

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
                    CASE WHEN a.report_group LIKE '%!_income' ESCAPE '!' THEN 'income' ELSE 'costs' END AS report_section,
                    CASE WHEN a.report_group LIKE '%!_income' ESCAPE '!' THEN 1 ELSE 2 END AS report_section_order
                FROM monthly_by_account f
                JOIN account_scope a ON a.account_id = f.account_id
            ),

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
                    CASE WHEN g_group = 1 THEN 99 ELSE report_group_order END AS report_group_order,
                    CASE WHEN g_account = 1 THEN NULL ELSE account_name END AS account_name,
                    CASE WHEN g_account = 1 THEN 0 ELSE line_order END AS line_order,
                    amount,
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

            milk_units AS (
                SELECT
                    mp.month::DATE AS month_start,
                    SUM(mp.kg_ms_current + mp.kg_ms_deferred) AS quantity
                FROM tracker_milk_production mp
                JOIN trackers t ON t.tracker_id = mp.tracker_id
                WHERE t.farm_id = :farm_id2
                GROUP BY 1
            ),

            -- Unfiltered by date on purpose: a running balance needs the whole
            -- history before the period, or every closing figure is wrong.
            stock_running AS (
                SELECT
                    mv.tracker_id,
                    mv.month::DATE AS month_start,
                    t.opening_stock
                        + SUM(mv.purchases + mv.births - mv.sales - mv.deaths)
                          OVER (PARTITION BY mv.tracker_id ORDER BY mv.month
                                ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS closing_head
                FROM tracker_stock_movements mv
                JOIN trackers t ON t.tracker_id = mv.tracker_id
                WHERE t.farm_id = :farm_id3
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

    public function buildSourceRowCountSql(): string
    {
        $fp = self::FIXED_POINT;

        return <<<SQL
            SELECT
                count(*) AS n,
                count(DISTINCT a.report_group) AS n_groups,
                count(DISTINCT tl.tracker_id) AS n_trackers,
                sum(tl.amount) / {$fp}.0 AS net_dollars
            FROM transaction_lines tl
            JOIN accounts a ON a.account_id = tl.account_id
            WHERE tl.farm_id = :farm_id
              AND tl.basis   = :basis
              AND a.report_group IS NOT NULL
              AND tl.date BETWEEN CAST(:period_from AS DATE) AND CAST(:period_to AS DATE)
              AND (
                    (tl.date <= CAST(:horizon AS DATE) AND tl.type = 'actuals')
                 OR (tl.date >  CAST(:horizon2 AS DATE) AND tl.type = 'forecast')
              )
            SQL;
    }
}
