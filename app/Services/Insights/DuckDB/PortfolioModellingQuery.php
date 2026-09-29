<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\PortfolioAssumption;
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
    public function sql(PortfolioScope $scope, array $assumptions, bool $withBreakdown = true): string
    {
        return (new PortfolioModellingSqlBuilder($scope->farmIds, $assumptions, $withBreakdown, $scope->basis))->build();
    }

    /**
     * @param list<PortfolioAssumption> $assumptions
     * @return list<array{farm_id: ?int, season: int, line: string, farms: int, original: float, modelled: float, variance: float}>
     */
    public function run(PortfolioScope $scope, array $assumptions, bool $withBreakdown = true): array
    {
        PortfolioAssumption::toJson($assumptions);

        return array_map(static fn (array $r): array => [
            'farm_id' => $r['farm_id'] === null ? null : (int) $r['farm_id'],
            'season' => (int) $r['season'],
            'line' => (string) $r['line'],
            'farms' => (int) $r['farms'],
            'original' => (float) $r['original'],
            'modelled' => (float) $r['modelled'],
            'variance' => (float) $r['variance'],
        ], DuckDbStatement::rows($this->db, $this->sql($scope, $assumptions, $withBreakdown), $scope->bindings()));
    }

    /**
     * @param list<PortfolioAssumption> $assumptions
     */
    public function explain(PortfolioScope $scope, array $assumptions, bool $withBreakdown = true): string
    {
        $rows = DuckDbStatement::rows($this->db, 'EXPLAIN ANALYZE '.$this->sql($scope, $assumptions, $withBreakdown), $scope->bindings());

        return implode("\n", array_map(static fn (array $r): string => (string) end($r), $rows));
    }
}
