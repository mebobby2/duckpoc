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

        // Everything as VARCHAR, CAST in the SQL. Binding a date string as
        // DUCKDB_TYPE_DATE fails inside the FFI driver's conversion; the other
        // queries here do the same.
        foreach ([
            'farm_id' => $farmId,
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
        return (new OverdraftSqlBuilder($this->alias, $this->appAlias))->build();
    }
}
