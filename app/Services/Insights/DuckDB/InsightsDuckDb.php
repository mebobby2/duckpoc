<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\DuckLake\DuckLakeConnectionFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;

/**
 * Where the DuckDB Insights data lives, and how to reach it.
 *
 * The split the PoC tests: journal lines are the only thing in the lake
 * (DuckLake on MinIO, table `lake.insights.transaction_lines`); farms, the
 * chart of accounts, categories, milk and livestock stay in MySQL, in an
 * `insights` database shaped like Figured's own, attached to DuckDB as
 * `insightsdb` so a report joins the two in one statement.
 */
final class InsightsDuckDb
{
    public const string LAKE_SCHEMA = 'insights';
    public const string MYSQL_ALIAS = 'insightsdb';
    public const string MYSQL_DATABASE = 'insights';
    public const string MYSQL_CONNECTION = 'insights_mysql';

    /** The lake table every report scans. */
    public static function lines(): string
    {
        return config('duckdb.attached_alias').'.'.self::LAKE_SCHEMA.'.transaction_lines';
    }

    /** A MySQL table as DuckDB sees it through the attached database. */
    public static function table(string $name): string
    {
        return self::MYSQL_ALIAS.'.'.$name;
    }

    /**
     * A DuckDB with the lake and the Insights MySQL database attached.
     * Reports attach MySQL read-only; the seeder and the importer need to
     * write through it.
     */
    public static function connect(bool $writableMysql = false): DuckDB
    {
        $db = (new DuckLakeConnectionFactory(config('duckdb')))->connect();

        $c = config('duckdb.app_database');
        $dsn = sprintf('host=%s port=%s user=%s password=%s database=%s', $c['host'], $c['port'], $c['username'], $c['password'], self::MYSQL_DATABASE);
        $db->query(sprintf(
            "ATTACH IF NOT EXISTS '%s' AS %s (TYPE mysql%s)",
            str_replace("'", "''", $dsn),
            self::MYSQL_ALIAS,
            $writableMysql ? '' : ', READ_ONLY',
        ));

        return $db;
    }

    /** Laravel's own connection to the Insights MySQL database, for DDL and small reads. */
    public static function mysql(): ConnectionInterface
    {
        if (config('database.connections.'.self::MYSQL_CONNECTION) === null) {
            config(['database.connections.'.self::MYSQL_CONNECTION => array_merge(
                config('database.connections.mysql'),
                ['database' => self::MYSQL_DATABASE],
            )]);
        }

        return DB::connection(self::MYSQL_CONNECTION);
    }
}
