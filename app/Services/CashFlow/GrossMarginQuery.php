<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use App\Services\CashFlow\Definition\GrossMarginReportDefinition;
use Saturio\DuckDB\DuckDB;
use Saturio\DuckDB\Type\Type;

/**
 * Runs Gross Margin per operating entity as a single DuckDB query.
 *
 * The chain, per (month, tracker):
 *
 *     tracker_income        = SUM(revenue accounts, sign-flipped)
 *     tracker_direct_costs  = SUM(expense accounts)
 *     gross_margin          = tracker_income - tracker_direct_costs
 *
 *     opening_head  = opening_stock + running SUM(net_movement) before this month
 *     net_movement  = purchases + births - sales - deaths
 *     closing_head  = opening_head + net_movement
 *     average_head  = (opening_head + closing_head) / 2
 *
 *     gross_margin_per_head = gross_margin / average_head
 *
 * Journals come from the lake, trackers and stock movements from MySQL — one
 * federated query, no per-tracker round trips.
 */
final class GrossMarginQuery
{
    private readonly GrossMarginReportDefinition $definition;

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
        ?GrossMarginReportDefinition $definition = null,
    ) {
        $this->definition = $definition ?? new GrossMarginReportDefinition();
    }

    public function definition(): GrossMarginReportDefinition
    {
        return $this->definition;
    }

    /**
     * @return list<array<string, mixed>> one row per (tracker, month)
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

        return iterator_to_array($statement->execute()->rows(true));
    }

    public function sql(): string
    {
        return (new GrossMarginSqlBuilder(
            $this->definition,
            $this->alias,
            config('duckdb.app_database.alias'),
        ))->build();
    }
}
