<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use App\Services\CashFlow\Definition\TrackerCashFlowReportDefinition;
use Saturio\DuckDB\DuckDB;
use Saturio\DuckDB\Type\Type;

/**
 * Runs Cash Flow with per-tracker sections as a single DuckDB query.
 *
 * The chain, matching what `CashFlowStructureBuilder` assembles once
 * `LivestockStructureBuilder` has contributed a section set per tracker:
 *
 *     -- per (month, tracker)
 *     tracker_gross_profit  = tracker_income - tracker_direct_costs
 *
 *     -- consolidated
 *     trackers_gross_profit = SUM(tracker_gross_profit)   -- over all trackers
 *     gross_profit          = trackers_gross_profit + other_income
 *                                                   - direct_costs
 *     operating_surplus     = gross_profit - operating_expenses
 *     net_cash_movement     = operating_surplus + gst
 *     closing               = opening + net_cash_movement
 *
 * `trackers_gross_profit` is where Figured builds a formula string with one
 * term per tracker. Here the tracker count never reaches the SQL.
 */
final class TrackerCashFlowQuery
{
    private readonly TrackerCashFlowReportDefinition $definition;

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
        ?TrackerCashFlowReportDefinition $definition = null,
    ) {
        $this->definition = $definition ?? new TrackerCashFlowReportDefinition();
    }

    public function definition(): TrackerCashFlowReportDefinition
    {
        return $this->definition;
    }

    /**
     * The consolidated report — one row per month.
     *
     * @return list<array<string, mixed>>
     */
    public function run(
        string $farmId,
        string $farmType,
        string $region,
        string $periodFrom,
        string $periodTo,
        string $horizon,
        string $basis = 'cash',
    ): array {
        $statement = $this->db->preparedStatement($this->sql());

        $this->bindScope($statement, $farmId, $farmType, $region, $periodFrom, $periodTo, $horizon, $basis);
        $this->bindMonthSpineBound($statement, $periodTo);

        return iterator_to_array($statement->execute()->rows(true));
    }

    /**
     * The per-tracker breakdown — one row per (month, tracker).
     *
     * @return list<array<string, mixed>>
     */
    public function trackerDetail(
        string $farmId,
        string $farmType,
        string $region,
        string $periodFrom,
        string $periodTo,
        string $horizon,
        string $basis = 'cash',
    ): array {
        $statement = $this->db->preparedStatement($this->builder()->buildTrackerDetailSql());

        $this->bindScope($statement, $farmId, $farmType, $region, $periodFrom, $periodTo, $horizon, $basis);
        $this->bindMonthSpineBound($statement, $periodTo);

        return iterator_to_array($statement->execute()->rows(true));
    }

    /**
     * The individual transaction lines the report consumed.
     *
     * @return list<array<string, mixed>>
     */
    public function sourceRows(
        string $farmId,
        string $farmType,
        string $region,
        string $periodFrom,
        string $periodTo,
        string $horizon,
        string $basis = 'cash',
        int $limit = 500,
    ): array {
        $statement = $this->db->preparedStatement($this->builder()->buildSourceRowsSql());

        $this->bindScope($statement, $farmId, $farmType, $region, $periodFrom, $periodTo, $horizon, $basis);

        // Type left to inference, unlike the scope params: DuckDB infers an
        // integer for LIMIT and forcing VARCHAR fails outright.
        $statement->bindParam('row_limit', $limit);

        return iterator_to_array($statement->execute()->rows(true));
    }

    /**
     * @return array{n: int, n_tracker_tagged: int, n_trackers: int, net_dollars: float}
     */
    public function sourceRowSummary(
        string $farmId,
        string $farmType,
        string $region,
        string $periodFrom,
        string $periodTo,
        string $horizon,
        string $basis = 'cash',
    ): array {
        $statement = $this->db->preparedStatement($this->builder()->buildSourceRowCountSql());

        $this->bindScope($statement, $farmId, $farmType, $region, $periodFrom, $periodTo, $horizon, $basis);

        $row = iterator_to_array($statement->execute()->rows(true))[0] ?? [];

        // Cast via string: DuckDB's aggregate types come back as LongInteger
        // objects the driver will not cast to int directly.
        return [
            'n' => (int) (string) ($row['n'] ?? 0),
            'n_tracker_tagged' => (int) (string) ($row['n_tracker_tagged'] ?? 0),
            'n_trackers' => (int) (string) ($row['n_trackers'] ?? 0),
            'net_dollars' => (float) (string) ($row['net_dollars'] ?? 0),
        ];
    }

    public function sql(): string
    {
        return $this->builder()->build();
    }

    public function trackerDetailSql(): string
    {
        return $this->builder()->buildTrackerDetailSql();
    }

    private function builder(): TrackerReportSqlBuilder
    {
        return new TrackerReportSqlBuilder(
            $this->definition,
            $this->alias,
            config('duckdb.app_database.alias'),
        );
    }

    /**
     * generate_series' upper bound is inclusive and steps by month, so it
     * needs the first of the final month rather than the period end date.
     */
    private function bindMonthSpineBound(object $statement, string $periodTo): void
    {
        $statement->bindParam(
            'last_month_start',
            date('Y-m-01', strtotime($periodTo)),
            Type::DUCKDB_TYPE_VARCHAR,
        );
    }

    /**
     * Everything bound as VARCHAR: the date parameters are CAST in the SQL,
     * and binding them as DATE fails in the FFI driver's conversion.
     */
    private function bindScope(
        object $statement,
        string $farmId,
        string $farmType,
        string $region,
        string $periodFrom,
        string $periodTo,
        string $horizon,
        string $basis,
    ): void {
        foreach ([
            'farm_id' => $farmId,
            'farm_type' => $farmType,
            'region' => $region,
            'basis' => $basis,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'horizon' => $horizon,
        ] as $parameter => $value) {
            $statement->bindParam($parameter, $value, Type::DUCKDB_TYPE_VARCHAR);
        }
    }
}
