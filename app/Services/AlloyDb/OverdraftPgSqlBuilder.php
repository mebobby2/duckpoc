<?php

declare(strict_types=1);

namespace App\Services\AlloyDb;

/**
 * Overdraft interest for PostgreSQL / AlloyDB — the Phase 3 port.
 *
 * A faithful translation of `OverdraftSqlBuilder`, kept structurally identical
 * so a timing difference is attributable to the engine rather than a rewrite.
 * Same recursive CTE, same repayment calendar, same feedback of the posted
 * interest onto the balance.
 *
 * What actually had to change is small, which is the same finding the Gross
 * Margin port produced:
 *
 * - `strftime(x, '%Y-%m')`      -> `to_char(x, 'YYYY-MM')`
 * - `month(x)`                  -> `EXTRACT(MONTH FROM x)::INT`
 * - `INTERVAL 1 MONTH`          -> `INTERVAL '1 month'`
 * - `range(0, n)`               -> `generate_series`
 * - `DOUBLE`                    -> `DOUBLE PRECISION`
 * - `$param`                    -> `:param`
 *
 * `WITH RECURSIVE`, `LATERAL`, window frames and `trunc()` are standard and
 * needed no translation at all. The recursion — the part that could not be a
 * window function — ports unchanged.
 *
 * One deliberate difference from the Gross Margin port: the account filter is
 * NOT moved out of the scan here, because there is no account filter. Overdraft
 * reads the whole cash position, so there is nothing to push down and nothing
 * for the columnar engine to prune. Measured on the lake, clustering by account
 * was worth 1.80x to Gross Margin and nothing at all to this report.
 */
final class OverdraftPgSqlBuilder
{
    private const int FIXED_POINT = 10000;

    public function build(): string
    {
        $fp = self::FIXED_POINT;

        return <<<SQL
            WITH RECURSIVE months AS (
                SELECT
                    row_number() OVER (ORDER BY m.month_start)::INT AS n,
                    m.month_start::DATE AS month_start,
                    to_char(m.month_start, 'YYYY-MM') AS month,
                    EXTRACT(MONTH FROM m.month_start)::INT AS month_of_year
                FROM generate_series(
                    date_trunc('month', CAST(:period_from AS DATE)),
                    date_trunc('month', CAST(:period_to AS DATE)),
                    INTERVAL '1 month'
                ) AS m(month_start)
            ),

            -- Grouped on the raw date column, then rolled up to months in the
            -- next step. Grouping by date_trunc() here would be a function call
            -- on the grouping key, which stops the columnar engine evaluating
            -- the aggregate inside the scan — the single sharpest result in
            -- this PoC, 40,687 ms against 2,740 ms on the 500M farm.
            by_day AS MATERIALIZED (
                SELECT tl.date, SUM(-tl.amount) AS net
                FROM transaction_lines tl
                WHERE tl.farm_id = :farm_id
                  AND tl.basis = 'cash'
                  AND tl.date BETWEEN CAST(:period_from2 AS DATE) AND CAST(:period_to2 AS DATE)
                  AND (
                        (tl.date <= CAST(:horizon AS DATE) AND tl.type = 'actuals')
                     OR (tl.date >  CAST(:horizon2 AS DATE) AND tl.type = 'forecast')
                  )
                GROUP BY 1
            ),

            movement AS (
                SELECT date_trunc('month', d.date)::DATE AS month_start, SUM(d.net) AS net
                FROM by_day d
                GROUP BY 1
            ),

            closing AS (
                SELECT
                    m.n,
                    m.month_start,
                    m.month,
                    m.month_of_year,
                    f.opening_balance AS farm_opening,
                    COALESCE(v.net, 0) AS movement,
                    f.opening_balance
                        + COALESCE(SUM(COALESCE(v.net, 0)) OVER (ORDER BY m.n
                              ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW), 0) AS closing
                FROM months m
                LEFT JOIN movement v ON v.month_start = m.month_start
                CROSS JOIN farms f
                WHERE f.farm_id = :farm_id2
            ),

            configured AS (
                SELECT
                    c.*,
                    o.rate::DOUBLE PRECISION / {$fp} / 100.0 / 12.0 AS monthly_rate,
                    o.payment_term,
                    EXTRACT(MONTH FROM o.start_date)::INT AS start_month
                FROM closing c
                LEFT JOIN LATERAL (
                    SELECT od.rate, od.payment_term, od.start_date
                    FROM overdrafts od
                    WHERE od.farm_id = :farm_id3
                      AND od.start_date <= (c.month_start + INTERVAL '1 month' - INTERVAL '1 day')::DATE
                    ORDER BY od.start_date DESC
                    LIMIT 1
                ) o ON TRUE
            ),

            accrual AS (
                SELECT
                    0 AS n,
                    CAST(0 AS DOUBLE PRECISION) AS cum,
                    CAST(0 AS DOUBLE PRECISION) AS accrued,
                    CAST(0 AS DOUBLE PRECISION) AS principal

                UNION ALL

                SELECT
                    g.n,
                    CASE
                        WHEN g.monthly_rate IS NULL THEN a.cum
                        WHEN (g.closing - a.cum) < 0
                            THEN a.cum + (-(g.closing - a.cum) * g.monthly_rate)
                        ELSE a.cum
                    END,
                    CASE
                        WHEN g.monthly_rate IS NULL THEN CAST(0 AS DOUBLE PRECISION)
                        WHEN (g.closing - a.cum) < 0
                            THEN -(g.closing - a.cum) * g.monthly_rate
                        ELSE CAST(0 AS DOUBLE PRECISION)
                    END,
                    g.closing - a.cum
                FROM accrual a
                JOIN configured g ON g.n = a.n + 1
            ),

            posting AS (
                SELECT
                    g.*,
                    x.principal,
                    trunc(x.accrued)::BIGINT AS accrued_posted,
                    CASE g.payment_term
                        WHEN 'interest_only_bi_monthly'    THEN 2
                        WHEN 'interest_only_quarterly'     THEN 3
                        WHEN 'interest_only_semi_annually' THEN 6
                        WHEN 'interest_only_annually'      THEN 12
                        ELSE 1
                    END AS step
                FROM configured g
                JOIN accrual x ON x.n = g.n
            ),

            bucketed AS (
                SELECT
                    p.*,
                    -- Figured's calendar is `(start_month + i - 1) mod 12` for
                    -- i = step, 2*step, ..., 12. Inverted: month M posts when
                    -- `(M - start_month + 1) mod 12`, reading 0 as 12, is a
                    -- whole number of steps. The +1 is easy to lose and gives
                    -- four evenly spaced months that are all wrong.
                    CASE
                        WHEN ((p.month_of_year - p.start_month + 1) % 12 + 12) % 12 = 0
                            THEN 12
                        ELSE ((p.month_of_year - p.start_month + 1) % 12 + 12) % 12
                    END % p.step = 0 AS is_posting_month
                FROM posting p
            ),

            distributed AS (
                SELECT
                    b.*,
                    SUM(b.accrued_posted) OVER (
                        PARTITION BY b.bucket ORDER BY b.n
                        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                    ) AS bucket_total
                FROM (
                    SELECT
                        b.*,
                        -- COALESCE is load-bearing: the frame excludes the
                        -- current row, so the first month covers no rows and
                        -- SUM returns NULL. A NULL bucket partitions alone and
                        -- month one's accrual never reaches its posting month.
                        COALESCE(SUM(CASE WHEN b.is_posting_month THEN 1 ELSE 0 END) OVER (
                            ORDER BY b.n ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
                        ), 0) AS bucket
                    FROM bucketed b
                ) b
            ),

            settled AS (
                SELECT
                    d.*,
                    CASE WHEN d.is_posting_month THEN d.bucket_total ELSE 0 END AS posted_amount
                FROM distributed d
            )

            SELECT
                s.n AS interval_index,
                s.month,
                (s.farm_opening
                    + COALESCE(SUM(s.movement - s.posted_amount) OVER (
                          ORDER BY s.n ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING), 0))
                    / {$fp}.0 AS opening_balance,
                s.movement / {$fp}.0 AS net_cash_movement,
                s.closing / {$fp}.0 AS closing_before_interest,
                s.principal / {$fp}.0 AS principal,
                s.accrued_posted / {$fp}.0 AS interest_accrued,
                CASE WHEN s.is_posting_month THEN s.bucket_total / {$fp}.0 END AS interest_posted,
                (SUM(s.accrued_posted) OVER (ORDER BY s.n ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
                 - SUM(s.posted_amount) OVER (ORDER BY s.n ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW))
                    / {$fp}.0 AS accrued_not_charged,
                (s.farm_opening
                    + SUM(s.movement - s.posted_amount) OVER (
                          ORDER BY s.n ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW))
                    / {$fp}.0 AS closing_balance,
                s.payment_term
            FROM settled s
            ORDER BY s.n
            SQL;
    }

    /**
     * The journal lines behind the closing balance.
     */
    public function buildSourceRowsSql(): string
    {
        $fp = self::FIXED_POINT;

        return <<<SQL
            SELECT
                tl.line_id, tl.date, tl.type, a.account_name, a.account_class,
                tl.amount / {$fp}.0 AS amount_dollars,
                -tl.amount / {$fp}.0 AS cash_effect
            FROM transaction_lines tl
            JOIN accounts a ON a.account_id = tl.account_id
            WHERE tl.farm_id = :farm_id
              AND tl.basis = 'cash'
              AND tl.date BETWEEN CAST(:period_from AS DATE) AND CAST(:period_to AS DATE)
              AND (
                    (tl.date <= CAST(:horizon AS DATE) AND tl.type = 'actuals')
                 OR (tl.date >  CAST(:horizon2 AS DATE) AND tl.type = 'forecast')
              )
            ORDER BY tl.date
            LIMIT :row_limit
            SQL;
    }

    public function buildSourceSummarySql(): string
    {
        $fp = self::FIXED_POINT;

        return <<<SQL
            SELECT
                count(*) AS n,
                count(DISTINCT tl.account_id) AS n_accounts,
                min(tl.date) AS first_date,
                max(tl.date) AS last_date,
                SUM(-tl.amount) / {$fp}.0 AS net_cash
            FROM transaction_lines tl
            WHERE tl.farm_id = :farm_id
              AND tl.basis = 'cash'
              AND tl.date BETWEEN CAST(:period_from AS DATE) AND CAST(:period_to AS DATE)
              AND (
                    (tl.date <= CAST(:horizon AS DATE) AND tl.type = 'actuals')
                 OR (tl.date >  CAST(:horizon2 AS DATE) AND tl.type = 'forecast')
              )
            SQL;
    }
}
