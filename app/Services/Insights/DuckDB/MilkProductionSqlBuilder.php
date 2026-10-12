<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;

/**
 * One farm's milk for the season, plan against current, by supply (milk
 * tracker) and for the farm: kgMS from the production records, income from
 * the profit and loss's own milk lines at each horizon, and the price paid
 * per kgMS as the one divided by the other.
 *
 * Income variance splits exactly into volume (the kgMS difference at the
 * plan's price) and price (the price difference on the kgMS supplied).
 */
final class MilkProductionSqlBuilder
{
    private const int FIXED_POINT = 10000;

    /**
     * @param ?string $period only this period: `YTD`, `Season` or a month as `YYYY-MM`
     */
    public function __construct(
        private readonly int $farmId,
        private readonly ?string $period = null,
    ) {
        PlanPeriod::condition($period, 'period');
    }

    public function build(): string
    {
        $plan = $this->income(PlanComparisonSqlBuilder::PLAN_HORIZON);
        $current = $this->income(PlanComparisonSqlBuilder::CURRENT_HORIZON);
        $planHorizon = 'CAST($'.PlanComparisonSqlBuilder::PLAN_HORIZON.' AS DATE)';
        $currentHorizon = 'CAST($'.PlanComparisonSqlBuilder::CURRENT_HORIZON.' AS DATE)';
        $trackers = InsightsDuckDb::table('milk_trackers');
        $productions = InsightsDuckDb::table('milk_productions');
        $fp = self::FIXED_POINT;

        return <<<SQL
            WITH
            plan_income AS (
            {$plan}
            ),
            current_income AS (
            {$current}
            ),
            trackers AS (
                SELECT id, name, income_accountid FROM {$trackers}
                WHERE farm_id = {$this->farmId} AND _valid_to IS NULL
            ),
            months AS (
                SELECT CAST(g.m AS DATE) AS month,
                       CAST(g.m + INTERVAL '1 month' - INTERVAL '1 day' AS DATE) <= {$currentHorizon} AS actual
                FROM generate_series(CAST(CAST(\$period_from AS DATE) AS TIMESTAMP), CAST(CAST(\$period_to AS DATE) AS TIMESTAMP), INTERVAL '1 month') AS g(m)
            ),
            production AS (
                SELECT milk_tracker_id, transaction_date AS month,
                       SUM(production) FILTER (WHERE (transaction_date <= {$planHorizon} AND type = 'actual')
                                                  OR (transaction_date > {$planHorizon} AND type = 'forecast')) AS plan_kgms,
                       SUM(production) FILTER (WHERE (transaction_date <= {$currentHorizon} AND type = 'actual')
                                                  OR (transaction_date > {$currentHorizon} AND type = 'forecast')) AS current_kgms
                FROM {$productions}
                WHERE farm_id = {$this->farmId} AND _valid_to IS NULL AND budget_id = 0
                  AND transaction_date BETWEEN CAST(\$period_from AS DATE) AND CAST(\$period_to AS DATE)
                GROUP BY 1, 2
            ),
            tracker_months AS (
                SELECT t.id AS tracker_id, t.name AS tracker, m.month, m.actual,
                       COALESCE(p.plan_kgms, 0) AS plan_kgms, COALESCE(p.current_kgms, 0) AS current_kgms,
                       COALESCE(pi.income, 0) AS plan_income, COALESCE(ci.income, 0) AS current_income
                FROM trackers t
                CROSS JOIN months m
                LEFT JOIN production p ON p.milk_tracker_id = t.id AND p.month = m.month
                LEFT JOIN plan_income pi ON pi.account_id = t.income_accountid AND pi.month = m.month
                LEFT JOIN current_income ci ON ci.account_id = t.income_accountid AND ci.month = m.month
            ),
            periods AS (
                SELECT tracker_id, tracker, 'month' AS period_type, strftime(month, '%Y-%m') AS period,
                       CASE WHEN actual THEN 'actual' ELSE 'forecast' END AS status,
                       plan_kgms, current_kgms, plan_income, current_income
                FROM tracker_months
                UNION ALL
                SELECT tracker_id, tracker, 'ytd', 'YTD', 'actual',
                       SUM(plan_kgms), SUM(current_kgms), SUM(plan_income), SUM(current_income)
                FROM tracker_months WHERE actual
                GROUP BY tracker_id, tracker
                UNION ALL
                SELECT tracker_id, tracker, 'season', 'Season', 'projected',
                       SUM(plan_kgms), SUM(current_kgms), SUM(plan_income), SUM(current_income)
                FROM tracker_months
                GROUP BY tracker_id, tracker
            ),
            farm_and_trackers AS (
                SELECT * FROM periods
                UNION ALL
                SELECT NULL, 'Farm total', period_type, period, any_value(status),
                       SUM(plan_kgms), SUM(current_kgms), SUM(plan_income), SUM(current_income)
                FROM periods
                GROUP BY period_type, period
            ),
            priced AS (
                SELECT *,
                       CASE WHEN plan_kgms > 0 THEN CAST(plan_income AS DOUBLE) / {$fp} / plan_kgms END AS plan_price,
                       CASE WHEN current_kgms > 0 THEN CAST(current_income AS DOUBLE) / {$fp} / current_kgms END AS current_price
                FROM farm_and_trackers
            )
            SELECT tracker_id, tracker, period_type, period, status,
                   plan_kgms, current_kgms, current_kgms - plan_kgms AS kgms_variance,
                   CASE WHEN plan_kgms > 0 THEN round(100.0 * (current_kgms - plan_kgms) / plan_kgms, 1) END AS kgms_variance_pct,
                   round(CAST(plan_income AS DOUBLE) / {$fp}, 2) AS plan_income,
                   round(CAST(current_income AS DOUBLE) / {$fp}, 2) AS current_income,
                   round(CAST(current_income - plan_income AS DOUBLE) / {$fp}, 2) AS income_variance,
                   round(plan_price, 2) AS plan_price_per_kgms,
                   round(current_price, 2) AS current_price_per_kgms,
                   round((current_kgms - plan_kgms) * plan_price, 2) AS volume_effect,
                   round((current_price - plan_price) * current_kgms, 2) AS price_effect
            FROM priced
            WHERE {$this->periodCondition()}
            ORDER BY tracker_id NULLS FIRST, CASE period_type WHEN 'month' THEN 0 WHEN 'ytd' THEN 1 ELSE 2 END, period
            SQL;
    }

    private function periodCondition(): string
    {
        return PlanPeriod::condition($this->period, 'period');
    }

    /** Milk income by supply account and month, from the profit and loss's report lines at one horizon. */
    private function income(string $horizonParameter): string
    {
        $ctes = (new ReportLinesSqlBuilder([$this->farmId], ReportBasis::Accrual, $horizonParameter))
            ->ctes(ReportLinesSqlBuilder::periodWindow());

        return ReportLinesSqlBuilder::statement($ctes, <<<'SQL'
            SELECT account_id, CAST(date_trunc('month', date) AS DATE) AS month, -SUM(amount) AS income
            FROM report_lines
            WHERE category = 'Milk Income'
            GROUP BY 1, 2
            SQL);
    }
}
