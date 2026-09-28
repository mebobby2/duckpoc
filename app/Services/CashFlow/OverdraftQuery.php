<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Saturio\DuckDB\DuckDB;
use Saturio\DuckDB\Type\Type;

/**
 * Runs the overdraft interest statement.
 *
 * Thin by design: the recurrence lives in `OverdraftSqlBuilder` so it can be
 * read, diffed against Figured's PHP, and tested without a database.
 */
final class OverdraftQuery
{
    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
        private readonly string $appAlias,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function run(string $farmId, string $periodFrom, string $periodTo, string $horizon): array
    {
        $statement = $this->db->preparedStatement($this->sql());
        $this->bindScope($statement, $farmId, $periodFrom, $periodTo, $horizon);

        return iterator_to_array($statement->execute()->rows(true));
    }

    /**
     * @return list<array<string, mixed>>
     */
    /** @param list<string> $farmIds a reporting group's child entities; empty for `$farmId` alone */
    public function sourceRows(string $farmId, string $periodFrom, string $periodTo, string $horizon, int $limit, array $farmIds = []): array
    {
        $statement = $this->db->preparedStatement($this->builder()->buildSourceRowsSql($farmIds));
        $this->bindScope($statement, $farmId, $periodFrom, $periodTo, $horizon, bindFarm: $farmIds === []);
        $statement->bindParam('row_limit', $limit, Type::DUCKDB_TYPE_BIGINT);

        return iterator_to_array($statement->execute()->rows(true));
    }

    /**
     * @return array<string, mixed>
     */
    /** @param list<string> $farmIds a reporting group's child entities; empty for `$farmId` alone */
    public function sourceSummary(string $farmId, string $periodFrom, string $periodTo, string $horizon, array $farmIds = []): array
    {
        $statement = $this->db->preparedStatement($this->builder()->buildSourceSummarySql($farmIds));
        $this->bindScope($statement, $farmId, $periodFrom, $periodTo, $horizon, bindFarm: $farmIds === []);

        foreach ($statement->execute()->rows(true) as $row) {
            return (array) $row;
        }

        return [];
    }

    public function sql(): string
    {
        return $this->builder()->build();
    }

    private function builder(): OverdraftSqlBuilder
    {
        return new OverdraftSqlBuilder($this->alias, $this->appAlias);
    }

    /**
     * `$bindFarm` is false when the statement names a reporting group's
     * entities inline: DuckDB refuses to bind a parameter the SQL lacks.
     */
    private function bindScope(object $statement, string $farmId, string $periodFrom, string $periodTo, string $horizon, bool $bindFarm = true): void
    {
        $params = ['period_from' => $periodFrom, 'period_to' => $periodTo, 'horizon' => $horizon];
        if ($bindFarm) {
            $params = ['farm_id' => $farmId] + $params;
        }

        foreach ($params as $parameter => $value) {
            $statement->bindParam($parameter, $value, Type::DUCKDB_TYPE_VARCHAR);
        }
    }
}
