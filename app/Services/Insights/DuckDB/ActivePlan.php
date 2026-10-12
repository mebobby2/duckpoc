<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;
use InvalidArgumentException;

/**
 * The plan a farm is measured against: its forecast for one season as it
 * stood at the plan horizon, and how far actuals have come in since.
 */
final readonly class ActivePlan
{
    public function __construct(
        public int $farmId,
        public string $name,
        public int $season,
        public string $planHorizon,
        public string $actualsTo,
    ) {
    }

    public static function forFarm(int $farmId): self
    {
        $row = InsightsDuckDb::mysql()->selectOne(
            'SELECT farm_id, name, season, plan_horizon, actuals_to FROM active_plans WHERE farm_id = ?',
            [$farmId],
        ) ?? throw new InvalidArgumentException("Farm {$farmId} has no active plan; advance its practice's actuals first.");

        return new self((int) $row->farm_id, (string) $row->name, (int) $row->season, (string) $row->plan_horizon, (string) $row->actuals_to);
    }

    /**
     * The season's first and last day, from the farm's balance date.
     *
     * @return array{0: string, 1: string}
     */
    public function period(ReportBasis $basis): array
    {
        [$from, $to] = (new PortfolioScope([$this->farmId], $this->actualsTo, $this->season, $this->season, $basis))->period();

        return [$from, $to];
    }
}
