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

        return $db;
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
