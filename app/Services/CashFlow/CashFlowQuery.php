<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use App\Services\CashFlow\Definition\CashFlowReportDefinition;
use Saturio\DuckDB\DuckDB;
use Saturio\DuckDB\Type\Type;

/**
 * Runs Figured's Cash Flow report as a single DuckDB query over DuckLake.
 *
 * Thin by design — the report's meaning lives in
 * `CashFlowReportDefinition`, the SQL generation in `ReportSqlBuilder`, and
 * this class only prepares, binds and executes.
 *
 * The chain being reproduced, matching `CashFlowStructureBuilder`'s own
 * `ReportCalculationRow` declarations:
 *
 *     gross_profit      = income - direct_costs
 *     operating_surplus = gross_profit - operating_expenses
 *     total_surplus     = operating_surplus + non_operating_income
 *                                           - non_operating_expenses
 *     net_cash_movement = total_surplus + non_operating_movements
 *                                       + equity_movements + gst
 *     closing           = opening + net_cash_movement
 *     opening[n]        = closing[n-1]   (seeded from farms.opening_balance)
 *
 * Verified against the parity oracle in this project's README —
 * `duckdb:cashflow:run` asserts every cell.
 */
final class CashFlowQuery
{
    private readonly CashFlowReportDefinition $definition;

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
        ?CashFlowReportDefinition $definition = null,
    ) {
        $this->definition = $definition ?? new CashFlowReportDefinition();
    }

    public function definition(): CashFlowReportDefinition
    {
        return $this->definition;
    }

    /**
     * @return list<array<string, mixed>> one row per month, in period order
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

        // generate_series' upper bound is inclusive and steps by month, so it
        // needs the first of the final month, not the period end date. Only
        // the report query has a month spine, so this is bound here rather
        // than in bindScope().
        $statement->bindParam(
            'last_month_start',
            date('Y-m-01', strtotime($periodTo)),
            Type::DUCKDB_TYPE_VARCHAR,
        );

        return iterator_to_array($statement->execute()->rows(true));
    }

    /**
     * The individual transaction lines the report consumed.
     *
     * Uses the same in-scope predicate as the report, so this is genuinely
     * what fed the numbers rather than a re-derived approximation.
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

        // Type left to inference here, unlike the scope params: DuckDB infers
        // an integer for LIMIT, and forcing VARCHAR fails outright
        // ("Error creating a DUCKDB_TYPE_VARCHAR from the value '500'").
        $statement->bindParam('row_limit', $limit);

        return iterator_to_array($statement->execute()->rows(true));
    }

    /**
     * Row count and net total across everything in scope — so the viewer can
     * say how much it is not showing when the listing is truncated.
     *
     * @return array{n: int, net_dollars: float}
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

        return [
            'n' => (int) ($row['n'] ?? 0),
            'net_dollars' => (float) ($row['net_dollars'] ?? 0),
        ];
    }

    public function sql(): string
    {
        return $this->builder()->build();
    }

    private function builder(): ReportSqlBuilder
    {
        return new ReportSqlBuilder(
            $this->definition,
            $this->alias,
            config('duckdb.app_database.alias'),
        );
    }

    /**
     * Everything bound as VARCHAR — see the note in run() for why inferring
     * the type fails on the CAST-wrapped date parameters.
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
            'basis' => $basis,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'horizon' => $horizon,
        ] as $parameter => $value) {
            $statement->bindParam($parameter, $value, Type::DUCKDB_TYPE_VARCHAR);
        }
    }
}
