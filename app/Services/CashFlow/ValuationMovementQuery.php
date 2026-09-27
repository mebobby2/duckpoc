<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;
use Saturio\DuckDB\Type\Type;

/**
 * Runs the livestock valuation movement statement.
 *
 * Thin by design: the window functions live in `ValuationMovementSqlBuilder`
 * so they can be read, diffed against Figured's PHP, and tested without a
 * database.
 */
final class ValuationMovementQuery
{
    public function __construct(
        private readonly DuckDB $db,
        private readonly string $appAlias,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function run(string $farmId, string $periodFrom, string $periodTo, string $horizon): array
    {
        return $this->select($this->builder()->buildSql($this->trackerIds($farmId)), $farmId, $periodFrom, $periodTo, $horizon);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function totals(string $farmId, string $periodFrom, string $periodTo, string $horizon): array
    {
        return $this->select($this->builder()->buildTotalsSql($this->trackerIds($farmId)), $farmId, $periodFrom, $periodTo, $horizon);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sourceRows(string $farmId, string $periodFrom, string $periodTo, string $horizon, int $limit): array
    {
        $statement = $this->db->preparedStatement($this->builder()->buildSourceRowsSql($this->trackerIds($farmId)));
        $this->bindScope($statement, $farmId, $periodFrom, $periodTo, $horizon);
        $statement->bindParam('row_limit', $limit, Type::DUCKDB_TYPE_BIGINT);

        return iterator_to_array($statement->execute()->rows(true));
    }

    /**
     * @return array<string, mixed>
     */
    public function sourceSummary(string $farmId, string $periodFrom, string $periodTo, string $horizon): array
    {
        foreach ($this->select($this->builder()->buildSourceSummarySql($this->trackerIds($farmId)), $farmId, $periodFrom, $periodTo, $horizon) as $row) {
            return (array) $row;
        }

        return [];
    }

    public function sql(string $farmId = ''): string
    {
        return $this->builder()->buildSql($farmId === '' ? [] : $this->trackerIds($farmId));
    }

    /**
     * The farm's tracker ids, resolved before the statement is built.
     *
     * Cheap — a handful of rows off an indexed dimension table — and it is
     * what lets the movement and rate scans carry a predicate MySQL can
     * actually use. See `ValuationMovementSqlBuilder::trackerPredicate()`.
     *
     * @return list<string>
     */
    private function trackerIds(string $farmId): array
    {
        return array_map(
            static fn ($id): string => (string) $id,
            DB::table('trackers')->where('farm_id', $farmId)->pluck('tracker_id')->all(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function select(string $sql, string $farmId, string $periodFrom, string $periodTo, string $horizon): array
    {
        $statement = $this->db->preparedStatement($sql);
        $this->bindScope($statement, $farmId, $periodFrom, $periodTo, $horizon);

        return iterator_to_array($statement->execute()->rows(true));
    }

    private function builder(): ValuationMovementSqlBuilder
    {
        return new ValuationMovementSqlBuilder($this->appAlias);
    }

    private function bindScope(object $statement, string $farmId, string $periodFrom, string $periodTo, string $horizon): void
    {
        foreach ([
            'farm_id' => $farmId,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'horizon' => $horizon,
        ] as $parameter => $value) {
            $statement->bindParam($parameter, $value, Type::DUCKDB_TYPE_VARCHAR);
        }
    }
}
