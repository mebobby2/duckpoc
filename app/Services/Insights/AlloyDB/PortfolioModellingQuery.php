<?php

declare(strict_types=1);

namespace App\Services\Insights\AlloyDB;

use App\Services\Insights\PortfolioAssumption;
use App\Services\Insights\PortfolioBreakdown;
use App\Services\Insights\ReportBasis;
use Illuminate\Database\ConnectionInterface;

/**
 * Runs the Portfolio Modelling statement against AlloyDB.
 */
final class PortfolioModellingQuery
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
    }

    public function sql(PortfolioBreakdown $breakdown, ReportBasis $basis = ReportBasis::Cash): string
    {
        return (new PortfolioModellingSqlBuilder($breakdown, $basis))->build();
    }

    /**
     * @param list<PortfolioAssumption> $assumptions
     * @return list<array{farm_id: ?int, farm_name: ?string, region: ?string, farm_type: ?string, season: int, line: string, is_cost: bool, farms: int, original: float, modelled: float, variance: float, original_per_farm: float, modelled_per_farm: float, variance_per_farm: float}>
     */
    public function run(PortfolioScope $scope, array $assumptions, PortfolioBreakdown $breakdown): array
    {
        $this->configureSession();

        $rows = $this->db->select($this->sql($breakdown, $scope->basis), $scope->bindings($this->db, PortfolioAssumption::toJson($assumptions)));

        return array_map(static fn (object $r): array => [
            'farm_id' => $r->farm_id === null ? null : (int) $r->farm_id,
            'farm_name' => $r->farm_name,
            'region' => $r->region,
            'farm_type' => $r->farm_type,
            'season' => (int) $r->season,
            'line' => (string) $r->line,
            'is_cost' => (bool) $r->is_cost,
            'farms' => (int) $r->farms,
            'original' => (float) $r->original,
            'modelled' => (float) $r->modelled,
            'variance' => (float) $r->variance,
            'original_per_farm' => (float) $r->original_per_farm,
            'modelled_per_farm' => (float) $r->modelled_per_farm,
            'variance_per_farm' => (float) $r->variance_per_farm,
        ], $rows);
    }

    /**
     * EXPLAIN (ANALYZE, BUFFERS) of the statement, for the profile command.
     *
     * @param list<PortfolioAssumption> $assumptions
     */
    public function explain(PortfolioScope $scope, array $assumptions, PortfolioBreakdown $breakdown): string
    {
        $this->configureSession();

        $rows = $this->db->select('EXPLAIN (ANALYZE, BUFFERS, VERBOSE) '.$this->sql($breakdown, $scope->basis), $scope->bindings($this->db, PortfolioAssumption::toJson($assumptions)));

        return implode("\n", array_map(static fn (object $r): string => (string) array_values((array) $r)[0], $rows));
    }

    private function configureSession(): void
    {
        InsightsSession::configure($this->db);
    }
}
