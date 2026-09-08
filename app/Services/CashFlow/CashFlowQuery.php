<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Saturio\DuckDB\DuckDB;

/**
 * Figured's Cash Flow report, expressed as a single DuckDB query.
 *
 * This is the Phase 1 translation target: the same calculation chain
 * `CashFlowStructureBuilder` + `ReportRunnerService` perform in PHP, done
 * instead as SQL over DuckLake. Its output is diffed against the parity
 * oracle documented in this project's README.
 *
 * The chain being reproduced, from CashFlowStructureBuilder's own
 * ReportCalculationRow definitions:
 *
 *     gross_profit      = other_income - direct_costs
 *     operating_surplus = gross_profit - operating_expenses
 *     total_surplus     = operating_surplus + non_operating_income
 *                                           - non_operating_expenses
 *     net_cash_movement = total_surplus + non_operating_movements
 *                                       + equity_movements + gst
 *     closing           = opening + net_cash_movement
 *     opening[n]        = closing[n-1]   (seeded from farms.opening_balance)
 *
 * Two things this query has to get right that a naive aggregation would not:
 *
 * 1. The month spine. Months with no transactions still need a row, because
 *    the running balance carries through them — LEFT JOINing transactions
 *    onto a generated series of months is what produces that, rather than
 *    only emitting months where data happens to exist.
 * 2. The horizon split. For an ACTUALS_FORECAST period, a month before the
 *    horizon reads `actuals` rows and a month after it reads `forecast` rows.
 *    The boundary is a query parameter, not a column, so it cannot be a
 *    plain equality filter on `type`.
 */
final class CashFlowQuery
{
    /** Matches Figured's TEN_THOUSAND fixed-point convention. */
    private const int FIXED_POINT = 10000;

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
    ) {
    }

    /**
     * @return list<array<string, mixed>> one row per month, in period order
     */
    public function run(
        string $farmId,
        string $farmType,
        string $region,
        string $periodFrom,
        string $periodTo,
        string $horizon,
        string $basis = 'cash',
    ): array {
        return iterator_to_array(
            $this->db->query($this->sql($farmId, $farmType, $region, $periodFrom, $periodTo, $horizon, $basis))
                ->rows(true)
        );
    }

    public function sql(
        string $farmId,
        string $farmType,
        string $region,
        string $periodFrom,
        string $periodTo,
        string $horizon,
        string $basis = 'cash',
    ): string {
        $fp = self::FIXED_POINT;

        // The last month's start date — generate_series is inclusive of its
        // end bound, so it has to be the first of the final month, not the
        // period's end date.
        $lastMonthStart = date('Y-m-01', strtotime($periodTo));

        $farmId = $this->escape($farmId);
        $farmType = $this->escape($farmType);
        $region = $this->escape($region);
        $basis = $this->escape($basis);

        return <<<SQL
            WITH months AS (
                SELECT
                    m.month_start::DATE AS month_start,
                    (m.month_start + INTERVAL 1 MONTH - INTERVAL 1 DAY)::DATE AS month_end,
                    row_number() OVER (ORDER BY m.month_start) AS interval_index
                FROM generate_series(
                    DATE '{$periodFrom}',
                    DATE '{$lastMonthStart}',
                    INTERVAL 1 MONTH
                ) AS m(month_start)
            ),
            report_lines AS (
                SELECT
                    tl.date,
                    a.account_class,
                    a.account_category,
                    tl.amount
                FROM {$this->alias}.transaction_lines tl
                JOIN {$this->alias}.accounts a ON a.account_id = tl.account_id
                WHERE tl.farm_id = '{$farmId}'
                  -- farm_type/region are the partition keys: naming them lets
                  -- DuckDB prune to this cohort's Parquet files rather than
                  -- scanning every partition.
                  AND tl.farm_type = '{$farmType}'
                  AND tl.region = '{$region}'
                  AND tl.basis = '{$basis}'
                  AND tl.date BETWEEN DATE '{$periodFrom}' AND DATE '{$periodTo}'
                  AND (
                        (tl.date <= DATE '{$horizon}' AND tl.type = 'actuals')
                     OR (tl.date >  DATE '{$horizon}' AND tl.type = 'forecast')
                  )
            ),
            sections AS (
                SELECT
                    m.interval_index,
                    strftime(m.month_start, '%Y-%m') AS month,
                    -- REVENUE-class accounts are stored as credits (negative)
                    -- and shown positive, mirroring
                    -- XeroAccount::isAccountInversedForUser(), which returns
                    -- true for REVENUE and only REVENUE.
                    COALESCE(SUM(
                        CASE WHEN l.account_category = 'other_income'
                             THEN CASE WHEN l.account_class = 'REVENUE'
                                       THEN -l.amount ELSE l.amount END
                        END
                    ), 0) / {$fp}.0 AS other_income,
                    COALESCE(SUM(
                        CASE WHEN l.account_category = 'direct_costs'
                             THEN l.amount END
                    ), 0) / {$fp}.0 AS direct_costs,
                    COALESCE(SUM(
                        CASE WHEN l.account_category = 'operating_expenses'
                             THEN l.amount END
                    ), 0) / {$fp}.0 AS operating_expenses,
                    COALESCE(SUM(
                        CASE WHEN l.account_category = 'non_operating_income'
                             THEN CASE WHEN l.account_class = 'REVENUE'
                                       THEN -l.amount ELSE l.amount END
                        END
                    ), 0) / {$fp}.0 AS non_operating_income,
                    COALESCE(SUM(
                        CASE WHEN l.account_category = 'non_operating_expenses'
                             THEN l.amount END
                    ), 0) / {$fp}.0 AS non_operating_expenses,
                    -- These three sections are declared setInverse(true) on
                    -- the real structure, so their displayed value is the
                    -- negation of the stored sum.
                    COALESCE(SUM(
                        CASE WHEN l.account_category = 'non_operating_movements'
                             THEN -l.amount END
                    ), 0) / {$fp}.0 AS non_operating_movements,
                    COALESCE(SUM(
                        CASE WHEN l.account_category = 'equity_movements'
                             THEN -l.amount END
                    ), 0) / {$fp}.0 AS equity_movements,
                    COALESCE(SUM(
                        CASE WHEN l.account_category = 'gst'
                             THEN -l.amount END
                    ), 0) / {$fp}.0 AS gst
                FROM months m
                LEFT JOIN report_lines l
                       ON l.date BETWEEN m.month_start AND m.month_end
                GROUP BY m.interval_index, m.month_start
            ),
            calculated AS (
                SELECT
                    s.*,
                    s.other_income - s.direct_costs AS gross_profit,
                    (s.other_income - s.direct_costs) - s.operating_expenses AS operating_surplus,
                    ((s.other_income - s.direct_costs) - s.operating_expenses)
                        + s.non_operating_income - s.non_operating_expenses AS total_surplus,
                    ((s.other_income - s.direct_costs) - s.operating_expenses)
                        + s.non_operating_income - s.non_operating_expenses
                        + s.non_operating_movements + s.equity_movements + s.gst AS net_cash_movement
                FROM sections s
            )
            SELECT
                c.interval_index,
                c.month,
                c.other_income AS income,
                c.direct_costs,
                c.gross_profit,
                c.operating_expenses,
                c.operating_surplus,
                c.total_surplus,
                c.net_cash_movement,
                -- opening is the prior month's closing: the running total of
                -- every movement STRICTLY BEFORE this row, on top of the
                -- farm's configured opening balance.
                f.opening_balance / {$fp}.0
                    + COALESCE(SUM(c.net_cash_movement) OVER (
                          ORDER BY c.interval_index
                          ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
                      ), 0) AS opening,
                f.opening_balance / {$fp}.0
                    + SUM(c.net_cash_movement) OVER (
                          ORDER BY c.interval_index
                          ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                      ) AS closing
            FROM calculated c
            CROSS JOIN {$this->alias}.farms f
            WHERE f.farm_id = '{$farmId}'
            ORDER BY c.interval_index
            SQL;
    }

    private function escape(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
