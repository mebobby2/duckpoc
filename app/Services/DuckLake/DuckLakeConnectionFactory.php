<?php

declare(strict_types=1);

namespace App\Services\DuckLake;

use RuntimeException;
use Saturio\DuckDB\DuckDB;

/**
 * Builds a DuckDB connection with the `ducklake` + `httpfs` extensions loaded,
 * a GCS secret configured, and the DuckLake catalog attached — ready to query.
 *
 * Verified hands-on against this exact stack (PHP 8.3 + ext-ffi + satur.io/duckdb
 * v2.2.0 + DuckDB v1.5.5): extension install/load and the plain-query path.
 * NOT yet verified live: the DuckLake ATTACH string shape for the postgres/mysql
 * catalog drivers (built from DuckLake's documented pattern, not executed against
 * a real Postgres/MySQL catalog in this environment) — check
 * https://ducklake.select/docs/stable/duckdb/usage/connecting if either errors.
 */
final class DuckLakeConnectionFactory
{
    public function __construct(
        private readonly array $config,
    ) {
    }

    public function connect(): DuckDB
    {
        $db = DuckDB::create();

        $this->ensureCatalogAndDataDirectoriesExist();
        $this->configureExtensionDirectory($db);
        $this->loadExtensions($db);
        $this->createGcsSecret($db);
        $this->attachCatalog($db);
        $this->attachAppDatabase($db);

        return $db;
    }

    /**
     * Attaches the application MySQL database alongside the lake, so one query
     * can join live dimension rows (accounts, farms) against Parquet fact data.
     *
     * READ_ONLY on purpose: reads come through DuckDB, writes go through
     * Eloquent. Without it a stray DuckDB write could bypass every model
     * event, validation and constraint the app relies on.
     */
    private function attachAppDatabase(DuckDB $db): void
    {
        $app = $this->config['app_database'] ?? null;

        if ($app === null || !$app['enabled']) {
            return;
        }

        $db->query('INSTALL mysql');
        $db->query('LOAD mysql');

        $dsn = sprintf(
            'host=%s port=%s user=%s password=%s database=%s',
            $app['host'],
            $app['port'],
            $app['username'],
            $app['password'],
            $app['database'],
        );

        $db->query(sprintf(
            "ATTACH IF NOT EXISTS '%s' AS %s (TYPE mysql, READ_ONLY)",
            $this->escape($dsn),
            $app['alias'],
        ));
    }

    /**
     * DuckLake does not create missing parent directories itself — attaching
     * a sqlite catalog path (or a local, non-gs:// DATA_PATH) whose directory
     * doesn't exist yet fails with an IO error rather than creating it.
     * Confirmed by actually running this against a fresh checkout.
     */
    private function ensureCatalogAndDataDirectoriesExist(): void
    {
        if ($this->config['catalog']['driver'] === 'sqlite') {
            $this->makeDirectoryFor($this->config['catalog']['connections']['sqlite']['path']);
        }

        if (empty($this->config['gcs']['bucket'])) {
            $this->makeDirectory(storage_path('ducklake/data/'));
        }
    }

    private function makeDirectoryFor(string $filePath): void
    {
        $this->makeDirectory(dirname($filePath));
    }

    private function makeDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create directory: {$directory}");
        }
    }

    private function configureExtensionDirectory(DuckDB $db): void
    {
        $directory = $this->config['extension_directory'];
        $this->makeDirectory($directory);

        $db->query(sprintf("SET extension_directory = '%s'", $this->escape($directory)));
    }

    private function loadExtensions(DuckDB $db): void
    {
        $db->query('INSTALL ducklake');
        $db->query('LOAD ducklake');
        $db->query('INSTALL httpfs');
        $db->query('LOAD httpfs');

        $this->tuneRemoteReads($db);
    }

    /**
     * Both off by default, both worth real time against a remote bucket.
     *
     * The cost of a remote read here is round trips, not bytes — a 1 KB object
     * and a 100 KB object fetch in the same time — and a large share of each
     * round trip is the TLS handshake, measured at ~200 ms and *identical*
     * whether the bucket is in Sydney or Iowa, because the handshake
     * terminates at a nearby anycast frontend either way.
     *
     * That is why moving the bucket ~11,000 km closer changed the report time
     * not at all (2,504 ms -> 2,535 ms) while raw single GETs did improve
     * (~500 ms -> ~300 ms): repeated handshakes dominated, and they are
     * region-independent. Reusing connections is what actually removes them.
     *
     * Measured on the oracle report, three cold runs each:
     *   connection caching off: 1,769 / 2,265 / 2,370 ms
     *   connection caching on:  1,436 / 1,325 /   997 ms
     *
     * `parquet_metadata_cache` and `enable_http_metadata_cache` are on too.
     * Neither showed a gain on a single cold read — by design, since both
     * cache across *repeated* reads within one process — but they cost
     * nothing on a cold path and pay off the moment a process serves more
     * than one query, which is exactly where this is headed with a
     * persistent instance.
     *
     * `mysql_pool_enable_thread_local_cache` covers the other half of the
     * federated join: dimension reads against the attached MySQL database.
     *
     * A caution learned the hard way here: single measurements against this
     * bucket vary by up to 4x, and `httpfs_connection_caching` was wrongly
     * written off on one sample before three runs showed it was the largest
     * lever available. Take medians of several cold runs before believing
     * any of these numbers, including these.
     */
    private function tuneRemoteReads(DuckDB $db): void
    {
        // Fetch larger ranges in fewer requests.
        $db->query('SET prefetch_all_parquet_files = true');

        // Reuse TLS connections — the ~200 ms handshake is the single biggest
        // component of a remote read, and it is region-independent.
        $db->query('SET httpfs_connection_caching = true');

        // Avoid re-reading Parquet footers and HTTP metadata on repeat reads.
        $db->query('SET parquet_metadata_cache = true');
        $db->query('SET enable_http_metadata_cache = true');

        // The dimension side of the federated join.
        $db->query('SET mysql_pool_enable_thread_local_cache = true');
    }

    private function createGcsSecret(DuckDB $db): void
    {
        $gcs = $this->config['gcs'];

        if (empty($gcs['key_id']) || empty($gcs['secret'])) {
            // No credentials configured — fine for a purely local run (e.g. a
            // sqlite catalog with a local DATA_PATH instead of gs://), but any
            // gs:// path will fail auth without this.
            return;
        }

        $db->query(sprintf(
            "CREATE OR REPLACE SECRET gcs_secret (TYPE gcs, KEY_ID '%s', SECRET '%s')",
            $this->escape($gcs['key_id']),
            $this->escape($gcs['secret']),
        ));
    }

    private function attachCatalog(DuckDB $db): void
    {
        $alias = $this->config['attached_alias'];
        $attachTarget = $this->catalogAttachString();
        $dataPath = $this->dataPath();

        $db->query(sprintf(
            "ATTACH '%s' AS %s (DATA_PATH '%s')",
            $this->escape($attachTarget),
            $alias,
            $this->escape($dataPath),
        ));
    }

    private function catalogAttachString(): string
    {
        $driver = $this->config['catalog']['driver'];
        $connection = $this->config['catalog']['connections'][$driver]
            ?? throw new RuntimeException("Unknown DuckLake catalog driver: {$driver}");

        return match ($driver) {
            'sqlite' => 'ducklake:'.$connection['path'],

            'postgres' => sprintf(
                'ducklake:postgres:dbname=%s host=%s port=%s user=%s password=%s',
                $connection['database'],
                $connection['host'],
                $connection['port'],
                $connection['username'],
                $connection['password'],
            ),

            // Known-issue backend — see config/duckdb.php docblock. Kept for
            // deliberate testing, not the default.
            'mysql' => sprintf(
                'ducklake:mysql:db=%s host=%s port=%s user=%s password=%s',
                $connection['database'],
                $connection['host'],
                $connection['port'],
                $connection['username'],
                $connection['password'],
            ),

            default => throw new RuntimeException("Unsupported DuckLake catalog driver: {$driver}"),
        };
    }

    private function dataPath(): string
    {
        $gcs = $this->config['gcs'];

        if (empty($gcs['bucket'])) {
            // Local fallback — writes Parquet under storage/ instead of GCS.
            // Useful for smoke-testing the catalog/attach mechanics without
            // any cloud credentials at all.
            return storage_path('ducklake/data/');
        }

        return sprintf('gs://%s/%s', $gcs['bucket'], ltrim($gcs['data_path_prefix'], '/'));
    }

    private function escape(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
