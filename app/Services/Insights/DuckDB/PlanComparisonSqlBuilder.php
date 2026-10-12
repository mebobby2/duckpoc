<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;
use InvalidArgumentException;

/**
 * One farm's season, plan against actuals, in one statement: the single-farm
 * report (cash flow on cash basis, profit and loss on accrual) built twice
 * from the shared report SQL, once at the plan horizon and once at the
 * actuals horizon, and compared row by row.
 *
 * The plan is the report as it stood when the forecast was agreed. The
 * current side is actuals to date plus the same forecast after it, so each
 * month shows actual against plan, the year to date sums the actual months,
 * and the season compares the plan's total with where the farm is now
 * heading.
 *
 * Variance is current minus plan, in display sign. Its effect is adverse
 * when costs rise or anything else falls.
 */
final class PlanComparisonSqlBuilder
{
    public const string PLAN_HORIZON = 'plan_horizon';
    public const string CURRENT_HORIZON = 'current_horizon';

    private const array COST_GROUPS = ['operating_expenses', 'non_operating_expenses'];

    /**
     * @param ?string $period only this period: `YTD`, `Season` or a month as `YYYY-MM`
     */
    public function __construct(
        private readonly int $farmId,
        private readonly ReportBasis $basis,
        private readonly ?string $period = null,
    ) {
        if ($period !== null && preg_match('/^(YTD|Season|\d{4}-\d{2})$/', $period) !== 1) {
            throw new InvalidArgumentException("A period is YTD, Season or YYYY-MM, not '{$period}'.");
        }
    }

    public function build(): string
    {
        $plan = $this->report(self::PLAN_HORIZON)->build();
        $current = $this->report(self::CURRENT_HORIZON)->build();
        $costs = implode(', ', array_map(static fn (string $g): string => "'{$g}'", self::COST_GROUPS));

        return <<<SQL
            WITH
            plan_report AS (
            {$plan}
            ),
            current_report AS (
            {$current}
            ),
            column_types AS (
                SELECT DISTINCT interval_index, column_type FROM current_report
            ),
            months AS (
                SELECT COALESCE(c.kind, p.kind) AS kind, COALESCE(c.field, p.field) AS field,
                       COALESCE(c.label, p.label) AS label, COALESCE(c.category, p.category) AS category,
                       COALESCE(c.interval_index, p.interval_index) AS interval_index, COALESCE(c.month, p.month) AS month,
                       COALESCE(p.amount, 0) AS plan, COALESCE(c.amount, 0) AS current
                FROM plan_report p
                FULL JOIN current_report c
                  ON c.kind = p.kind AND c.field = p.field AND c.category IS NOT DISTINCT FROM p.category
                 AND c.interval_index = p.interval_index
            ),
            periods AS (
                SELECT m.kind, m.field, m.label, m.category,
                       CASE WHEN t.column_type = 'total' THEN 'season' ELSE 'month' END AS period_type,
                       CASE WHEN t.column_type = 'total' THEN 'Season' ELSE m.month END AS period,
                       t.column_type, m.plan, m.current
                FROM months m
                JOIN column_types t ON t.interval_index = m.interval_index
                UNION ALL
                SELECT m.kind, m.field, m.label, m.category, 'ytd', 'YTD', 'actuals',
                       CASE WHEN m.kind = 'balance' AND m.field = 'opening' THEN arg_min(m.plan, m.interval_index)
                            WHEN m.kind = 'balance' AND m.field = 'closing' THEN arg_max(m.plan, m.interval_index)
                            ELSE SUM(m.plan) END,
                       CASE WHEN m.kind = 'balance' AND m.field = 'opening' THEN arg_min(m.current, m.interval_index)
                            WHEN m.kind = 'balance' AND m.field = 'closing' THEN arg_max(m.current, m.interval_index)
                            ELSE SUM(m.current) END
                FROM months m
                JOIN column_types t ON t.interval_index = m.interval_index
                WHERE t.column_type = 'actuals'
                GROUP BY m.kind, m.field, m.label, m.category
            ),
            row_order(kind, field, row_order) AS (
                VALUES {$this->rowOrder()}
            )
            SELECT p.kind, p.field, p.label, p.category, p.period_type, p.period, p.column_type,
                   round(p.plan, 2) AS plan, round(p.current, 2) AS current, round(p.current - p.plan, 2) AS variance,
                   CASE WHEN p.plan <> 0 THEN round(100 * (p.current - p.plan) / abs(p.plan), 1) END AS variance_pct,
                   CASE WHEN round(p.current - p.plan, 2) = 0 THEN 'none'
                        WHEN (p.current > p.plan) <> (p.kind IN ('category', 'section') AND p.field IN ({$costs})) THEN 'favourable'
                        ELSE 'adverse'
                   END AS effect,
                   o.row_order
            FROM periods p
            JOIN row_order o ON o.kind = (CASE WHEN p.kind = 'category' THEN 'section' ELSE p.kind END) AND o.field = p.field
            WHERE {$this->periodCondition()}
            ORDER BY CASE p.period_type WHEN 'month' THEN 0 WHEN 'ytd' THEN 1 ELSE 2 END, p.period,
                     o.row_order, p.kind = 'section', p.category
            SQL;
    }

    private function periodCondition(): string
    {
        return $this->period === null ? 'true' : "p.period = '{$this->period}'";
    }

    private function report(string $horizonParameter): InsightsMonthlyReportSqlBuilder
    {
        return match ($this->basis) {
            ReportBasis::Cash => new InsightsCashFlowSqlBuilder($this->farmId, $horizonParameter),
            ReportBasis::Accrual => new InsightsProfitLossSqlBuilder($this->farmId, $horizonParameter),
        };
    }

    /**
     * The report's own order: opening balance, each section (its categories,
     * then its total), the calculated rows, closing balance.
     */
    private function rowOrder(): string
    {
        $report = $this->report(self::PLAN_HORIZON);
        $rows = ["('balance', 'opening', 0)"];
        foreach ($report->sections() as $i => [$group]) {
            $rows[] = sprintf("('section', '%s', %d)", $group, 10 + $i);
        }
        foreach ($report->calculations() as $i => [$field]) {
            $rows[] = sprintf("('calculation', '%s', %d)", $field, 100 + $i);
        }
        $rows[] = "('balance', 'closing', 1000)";

        return implode(', ', $rows);
    }
}
