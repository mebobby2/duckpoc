<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

/**
 * Livestock valuation movement as one DuckDB statement — Phase 2.
 *
 * Figured computes this in `NonCashMovementGenerator::getMovements()`: for each
 * valuation template, for each interval of the report period, construct a
 * management valuation object, allocate stock to it, call `calculate()`, read
 * `getTotalValue()`, and subtract the previous interval's value. The claim
 * under test is that all of it is one query.
 *
 * **This is not a recurrence.** The generator's inner line is
 *
 *     $movement = $intervalValue - $previousValue;
 *
 * which is `LAG`. Nothing feeds back into its own input — unlike Phase 3's
 * overdraft interest, where the accrued total changes the balance it is
 * charged against. Phase 2 is a prefix sum feeding a first difference, and
 * both halves are ordinary window functions. Two passes over one scan.
 *
 * So the win here is not expressibility, it is **PHP object churn**. The
 * measured production shape is 557 valuation templates x 84 intervals — about
 * 47,000 `calculate()` cycles — over a database whose largest single farm holds
 * 5,039 stock rows. The cost is O(intervals x templates) and almost independent
 * of row count, which is why this phase is a latency test rather than a volume
 * one. See the README's Phase 2 entry for the measurement that forced that
 * rescope.
 *
 * Three things the port keeps faithfully:
 *
 * - **Opening stock is history, not a column.** Closing head for a month is
 *   every prior movement accumulated from `trackers.opening_stock`, so the
 *   scan is deliberately unfiltered by date and the period filter is applied
 *   when joining the spine. A report covering 2021 still reads 1996-2020.
 * - **The horizon split.** Actuals up to the horizon date, forecast beyond it,
 *   in one scan — Figured's `addSplitDateRangeToQuery`. Without it a month
 *   holding both row types double-counts.
 * - **The first month has no predecessor.** `LAG` returns NULL there, and the
 *   opening valuation has to come from the position *before* the period, not
 *   from zero — otherwise month one books the entire herd value as a movement.
 *   This is the same NULL-bucket trap Phase 3 hit; it is caught here by the
 *   conservation check in the command, not by eyeballing a month.
 */
final class ValuationMovementSqlBuilder
{
    private const int FIXED_POINT = 10000;

    public function __construct(
        private readonly string $appAlias,
    ) {
    }

    /**
     * A redundant `IN` predicate on the scanned table, so the tracker filter
     * reaches MySQL.
     *
     * The farm filter naturally lives on `trackers`, and the DuckDB MySQL
     * scanner cannot push a predicate through a join: written the obvious way,
     * every farm's movements cross the wire and are discarded locally.
     * Measured on this PoC, the join-then-filter shape costs 17.4 ms against
     * 1.6 ms for the same filter applied directly — and the 17.4 ms does not
     * move when the farm has 50 trackers instead of 5, which is what gives it
     * away.
     *
     * So the ids are resolved first (a cheap dimension lookup) and pasted in
     * alongside the join rather than instead of it: the join still supplies
     * `opening_stock`, `tracker_name` and `display_order`.
     *
     * @param list<string> $trackerIds
     */
    public static function trackerPredicate(array $trackerIds, string $column): string
    {
        if ($trackerIds === []) {
            return '';
        }

        $quoted = implode(',', array_map(
            static fn (string $id): string => "'".str_replace("'", "''", $id)."'",
            $trackerIds,
        ));

        return "\n                  AND {$column} IN ({$quoted})";
    }

    /**
     * The whole report: closing head, closing valuation, and the movement.
     *
     * `stock_running` and `valued` are separate CTEs rather than one because
     * the running sum must be computed over the tracker's entire history while
     * the valuation join only needs the months that survive it. Folding them
     * together makes DuckDB carry the rate join across every historical row.
     */
    public function buildSql(array $trackerIds = []): string
    {
        $trackerFilter = self::trackerPredicate($trackerIds, 'mv.tracker_id');
        $rateFilter = self::trackerPredicate($trackerIds, 'tracker_id');
        $fp = self::FIXED_POINT;

        return <<<SQL
            WITH months AS (
                SELECT
                    CAST(m AS DATE) AS month_start,
                    row_number() OVER (ORDER BY m) AS interval_index
                FROM generate_series(
                    CAST(\$period_from AS DATE),
                    CAST(\$period_to AS DATE),
                    INTERVAL 1 MONTH
                ) AS g(m)
            ),

            -- Unfiltered by date on purpose: opening stock for the first month
            -- of the period is every movement before it, accumulated.
            stock_running AS (
                SELECT
                    mv.tracker_id,
                    mv.month AS month_start,
                    t.opening_stock
                        + SUM(mv.purchases + mv.births - mv.sales - mv.deaths)
                          OVER (PARTITION BY mv.tracker_id ORDER BY mv.month
                                ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS closing_head
                FROM {$this->appAlias}.tracker_stock_movements mv
                JOIN {$this->appAlias}.trackers t ON t.tracker_id = mv.tracker_id
                WHERE t.farm_id = \$farm_id{$trackerFilter}
                  AND (
                        (mv.month <= CAST(\$horizon AS DATE) AND mv.type = 'actuals')
                     OR (mv.month >  CAST(\$horizon AS DATE) AND mv.type = 'forecast')
                  )
            ),

            -- quantity x per-head value. That is the entire valuation maths for
            -- a management valuation; see the class docblock.
            valued AS (
                SELECT
                    sr.tracker_id,
                    sr.month_start,
                    sr.closing_head,
                    vr.per_head_value,
                    sr.closing_head * vr.per_head_value AS closing_value
                FROM stock_running sr
                JOIN (
                    SELECT tracker_id, month, per_head_value
                    FROM {$this->appAlias}.valuation_rates
                    WHERE 1 = 1{$rateFilter}
                ) vr ON vr.tracker_id = sr.tracker_id AND vr.month = sr.month_start
            ),

            -- The first difference, per tracker. Taken BEFORE the period filter
            -- so the period's first month differences against the month before
            -- it rather than against NULL.
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
                strftime(m.month_start, '%Y-%m') AS month,
                t.tracker_id,
                t.tracker_name,
                t.stock_type,
                mo.closing_head,
                mo.per_head_value / {$fp}.0 AS per_head_dollars,
                mo.closing_value / {$fp}.0 AS closing_value_dollars,
                COALESCE(mo.movement, 0) / {$fp}.0 AS movement_dollars
            FROM months m
            JOIN moved mo ON mo.month_start = m.month_start
            JOIN {$this->appAlias}.trackers t ON t.tracker_id = mo.tracker_id
            ORDER BY m.interval_index, t.display_order
            SQL;
    }

    /**
     * The farm total per month — what the virtual journal would actually post.
     *
     * Figured emits one journal line per tracker per interval against the
     * tracker's mapped movement account, so the farm total is the sum. Kept as
     * a separate statement rather than a rollup of the detail because the
     * report page shows them in different places and a GROUPING SETS result
     * would have to be un-interleaved in PHP.
     */
    public function buildTotalsSql(array $trackerIds = []): string
    {
        $trackerFilter = self::trackerPredicate($trackerIds, 'mv.tracker_id');
        $rateFilter = self::trackerPredicate($trackerIds, 'tracker_id');
        $fp = self::FIXED_POINT;

        return <<<SQL
            WITH months AS (
                SELECT
                    CAST(m AS DATE) AS month_start,
                    row_number() OVER (ORDER BY m) AS interval_index
                FROM generate_series(
                    CAST(\$period_from AS DATE),
                    CAST(\$period_to AS DATE),
                    INTERVAL 1 MONTH
                ) AS g(m)
            ),

            stock_running AS (
                SELECT
                    mv.tracker_id,
                    mv.month AS month_start,
                    t.opening_stock
                        + SUM(mv.purchases + mv.births - mv.sales - mv.deaths)
                          OVER (PARTITION BY mv.tracker_id ORDER BY mv.month
                                ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS closing_head
                FROM {$this->appAlias}.tracker_stock_movements mv
                JOIN {$this->appAlias}.trackers t ON t.tracker_id = mv.tracker_id
                WHERE t.farm_id = \$farm_id{$trackerFilter}
                  AND (
                        (mv.month <= CAST(\$horizon AS DATE) AND mv.type = 'actuals')
                     OR (mv.month >  CAST(\$horizon AS DATE) AND mv.type = 'forecast')
                  )
            ),

            valued AS (
                SELECT
                    sr.tracker_id,
                    sr.month_start,
                    sr.closing_head,
                    sr.closing_head * vr.per_head_value AS closing_value
                FROM stock_running sr
                JOIN (
                    SELECT tracker_id, month, per_head_value
                    FROM {$this->appAlias}.valuation_rates
                    WHERE 1 = 1{$rateFilter}
                ) vr ON vr.tracker_id = sr.tracker_id AND vr.month = sr.month_start
            ),

            moved AS (
                SELECT
                    tracker_id,
                    month_start,
                    closing_head,
                    closing_value,
                    closing_value - LAG(closing_value) OVER (
                        PARTITION BY tracker_id ORDER BY month_start
                    ) AS movement
                FROM valued
            )

            SELECT
                m.interval_index,
                strftime(m.month_start, '%Y-%m') AS month,
                SUM(mo.closing_head) AS closing_head,
                SUM(mo.closing_value) / {$fp}.0 AS closing_value_dollars,
                SUM(COALESCE(mo.movement, 0)) / {$fp}.0 AS movement_dollars
            FROM months m
            JOIN moved mo ON mo.month_start = m.month_start
            GROUP BY m.interval_index, m.month_start
            ORDER BY m.interval_index
            SQL;
    }

    /**
     * The stock movements behind one month's head count.
     *
     * Scope predicate duplicated from `stock_running` rather than shared, for
     * the same reason the overdraft builder duplicates its own: a listing that
     * drifts from the number above it is worse than no listing.
     */
    public function buildSourceRowsSql(array $trackerIds = []): string
    {
        $trackerFilter = self::trackerPredicate($trackerIds, 'mv.tracker_id');
        $rateFilter = self::trackerPredicate($trackerIds, 'tracker_id');
        return <<<SQL
            SELECT
                mv.tracker_id,
                t.tracker_name,
                mv.month,
                mv.type,
                mv.purchases,
                mv.births,
                mv.sales,
                mv.deaths,
                mv.purchases + mv.births - mv.sales - mv.deaths AS net_movement
            FROM {$this->appAlias}.tracker_stock_movements mv
            JOIN {$this->appAlias}.trackers t ON t.tracker_id = mv.tracker_id
            WHERE t.farm_id = \$farm_id{$trackerFilter}
              AND mv.month BETWEEN CAST(\$period_from AS DATE) AND CAST(\$period_to AS DATE)
              AND (
                    (mv.month <= CAST(\$horizon AS DATE) AND mv.type = 'actuals')
                 OR (mv.month >  CAST(\$horizon AS DATE) AND mv.type = 'forecast')
              )
            ORDER BY mv.month, t.display_order
            LIMIT \$row_limit
            SQL;
    }

    public function buildSourceSummarySql(array $trackerIds = []): string
    {
        $trackerFilter = self::trackerPredicate($trackerIds, 'mv.tracker_id');
        $rateFilter = self::trackerPredicate($trackerIds, 'tracker_id');
        return <<<SQL
            SELECT
                COUNT(*) AS movement_rows,
                COUNT(DISTINCT mv.tracker_id) AS trackers,
                SUM(mv.purchases) AS purchases,
                SUM(mv.births) AS births,
                SUM(mv.sales) AS sales,
                SUM(mv.deaths) AS deaths
            FROM {$this->appAlias}.tracker_stock_movements mv
            JOIN {$this->appAlias}.trackers t ON t.tracker_id = mv.tracker_id
            WHERE t.farm_id = \$farm_id{$trackerFilter}
              AND mv.month BETWEEN CAST(\$period_from AS DATE) AND CAST(\$period_to AS DATE)
              AND (
                    (mv.month <= CAST(\$horizon AS DATE) AND mv.type = 'actuals')
                 OR (mv.month >  CAST(\$horizon AS DATE) AND mv.type = 'forecast')
              )
            SQL;
    }
}
