<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\PortfolioAssumption;
use App\Services\Insights\PortfolioBreakdown;
use Saturio\DuckDB\DuckDB;

/**
 * Runs the Portfolio Modelling statement on DuckDB.
 */
final class PortfolioModellingQuery
{
    public function __construct(
        private readonly DuckDB $db,
    ) {
    }

    /**
     * @param list<PortfolioAssumption> $assumptions
     */
    public function sql(PortfolioScope $scope, array $assumptions, PortfolioBreakdown $breakdown): string
    {
        return (new PortfolioModellingSqlBuilder($scope->farmIds, $assumptions, $breakdown, $scope->basis))->build();
    }

    /**
     * @param list<PortfolioAssumption> $assumptions
     * @return list<array{farm_id: ?int, farm_name: ?string, region: ?string, farm_type: ?string, season: int, line: string, is_cost: bool, farms: int, original: float, modelled: float, variance: float, original_per_farm: float, modelled_per_farm: float, variance_per_farm: float}>
     */
    public function run(PortfolioScope $scope, array $assumptions, PortfolioBreakdown $breakdown): array
    {
        return array_map(static fn (array $r): array => [
            'farm_id' => $r['farm_id'] === null ? null : (int) $r['farm_id'],
            'farm_name' => $r['farm_name'],
            'region' => $r['region'],
            'farm_type' => $r['farm_type'],
            'season' => (int) $r['season'],
            'line' => (string) $r['line'],
            'is_cost' => (bool) $r['is_cost'],
            'farms' => (int) $r['farms'],
            'original' => (float) $r['original'],
            'modelled' => (float) $r['modelled'],
            'variance' => (float) $r['variance'],
            'original_per_farm' => (float) $r['original_per_farm'],
            'modelled_per_farm' => (float) $r['modelled_per_farm'],
            'variance_per_farm' => (float) $r['variance_per_farm'],
        ], DuckDbStatement::rows($this->db, $this->sql($scope, $assumptions, $breakdown), $scope->bindings()));
    }

    /**
     * @param list<PortfolioAssumption> $assumptions
     */
    public function explain(PortfolioScope $scope, array $assumptions, PortfolioBreakdown $breakdown): string
    {
        $rows = DuckDbStatement::rows($this->db, 'EXPLAIN ANALYZE '.$this->sql($scope, $assumptions, $breakdown), $scope->bindings());

        return implode("\n", array_map(static fn (array $r): string => (string) end($r), $rows));
    }
}
