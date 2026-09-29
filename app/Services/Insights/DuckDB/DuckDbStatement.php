<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use Saturio\DuckDB\DuckDB;
use Saturio\DuckDB\Type\Type;

/**
 * Runs a statement with named `$parameters`, bound as text and cast in the
 * SQL, and returns plain PHP rows: DuckDB hands values back as objects, so
 * every value comes out as a string or null for the caller to type.
 */
final class DuckDbStatement
{
    /**
     * @param array<string, string> $bindings
     * @return list<array<string, ?string>>
     */
    public static function rows(DuckDB $db, string $sql, array $bindings = []): array
    {
        $statement = $db->preparedStatement($sql);
        foreach ($bindings as $name => $value) {
            $statement->bindParam($name, $value, Type::DUCKDB_TYPE_VARCHAR);
        }

        $rows = [];
        foreach ($statement->execute()->rows(true) as $row) {
            $rows[] = array_map(static fn (mixed $v): ?string => $v === null ? null : (string) $v, $row);
        }

        return $rows;
    }
}
