<?php

declare(strict_types=1);

namespace App\Services\Insights;

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

    public function sql(bool $withBreakdown = true, ReportBasis $basis = ReportBasis::Cash): string
    {
        return (new PortfolioModellingSqlBuilder($withBreakdown, $basis))->build();
    }

    /**
     * @param list<PortfolioAssumption> $assumptions
     * @return list<array{farm_id: ?int, season: int, line: string, farms: int, original: float, modelled: float, variance: float}>
     */
    public function run(PortfolioScope $scope, array $assumptions, bool $withBreakdown = true): array
    {
        $this->configureSession();

        $rows = $this->db->select($this->sql($withBreakdown, $scope->basis), $scope->bindings($this->db, PortfolioAssumption::toJson($assumptions)));

        return array_map(static fn (object $r): array => [
            'farm_id' => $r->farm_id === null ? null : (int) $r->farm_id,
            'season' => (int) $r->season,
            'line' => (string) $r->line,
            'farms' => (int) $r->farms,
            'original' => (float) $r->original,
            'modelled' => (float) $r->modelled,
            'variance' => (float) $r->variance,
        ], $rows);
    }

    /**
     * EXPLAIN (ANALYZE, BUFFERS) of the statement, for the profile command.
     *
     * @param list<PortfolioAssumption> $assumptions
     */
    public function explain(PortfolioScope $scope, array $assumptions, bool $withBreakdown = true): string
    {
        $this->configureSession();

        $rows = $this->db->select('EXPLAIN (ANALYZE, BUFFERS, VERBOSE) '.$this->sql($withBreakdown, $scope->basis), $scope->bindings($this->db, PortfolioAssumption::toJson($assumptions)));

        return implode("\n", array_map(static fn (object $r): string => (string) array_values((array) $r)[0], $rows));
    }

    private function configureSession(): void
    {
        InsightsSession::configure($this->db);
    }
}
