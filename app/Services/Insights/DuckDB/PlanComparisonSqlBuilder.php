<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;

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
 *
 * The current side includes the scenarios the farm has adopted since the
 * plan was agreed; the plan never does. With a scenario, a third side is
 * the current one plus the proposed changes, and each row also carries the
 * scenario's amount and its change from current.
 */
final class PlanComparisonSqlBuilder
{
    public const string PLAN_HORIZON = 'plan_horizon';
    public const string CURRENT_HORIZON = 'current_horizon';

    /** Report groups where spending more than planned is adverse; for everything else, less is. */
    public const array COST_GROUPS = ['operating_expenses', 'non_operating_expenses'];

    /**
     * @param ?string $period only this period: `YTD`, `Season` or a month as `YYYY-MM`
     * @param bool $withCategories false for the sections, calculations and balances alone
     */
    public function __construct(
        private readonly int $farmId,
        private readonly ReportBasis $basis,
        private readonly ?string $period = null,
        private readonly bool $withCategories = true,
        private readonly bool $withScenario = false,
    ) {
        PlanPeriod::condition($period, 'p.period');
    }

    public function build(): string
    {
        $sides = [
            'plan' => $this->report(self::PLAN_HORIZON, ForecastEdits::None),
            'current' => $this->report(self::CURRENT_HORIZON, ForecastEdits::Adopted),
        ];
        if ($this->withScenario) {
            $sides['scenario'] = $this->report(self::CURRENT_HORIZON, ForecastEdits::AdoptedAndProposed);
        }

        $reports = implode(",\n", array_map(
            static fn (string $side, InsightsMonthlyReportSqlBuilder $report): string => "{$side}_report AS (\n{$report->build()}\n)",
            array_keys($sides),
            $sides,
        ));
        $versions = implode("\n    UNION ALL\n    ", array_map(static fn (string $side): string => "SELECT '{$side}' AS side, * FROM {$side}_report", array_keys($sides)));
        $byMonth = implode(",\n           ", array_map(static fn (string $side): string => "COALESCE(SUM(amount) FILTER (WHERE side = '{$side}'), 0) AS {$side}", array_keys($sides)));
        $byPeriod = implode(', ', array_map(static fn (string $side): string => "m.{$side}", array_keys($sides)));
        $toDate = implode(",\n           ", array_map(static fn (string $side): string => <<<SQL
            CASE WHEN m.kind = 'balance' AND m.field = 'opening' THEN arg_min(m.{$side}, m.interval_index)
                            WHEN m.kind = 'balance' AND m.field = 'closing' THEN arg_max(m.{$side}, m.interval_index)
                            ELSE SUM(m.{$side}) END
            SQL, array_keys($sides)));
        $scenario = $this->withScenario
            ? sprintf(
                ",\n       round(p.scenario, 2) AS scenario, round(p.scenario - p.current, 2) AS scenario_change,\n       %s AS scenario_effect",
                $this->effect('p.current', 'p.scenario'),
            )
            : '';

        return <<<SQL
            WITH
            {$reports},
            versions AS (
                {$versions}
            ),
            column_types AS (
                SELECT DISTINCT interval_index, column_type FROM current_report
            ),
            months AS (
                SELECT kind, field, any_value(label) AS label, category, interval_index, any_value(month) AS month,
                       {$byMonth}
                FROM versions
                GROUP BY kind, field, category, interval_index
            ),
            periods AS (
                SELECT m.kind, m.field, m.label, m.category,
                       CASE WHEN t.column_type = 'total' THEN 'season' ELSE 'month' END AS period_type,
                       CASE WHEN t.column_type = 'total' THEN 'Season' ELSE m.month END AS period,
                       t.column_type, {$byPeriod}
                FROM months m
                JOIN column_types t ON t.interval_index = m.interval_index
                UNION ALL
                SELECT m.kind, m.field, m.label, m.category, 'ytd', 'YTD', 'actuals',
                       {$toDate}
                FROM months m
                JOIN column_types t ON t.interval_index = m.interval_index
                WHERE t.column_type = 'actuals'
                GROUP BY m.kind, m.field, m.label, m.category
            ),
            row_order(kind, field, row_order) AS (
                VALUES {$this->rowOrder()}
            )
            SELECT p.kind, p.field, p.label, p.category, p.period_type, p.period,
                   CASE p.column_type WHEN 'actuals' THEN 'actual' WHEN 'forecast' THEN 'forecast'
                                      WHEN 'actualsForecast' THEN 'part_actual' ELSE 'projected' END AS status,
                   round(p.plan, 2) AS plan, round(p.current, 2) AS current, round(p.current - p.plan, 2) AS variance,
                   CASE WHEN p.plan <> 0 THEN round(100 * (p.current - p.plan) / abs(p.plan), 1) END AS variance_pct,
                   {$this->effect('p.plan', 'p.current')} AS effect,
                   o.row_order{$scenario}
            FROM periods p
            JOIN row_order o ON o.kind = (CASE WHEN p.kind = 'category' THEN 'section' ELSE p.kind END) AND o.field = p.field
            WHERE {$this->periodCondition()}
            ORDER BY CASE p.period_type WHEN 'month' THEN 0 WHEN 'ytd' THEN 1 ELSE 2 END, p.period,
                     o.row_order, p.kind = 'section', p.category
            SQL;
    }

    /**
     * Whether moving from one amount to another helps the farm: adverse when
     * costs rise or anything else falls.
     */
    private function effect(string $from, string $to): string
    {
        $costs = implode(', ', array_map(static fn (string $g): string => "'{$g}'", self::COST_GROUPS));

        return <<<SQL
            CASE WHEN round({$to} - {$from}, 2) = 0 THEN 'none'
                        WHEN ({$to} > {$from}) <> (p.kind IN ('category', 'section') AND p.field IN ({$costs})) THEN 'favourable'
                        ELSE 'adverse'
                   END
            SQL;
    }

    private function periodCondition(): string
    {
        $period = PlanPeriod::condition($this->period, 'p.period');

        return $this->withCategories ? $period : "{$period} AND p.kind <> 'category'";
    }

    private function report(string $horizonParameter, ForecastEdits $edits = ForecastEdits::None): InsightsMonthlyReportSqlBuilder
    {
        return match ($this->basis) {
            ReportBasis::Cash => new InsightsCashFlowSqlBuilder($this->farmId, $horizonParameter, $edits),
            ReportBasis::Accrual => new InsightsProfitLossSqlBuilder($this->farmId, $horizonParameter, $edits),
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
