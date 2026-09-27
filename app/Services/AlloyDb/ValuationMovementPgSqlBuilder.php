<?php

declare(strict_types=1);

namespace App\Services\AlloyDb;

/**
 * The livestock valuation movement on AlloyDB — the portability half of Phase 2.
 *
 * This class exists to answer one question: **is the valuation logic portable,
 * or is it DuckDB's?** Phase 2's latency result was a wash — the statement is
 * not faster than the PHP it replaces once the data has to be reached through
 * a connector — so the case for moving the logic into SQL rests on it being
 * *one definition usable from several engines*: the app, BigQuery for the data
 * science team, and whatever the warehouse becomes.
 *
 * That claim is only worth anything if the thing that ports is the part that
 * encodes the business rule. It is. Compared against
 * `CashFlow\ValuationMovementSqlBuilder`, the two window clauses that ARE the
 * valuation —
 *
 *     SUM(...) OVER (PARTITION BY tracker_id ORDER BY month
 *                    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
 *     LAG(closing_value) OVER (PARTITION BY tracker_id ORDER BY month_start)
 *
 * — are byte-identical between the two files. Everything that differs is
 * scaffolding:
 *
 * | concern | DuckDB | Postgres / AlloyDB | BigQuery |
 * |---|---|---|---|
 * | month spine | `generate_series(a, b, INTERVAL 1 MONTH) AS g(m)` | `generate_series(a, b, INTERVAL '1 month')` | `UNNEST(GENERATE_DATE_ARRAY(a, b, INTERVAL 1 MONTH))` |
 * | month label | `strftime(d, '%Y-%m')` | `to_char(d, 'YYYY-MM')` | `FORMAT_DATE('%Y-%m', d)` |
 * | integer division | `/ 10000.0` | `/ 10000.0` | `/ 10000.0` |
 *
 * A date spine and a display format. Neither carries a business rule, and both
 * are the kind of thing a thin dialect shim or dbt macro handles. The risk
 * worth naming is not expressibility, it is **drift**: three hand-maintained
 * copies of a rule will diverge, so if this becomes real the SQL should be
 * generated from one source rather than copied. This class being a near-clone
 * of its DuckDB counterpart is itself the evidence for that.
 *
 * No pushdown predicate here, unlike the DuckDB builder: AlloyDB owns its own
 * tables, so there is no connector to push anything through.
 */
final class ValuationMovementPgSqlBuilder
{
    private const int FIXED_POINT = 10000;

    public function buildSql(): string
    {
        $fp = self::FIXED_POINT;

        return <<<SQL
            WITH months AS (
                SELECT
                    m::DATE AS month_start,
                    row_number() OVER (ORDER BY m) AS interval_index
                FROM generate_series(
                    CAST(:period_from AS DATE),
                    CAST(:period_to AS DATE),
                    INTERVAL '1 month'
                ) AS m
            ),

            stock_running AS (
                SELECT
                    mv.tracker_id,
                    mv.month AS month_start,
                    t.opening_stock
                        + SUM(mv.purchases + mv.births - mv.sales - mv.deaths)
                          OVER (PARTITION BY mv.tracker_id ORDER BY mv.month
                                ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS closing_head
                FROM tracker_stock_movements mv
                JOIN trackers t ON t.tracker_id = mv.tracker_id
                WHERE t.farm_id = :farm_id
                  AND (
                        (mv.month <= CAST(:horizon AS DATE) AND mv.type = 'actuals')
                     OR (mv.month >  CAST(:horizon AS DATE) AND mv.type = 'forecast')
                  )
            ),

            valued AS (
                SELECT
                    sr.tracker_id,
                    sr.month_start,
                    sr.closing_head,
                    vr.per_head_value,
                    sr.closing_head * vr.per_head_value AS closing_value
                FROM stock_running sr
                JOIN valuation_rates vr
                  ON vr.tracker_id = sr.tracker_id AND vr.month = sr.month_start
            ),

            moved AS (
                SELECT
                    tracker_id,
                    month_start,
                    closing_head,
                    per_head_value,
                    closing_value,
                    closing_value - LAG(closing_value) OVER (
                        PARTITION BY tracker_id ORDER BY month_start
                    ) AS movement
                FROM valued
            )

            SELECT
                m.interval_index,
                to_char(m.month_start, 'YYYY-MM') AS month,
                t.tracker_id,
                t.tracker_name,
                t.stock_type,
                mo.closing_head,
                mo.per_head_value / {$fp}.0 AS per_head_dollars,
                mo.closing_value / {$fp}.0 AS closing_value_dollars,
                COALESCE(mo.movement, 0) / {$fp}.0 AS movement_dollars
            FROM months m
            JOIN moved mo ON mo.month_start = m.month_start
            JOIN trackers t ON t.tracker_id = mo.tracker_id
            ORDER BY m.interval_index, t.display_order
            SQL;
    }
}
