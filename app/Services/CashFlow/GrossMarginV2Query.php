<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Saturio\DuckDB\DuckDB;
use Saturio\DuckDB\Type\Type;

/**
 * Runs Gross Margin V2 — hierarchical, mixed milk + livestock — as one query.
 *
 * Returns long rows, one per (row, month), each carrying its `level`:
 * 0 = line item, 1 = section subtotal, 2 = grand total. The view assembles the
 * tree from that; the query does the aggregation at all three levels in a
 * single scan via GROUPING SETS.
 */
final class GrossMarginV2Query
{
    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
    ) {
    }

    /**
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

        return iterator_to_array($statement->execute()->rows(true));
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

    /**
     * The journal lines the report consumed.
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
        $statement->bindParam('row_limit', $limit);

        return iterator_to_array($statement->execute()->rows(true));
    }

    /**
     * @return array{n: int, n_groups: int, n_trackers: int, net_dollars: float}
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
            'n' => (int) (string) ($row['n'] ?? 0),
            'n_groups' => (int) (string) ($row['n_groups'] ?? 0),
            'n_trackers' => (int) (string) ($row['n_trackers'] ?? 0),
            'net_dollars' => (float) (string) ($row['net_dollars'] ?? 0),
        ];
    }

    private function builder(): GrossMarginV2SqlBuilder
    {
        return new GrossMarginV2SqlBuilder(
            $this->alias,
            config('duckdb.app_database.alias'),
        );
    }

    public function sql(): string
    {
        return (new GrossMarginV2SqlBuilder(
            $this->alias,
            config('duckdb.app_database.alias'),
        ))->build();
    }
}
