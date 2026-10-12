<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;
use Saturio\DuckDB\DuckDB;

/**
 * Runs a farm's plan comparison: every report row for each month, the year
 * to date and the season, with plan, current, variance and its effect.
 */
final class PlanComparisonQuery
{
    public function __construct(private readonly DuckDB $db)
    {
    }

    /**
     * @return list<array{kind: string, field: string, label: string, category: ?string, period_type: string, period: string, status: string, plan: float, current: float, variance: float, variance_pct: ?float, effect: string}>
     */
    public function run(ActivePlan $plan, ReportBasis $basis, ?string $period = null, bool $withCategories = true): array
    {
        [$from, $to] = $plan->period($basis);
        $bindings = [
            PlanComparisonSqlBuilder::PLAN_HORIZON => $plan->planHorizon,
            PlanComparisonSqlBuilder::CURRENT_HORIZON => $plan->actualsTo,
            'period_from' => $from,
            'period_to' => $to,
        ];
        if ($basis === ReportBasis::Cash) {
            $bindings['opening_before'] = $from;
        }

        return array_map(static fn (array $r): array => [
            'kind' => (string) $r['kind'],
            'field' => (string) $r['field'],
            'label' => (string) $r['label'],
            'category' => $r['category'],
            'period_type' => (string) $r['period_type'],
            'period' => (string) $r['period'],
            'status' => (string) $r['status'],
            'plan' => (float) $r['plan'],
            'current' => (float) $r['current'],
            'variance' => (float) $r['variance'],
            'variance_pct' => $r['variance_pct'] === null ? null : (float) $r['variance_pct'],
            'effect' => (string) $r['effect'],
        ], DuckDbStatement::rows($this->db, (new PlanComparisonSqlBuilder($plan->farmId, $basis, $period, $withCategories))->build(), $bindings));
    }
}
