<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;

/**
 * The bottom lines of plan status, from the plan comparisons themselves:
 * operating surplus and net profit from the profit and loss, the bank
 * balance from the cash flow, year to date and for the season, and the
 * season's lowest month-end bank balance on each side, with the month it
 * falls in.
 *
 * With a scenario, each bottom line also carries the scenario's figure, its
 * change from current, and for the lowest balance the month it falls in.
 */
final class PlanHeadlineSqlBuilder
{
    public function __construct(private readonly int $farmId, private readonly bool $withScenario = false)
    {
    }

    public function build(): string
    {
        $profit = (new PlanComparisonSqlBuilder($this->farmId, ReportBasis::Accrual, withCategories: false, withScenario: $this->withScenario))->build();
        $cash = (new PlanComparisonSqlBuilder($this->farmId, ReportBasis::Cash, withCategories: false, withScenario: $this->withScenario))->build();

        $lowScenario = '';
        $scenarioColumns = '';
        $fromComparison = '';
        $fromLowPoint = '';
        if ($this->withScenario) {
            $lowScenario = ', min(scenario) AS scenario, arg_min(period, scenario) AS scenario_month';
            $scenarioColumns = ', scenario, scenario_change, scenario_effect, scenario_month';
            $fromComparison = ', scenario, scenario_change, scenario_effect, CAST(NULL AS VARCHAR) AS scenario_month';
            $fromLowPoint = <<<'SQL'
                , scenario, round(scenario - current, 2),
                CASE WHEN round(scenario - current, 2) = 0 THEN 'none' WHEN scenario > current THEN 'favourable' ELSE 'adverse' END,
                scenario_month
                SQL;
        }

        return <<<SQL
            WITH
            profit AS (
            {$profit}
            ),
            cash AS (
            {$cash}
            ),
            low_point AS (
                SELECT min(plan) AS plan, arg_min(period, plan) AS plan_month,
                       min(current) AS current, arg_min(period, current) AS current_month{$lowScenario}
                FROM cash
                WHERE kind = 'balance' AND field = 'closing' AND period_type = 'month'
            )
            SELECT measure, period, status, plan, current, variance, variance_pct, effect, plan_month, current_month{$scenarioColumns} FROM (
                SELECT 1 AS measure_order, label AS measure, period, status, plan, current, variance, variance_pct, effect,
                       CAST(NULL AS VARCHAR) AS plan_month, CAST(NULL AS VARCHAR) AS current_month, row_order{$fromComparison}
                FROM profit
                WHERE kind = 'calculation' AND period_type IN ('ytd', 'season')
                UNION ALL
                SELECT 2, 'Bank balance', period, status, plan, current, variance, variance_pct, effect, NULL, NULL, row_order{$fromComparison}
                FROM cash
                WHERE kind = 'balance' AND field = 'closing' AND period_type IN ('ytd', 'season')
                UNION ALL
                SELECT 3, 'Lowest month-end bank balance', 'Season', 'projected', plan, current, round(current - plan, 2),
                       CASE WHEN plan <> 0 THEN round(100 * (current - plan) / abs(plan), 1) END,
                       CASE WHEN round(current - plan, 2) = 0 THEN 'none' WHEN current > plan THEN 'favourable' ELSE 'adverse' END,
                       plan_month, current_month, 0{$fromLowPoint}
                FROM low_point
            )
            ORDER BY measure_order, row_order, period = 'Season'
            SQL;
    }
}
