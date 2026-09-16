<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

/**
 * Overdraft interest as one DuckDB statement — Phase 3.
 *
 * Figured computes this in four PHP steps: build a cash-flow sub-report, walk
 * its months accruing interest, walk them again bucketing accruals into
 * repayment months, then emit virtual journals. The claim under test is that
 * all of it is one query.
 *
 * **This is the first thing in the PoC that a window function cannot express.**
 * Every other report here is a projection or a prefix sum. Interest is a
 * genuine recurrence: the balance it charges against excludes interest, so the
 * running total of interest already accrued has to be subtracted to find the
 * true overdrawn position —
 *
 *     P(n) = closing(n) - cum(n-1)
 *     cum(n) = cum(n-1) + (P(n) < 0 ? -P(n) * rate : 0)
 *
 * — and the branch depends on `cum` itself, so month N's interest changes
 * month N+1's charge base. `WITH RECURSIVE` is the only way to write it.
 *
 * Verified against Figured's own oracle (`OverdraftTest::overdraftDataProvider`,
 * `OverdraftInterestTest::testBudgetInterestAnnually`): a constant -$1,000
 * closing balance at 5% over twelve months posts
 * 41666, 41840, 42014, 42189, 42365, 42541, 42719, 42897, 43075, 43255, 43435,
 * 43616 — matched exactly.
 *
 * DOUBLE is enough, which was not obvious. Figured's arithmetic is bcmath at
 * scale 14, truncating; DuckDB's doubles diverge from it around the tenth
 * significant digit. It does not matter because only the POSTED amount is
 * truncated to an integer and the divergence is far below one unit. The
 * compounding base keeps full precision on both sides.
 */
final class OverdraftSqlBuilder
{
    private const int FIXED_POINT = 10000;

    public function __construct(
        private readonly string $alias,
        private readonly string $appAlias,
    ) {
    }

    /**
     * The journal lines behind the closing balance.
     *
     * Same scope predicate as the `movement` CTE, deliberately duplicated
     * rather than shared: if the two ever drift, the listing stops explaining
     * the number above it, and a silently wrong explanation is worse than none.
     * The `cash_effect` column shows the negation the movement applies, because
     * "why is this expense reducing the balance" is the first question anyone
     * asks of this table.
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
                tl.amount / {$fp}.0 AS amount_dollars,
                -tl.amount / {$fp}.0 AS cash_effect
            FROM {$this->alias}.transaction_lines tl
            JOIN {$this->appAlias}.accounts a ON a.account_id = tl.account_id
            WHERE tl.farm_id = \$farm_id
              AND tl.basis = 'cash'
              AND tl.date BETWEEN CAST(\$period_from AS DATE) AND CAST(\$period_to AS DATE)
              AND (
                    (tl.date <= CAST(\$horizon AS DATE) AND tl.type = 'actuals')
                 OR (tl.date >  CAST(\$horizon AS DATE) AND tl.type = 'forecast')
              )
            ORDER BY tl.date
            LIMIT \$row_limit
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
            FROM {$this->alias}.transaction_lines tl
            JOIN {$this->appAlias}.accounts a ON a.account_id = tl.account_id
            WHERE tl.farm_id = \$farm_id
              AND tl.basis = 'cash'
              AND tl.date BETWEEN CAST(\$period_from AS DATE) AND CAST(\$period_to AS DATE)
              AND (
                    (tl.date <= CAST(\$horizon AS DATE) AND tl.type = 'actuals')
                 OR (tl.date >  CAST(\$horizon AS DATE) AND tl.type = 'forecast')
              )
            SQL;
    }

    public function build(): string
    {
        $fp = self::FIXED_POINT;

        return <<<SQL
            WITH RECURSIVE months AS (
                SELECT
                    row_number() OVER (ORDER BY m.month_start) AS n,
                    m.month_start::DATE AS month_start,
                    strftime(m.month_start, '%Y-%m') AS month,
                    month(m.month_start) AS month_of_year
                FROM generate_series(
                    date_trunc('month', CAST(\$period_from AS DATE)),
                    date_trunc('month', CAST(\$period_to AS DATE)),
                    INTERVAL 1 MONTH
                ) AS m(month_start)
            ),

            -- The cash position, computed the way Figured's sub-report computes
            -- it: CASH basis regardless of what the outer report is showing,
            -- and the farm's opening bank balance plus cumulative net movement.
            -- Interest is deliberately absent — that is what makes the
            -- recurrence below necessary rather than a simple running total.
            movement AS (
                SELECT
                    date_trunc('month', tl.date)::DATE AS month_start,
                    -- Negated once, for every account class. Revenue is stored
                    -- as a credit (negative) and expense as a debit (positive),
                    -- so -amount gives +income and -expense — cash movement —
                    -- in one expression. Branching on account_class here reads
                    -- like the report's sign rules but is wrong: it produces
                    -- income PLUS expense, and the balance then drifts positive
                    -- on a farm that is actually overdrawn, which is exactly
                    -- how this was caught.
                    --
                    -- Known gap: GST is treated as an ordinary account. The
                    -- Cash Flow report inverts the whole GST section
                    -- (SignRule::InvertWholeSection) and this does not, so a
                    -- farm with GST lines will disagree with it. The oracle
                    -- carries none, so it is not exercised either way.
                    SUM(-tl.amount) AS net
                FROM {$this->alias}.transaction_lines tl
                JOIN {$this->appAlias}.accounts a ON a.account_id = tl.account_id
                WHERE tl.farm_id = \$farm_id
                  AND tl.basis = 'cash'
                  AND tl.date BETWEEN CAST(\$period_from AS DATE) AND CAST(\$period_to AS DATE)
                  AND (
                        (tl.date <= CAST(\$horizon AS DATE) AND tl.type = 'actuals')
                     OR (tl.date >  CAST(\$horizon AS DATE) AND tl.type = 'forecast')
                  )
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
                CROSS JOIN {$this->appAlias}.farms f
                WHERE f.farm_id = \$farm_id
            ),

            -- The config in force for a month is the one with the latest
            -- start_date on or before that month's end. Several rows are a time
            -- series of settings, never concurrent facilities, so this is a
            -- pick-one rather than an aggregate.
            configured AS (
                SELECT
                    c.*,
                    o.rate / {$fp}.0 / 100.0 / 12.0 AS monthly_rate,
                    o.payment_term,
                    month(o.start_date) AS start_month
                FROM closing c
                LEFT JOIN LATERAL (
                    SELECT od.rate, od.payment_term, od.start_date
                    FROM {$this->appAlias}.overdrafts od
                    WHERE od.farm_id = \$farm_id
                      AND od.start_date <= (c.month_start + INTERVAL 1 MONTH - INTERVAL 1 DAY)::DATE
                    ORDER BY od.start_date DESC
                    LIMIT 1
                ) o ON TRUE
            ),

            -- The recurrence. `cum` carries FULL precision; only the posted
            -- amount is truncated, exactly as Figured does — truncating the
            -- accumulator instead would compound the rounding error.
            accrual AS (
                SELECT
                    0 AS n,
                    CAST(0 AS DOUBLE) AS cum,
                    CAST(0 AS DOUBLE) AS accrued,
                    CAST(0 AS DOUBLE) AS principal

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
                        WHEN g.monthly_rate IS NULL THEN CAST(0 AS DOUBLE)
                        WHEN (g.closing - a.cum) < 0
                            THEN -(g.closing - a.cum) * g.monthly_rate
                        ELSE CAST(0 AS DOUBLE)
                    END,
                    -- The sum the charge is actually computed on, carried out
                    -- so the report can show it. The closing balance alone
                    -- explains nothing: on a farm whose cash position never
                    -- moves it stays flat while the charge climbs, and the
                    -- link between the two is exactly this subtraction.
                    g.closing - a.cum
                FROM accrual a
                JOIN configured g ON g.n = a.n + 1
            ),

            -- Which months the accrual is actually POSTED in. The calendar
            -- counts forward from the overdraft's own start month in steps of
            -- the term, so a quarterly overdraft starting in April posts in
            -- June, September, December and March — the start month itself is
            -- never a posting month.
            posting AS (
                SELECT
                    g.*,
                    x.principal,
                    CAST(trunc(x.accrued) AS BIGINT) AS accrued_posted,
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
                    -- Figured builds the posting calendar as
                    -- `(start_month + i - 1) mod 12` for i = step, 2*step, ...,
                    -- 12, mapping 0 to December. Inverted, month M posts when
                    -- `(M - start_month + 1) mod 12` — with 0 read as 12 — is a
                    -- whole number of steps.
                    --
                    -- The +1 is not cosmetic and is easy to lose: a quarterly
                    -- overdraft starting in April posts in June, September,
                    -- December and March, NOT July. Dropping it shifts every
                    -- posting month by one and the totals still look plausible.
                    CASE
                        WHEN ((p.month_of_year - p.start_month + 1) % 12 + 12) % 12 = 0
                            THEN 12
                        ELSE ((p.month_of_year - p.start_month + 1) % 12 + 12) % 12
                    END % p.step = 0 AS is_posting_month
                FROM posting p
            ),

            -- Accrued-but-unposted interest rides forward to the next posting
            -- month and is charged as one lump. Summing within the bucket is
            -- equivalent to Figured's carried accumulator, and unlike the
            -- accrual it needs no recursion — the bucket boundaries are known.
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
                        -- COALESCE is load-bearing. The frame excludes the
                        -- current row, so for the first month it covers no rows
                        -- at all and SUM returns NULL rather than 0. A NULL
                        -- bucket partitions on its own, and month one's accrual
                        -- then never reaches the posting month that should
                        -- carry it — silently, because every other month still
                        -- looks right. Caught by asserting that the posted
                        -- amounts sum to the accrued amounts.
                        COALESCE(SUM(CASE WHEN b.is_posting_month THEN 1 ELSE 0 END) OVER (
                            ORDER BY b.n ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
                        ), 0) AS bucket
                    FROM bucketed b
                ) b
            ),

            -- The interest charged back onto the balance, which is what
            -- Figured's virtual journals do: the posted amount is a cash
            -- outflow, so it moves the closing balance and therefore the next
            -- month's opening. Only POSTED amounts land — accrued-but-unposted
            -- interest is a liability the farmer has not paid yet.
            --
            -- A plain window suffices here even though the accrual needed
            -- recursion: once `posted` exists per month it is a prefix sum, and
            -- nothing downstream feeds back into it.
            settled AS (
                SELECT
                    d.*,
                    CASE WHEN d.is_posting_month THEN d.bucket_total ELSE 0 END AS posted_amount
                FROM distributed d
            )

            SELECT
                s.n AS interval_index,
                s.month,
                s.farm_opening
                    + COALESCE(SUM(s.movement - s.posted_amount) OVER (
                          ORDER BY s.n ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING), 0)
                    / {$fp}.0 AS opening_balance,
                s.movement / {$fp}.0 AS net_cash_movement,
                s.closing / {$fp}.0 AS closing_before_interest,
                s.principal / {$fp}.0 AS principal,
                s.accrued_posted / {$fp}.0 AS interest_accrued,
                CASE WHEN s.is_posting_month THEN s.bucket_total / {$fp}.0 END AS interest_posted,
                (s.movement - s.posted_amount) / {$fp}.0 AS net_cash_movement_after_interest,
                (s.farm_opening
                    + SUM(s.movement - s.posted_amount) OVER (
                          ORDER BY s.n ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW))
                    / {$fp}.0 AS closing_balance,
                s.payment_term
            FROM settled s
            ORDER BY s.n
            SQL;
    }
}
