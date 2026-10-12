<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;

/**
 * One farm's livestock at a month end, plan against current: head by class
 * and its value at the season's rate per head, from the same rule the profit
 * and loss values livestock by, with totals for each mob (tracker) and the
 * farm.
 */
final class HerdValuationSqlBuilder
{
    private const int FIXED_POINT = 10000;

    /** The shared CTEs a valuation needs: livestock movements to head to value, nothing from the lake. */
    private const array VALUATION_CTES = ['farm', 'report_window', 'stock_net', 'stock_heads', 'stock_class_values'];

    public function __construct(
        private readonly int $farmId,
        private readonly ?int $trackerId = null,
    ) {
    }

    public function build(): string
    {
        $plan = $this->values(PlanComparisonSqlBuilder::PLAN_HORIZON);
        $current = $this->values(PlanComparisonSqlBuilder::CURRENT_HORIZON);
        $trackers = InsightsDuckDb::table('trackers');
        $types = InsightsDuckDb::table('stock_types');
        $classes = InsightsDuckDb::table('stock_classes');
        $tracker = $this->trackerId === null ? 'true' : "v.tracker_id = {$this->trackerId}";
        $fp = self::FIXED_POINT;

        return <<<SQL
            WITH
            plan_values AS (
            {$plan}
            ),
            current_values AS (
            {$current}
            ),
            at_month_end AS (
                SELECT COALESCE(c.tracker_id, p.tracker_id) AS tracker_id,
                       COALESCE(c.stock_class_uuid, p.stock_class_uuid) AS stock_class_uuid,
                       COALESCE(p.head, 0) AS plan_head, COALESCE(c.head, 0) AS current_head,
                       COALESCE(c.value_per_head, p.value_per_head) AS value_per_head,
                       COALESCE(p.value, 0) AS plan_value, COALESCE(c.value, 0) AS current_value
                FROM plan_values p
                FULL JOIN current_values c
                  ON c.tracker_id = p.tracker_id AND c.stock_class_uuid = p.stock_class_uuid AND c.ms = p.ms
                WHERE COALESCE(c.ms, p.ms) = CAST(date_trunc('month', CAST(\$as_at AS DATE)) AS DATE)
            )
            SELECT v.tracker_id,
                   CASE WHEN GROUPING(v.tracker_id) = 0 THEN any_value(tr.name) END AS tracker,
                   CASE WHEN GROUPING(v.tracker_id) = 0 THEN any_value(st.name) END AS stock_type,
                   CASE WHEN GROUPING(sc.name) = 0 THEN sc.name END AS stock_class,
                   CASE WHEN GROUPING(v.tracker_id) = 1 THEN 'farm' WHEN GROUPING(sc.name) = 1 THEN 'tracker' ELSE 'class' END AS level,
                   SUM(v.plan_head) AS plan_head, SUM(v.current_head) AS current_head,
                   SUM(v.current_head) - SUM(v.plan_head) AS head_variance,
                   CASE WHEN GROUPING(sc.name) = 0 THEN round(CAST(any_value(v.value_per_head) AS DOUBLE) / {$fp}, 2) END AS value_per_head,
                   round(CAST(SUM(v.plan_value) AS DOUBLE) / {$fp}, 2) AS plan_value,
                   round(CAST(SUM(v.current_value) AS DOUBLE) / {$fp}, 2) AS current_value,
                   round(CAST(SUM(v.current_value) - SUM(v.plan_value) AS DOUBLE) / {$fp}, 2) AS value_variance
            FROM at_month_end v
            JOIN {$trackers} tr ON tr.id = v.tracker_id AND tr._valid_to IS NULL
            JOIN {$types} st ON st.uuid = tr.stock_type_uuid
            JOIN {$classes} sc ON sc.uuid = v.stock_class_uuid
            WHERE tr.farm_id = {$this->farmId} AND {$tracker}
            GROUP BY GROUPING SETS ((v.tracker_id, sc.name), (v.tracker_id), ())
            ORDER BY v.tracker_id NULLS FIRST, GROUPING(sc.name) DESC, sc.name
            SQL;
    }

    private function values(string $horizonParameter): string
    {
        $ctes = (new ReportLinesSqlBuilder([$this->farmId], ReportBasis::Accrual, $horizonParameter))
            ->ctes(ReportLinesSqlBuilder::periodWindow());

        return ReportLinesSqlBuilder::statement(
            array_intersect_key($ctes, array_flip(self::VALUATION_CTES)),
            'SELECT tracker_id, stock_class_uuid, ms, head, value_per_head, value FROM stock_class_values',
        );
    }
}
