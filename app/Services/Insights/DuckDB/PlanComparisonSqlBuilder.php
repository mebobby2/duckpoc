<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;

/**
 * One farm's season, budget against current, in one statement: the
 * comparison of the two versions the active plan is about, in the columns
 * the plan comparison has always had (plan, current, variance as current
 * minus plan, its percentage and effect).
 *
 * The plan is the budget: the forecast as agreed, actuals to the plan
 * horizon, before any scenario was adopted. Current is actuals to date plus
 * the forecast as it stands now, adopted scenarios included. Both carry the
 * farm's forecast overdraft interest.
 */
final class PlanComparisonSqlBuilder
{
    public const string PLAN_HORIZON = 'plan_horizon';
    public const string CURRENT_HORIZON = 'current_horizon';

    /** When the budget was agreed: the forecast changes it includes are those adopted by then. */
    public const string BUDGET_KNOWN_AT = 'budget_known_at';

    public const array COST_GROUPS = ReportComparisonSqlBuilder::COST_GROUPS;

    /**
     * @param ?string $period only this period: `YTD`, `Season` or a month as `YYYY-MM`
     * @param bool $withCategories false for the sections, calculations and balances alone
     */
    public function __construct(
        private readonly int $farmId,
        private readonly ReportBasis $basis,
        private readonly ?string $period = null,
        private readonly bool $withCategories = true,
    ) {
        PlanPeriod::condition($period, 'p.period');
    }

    public static function current(): ReportSide
    {
        return new ReportSide('current', 'Current forecast', self::CURRENT_HORIZON, ForecastEdits::adopted());
    }

    public static function budget(string $key = 'budget'): ReportSide
    {
        return new ReportSide($key, 'Budget', self::PLAN_HORIZON, ForecastEdits::adopted(self::BUDGET_KNOWN_AT));
    }

    public function build(): string
    {
        $comparison = (new ReportComparisonSqlBuilder(
            $this->farmId,
            $this->basis,
            [self::current(), self::budget('plan')],
            $this->period,
            $this->withCategories,
        ))->build();

        return <<<SQL
            SELECT kind, field, label, category, CASE period_type WHEN 'total' THEN 'season' ELSE period_type END AS period_type,
                   period, status, plan, current, vs_plan AS variance, vs_plan_pct AS variance_pct, vs_plan_effect AS effect, row_order
            FROM (
            {$comparison}
            )
            SQL;
    }
}
