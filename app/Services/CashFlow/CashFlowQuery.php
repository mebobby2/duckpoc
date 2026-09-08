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

        // Bound as VARCHAR, including the dates. Left to infer, the client
        // reads DuckDB's own param type — DATE, because the SQL wraps these in
        // CAST(... AS DATE) — and then fails converting a PHP string to it
        // ("Error creating a DUCKDB_TYPE_DATE from the value '2024-01-01'").
        // Binding text and letting the SQL's own CAST do the conversion keeps
        // the call sites plain strings.
        foreach ([
            'farm_id' => $farmId,
            'farm_type' => $farmType,
            'region' => $region,
            'basis' => $basis,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'horizon' => $horizon,
            // generate_series' upper bound is inclusive and steps by month, so
            // it needs the first of the final month, not the period end date.
            'last_month_start' => date('Y-m-01', strtotime($periodTo)),
        ] as $parameter => $value) {
            $statement->bindParam($parameter, $value, Type::DUCKDB_TYPE_VARCHAR);
        }

        return iterator_to_array($statement->execute()->rows(true));
    }

    public function sql(): string
    {
        return (new ReportSqlBuilder($this->definition, $this->alias))->build();
    }
}
