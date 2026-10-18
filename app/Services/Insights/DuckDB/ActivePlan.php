<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;
use InvalidArgumentException;

/**
 * The plan a farm is measured against: its forecast for one season as it
 * stood at the plan horizon (its budget), and how far actuals have come in
 * since.
 */
final readonly class ActivePlan
{
    /**
     * @param string $budgetKnownAt when the budget was agreed: forecast changes adopted after it are not part of it
     */
    public function __construct(
        public int $farmId,
        public string $name,
        public int $season,
        public string $planHorizon,
        public string $actualsTo,
        public string $budgetKnownAt,
    ) {
    }

    public static function forFarm(int $farmId): self
    {
        $row = InsightsDuckDb::mysql()->selectOne(
            "SELECT ap.farm_id, ap.name, ap.season, ap.plan_horizon, ap.actuals_to,
                    COALESCE(fs.taken_at, ap.plan_horizon + INTERVAL 1 DAY) AS budget_known_at
             FROM active_plans ap
             LEFT JOIN forecast_snapshots fs ON fs.farm_id = ap.farm_id AND fs.kind = 'budget'
             WHERE ap.farm_id = ?",
            [$farmId],
        ) ?? throw new InvalidArgumentException("Farm {$farmId} has no active plan; advance its practice's actuals first.");

        return new self((int) $row->farm_id, (string) $row->name, (int) $row->season, (string) $row->plan_horizon,
            (string) $row->actuals_to, (string) $row->budget_known_at);
    }

    /**
     * The season's first and last day, from the farm's balance date.
     *
     * @return array{0: string, 1: string}
     */
    public function period(ReportBasis $basis = ReportBasis::Accrual): array
    {
        return $this->seasonPeriod($this->season);
    }

    /**
     * Any season's first and last day, by the year it ends in.
     *
     * @return array{0: string, 1: string}
     */
    public function seasonPeriod(int $season): array
    {
        [$from, $to] = (new PortfolioScope([$this->farmId], $this->actualsTo, $season, $season, ReportBasis::Accrual))->period();

        return [$from, $to];
    }

    /**
     * What every plan comparison of the season binds: both horizons, when
     * the budget was agreed, and the season as the window shown, the reports
     * starting early enough for the budget's horizon.
     *
     * @return array<string, string>
     */
    public function seasonBindings(): array
    {
        [$from, $to] = $this->period();
        $reportFrom = min($from, self::reportStart($this->planHorizon));

        return [
            PlanComparisonSqlBuilder::PLAN_HORIZON => $this->planHorizon,
            PlanComparisonSqlBuilder::CURRENT_HORIZON => $this->actualsTo,
            PlanComparisonSqlBuilder::BUDGET_KNOWN_AT => $this->budgetKnownAt,
            'period_from' => $reportFrom,
            'period_to' => $to,
            ReportComparisonSqlBuilder::MAIN_FROM => $from,
            'opening_before' => $reportFrom,
        ];
    }

    /**
     * The latest a report can start and still be right after a horizon:
     * the first GST return predicted after it covers the two months before
     * it is paid, and the opening bank balance must not skip the milk
     * cheques worked out after it. Two months before the horizon's month.
     */
    public static function reportStart(string $horizon): string
    {
        return date('Y-m-01', (int) strtotime(substr($horizon, 0, 7).'-01 -2 months'));
    }
}
