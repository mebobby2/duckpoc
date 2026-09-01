<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | DuckLake catalog
    |--------------------------------------------------------------------------
    |
    | DuckLake's own documentation recommends SQLite/DuckDB for local, single-
    | writer PoCs and Postgres for a production, multi-user catalog. There is
    | currently a known, open bug with MySQL as a DuckLake catalog backend
    | (https://github.com/duckdb/ducklake/issues/214), so MySQL is deliberately
    | NOT the default here even though this app also runs a MySQL service for
    | its own Laravel-side config data (matching Figured's own MySQL usage).
    |
    | Switch `driver` to `postgres` or `mysql` only if you're deliberately
    | testing that combination — see connections below.
    |
    */
    'catalog' => [
        'driver' => env('DUCKLAKE_CATALOG_DRIVER', 'sqlite'),

        'connections' => [
            'sqlite' => [
                // A local file catalog — DuckLake manages this as a DuckDB/SQLite
                // database file. Fine for a single-process PoC; not for concurrent
                // multi-writer access.
                'path' => env('DUCKLAKE_CATALOG_SQLITE_PATH', storage_path('ducklake/catalog.sqlite')),
            ],

            'postgres' => [
                'host' => env('DUCKLAKE_CATALOG_PG_HOST', '127.0.0.1'),
                'port' => env('DUCKLAKE_CATALOG_PG_PORT', 5432),
                'database' => env('DUCKLAKE_CATALOG_PG_DATABASE', 'ducklake_catalog'),
                'username' => env('DUCKLAKE_CATALOG_PG_USERNAME', 'postgres'),
                'password' => env('DUCKLAKE_CATALOG_PG_PASSWORD', ''),
            ],

            // Included for completeness / deliberate testing only — see the
            // known-issue warning above.
            'mysql' => [
                'host' => env('DUCKLAKE_CATALOG_MYSQL_HOST', '127.0.0.1'),
                'port' => env('DUCKLAKE_CATALOG_MYSQL_PORT', 3306),
                'database' => env('DUCKLAKE_CATALOG_MYSQL_DATABASE', 'ducklake_catalog'),
                'username' => env('DUCKLAKE_CATALOG_MYSQL_USERNAME', 'root'),
                'password' => env('DUCKLAKE_CATALOG_MYSQL_PASSWORD', ''),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | DuckLake data path (GCS)
    |--------------------------------------------------------------------------
    |
    | Where DuckLake writes/reads the actual Parquet data files. Authenticated
    | via the S3-compatibility layer DuckDB's httpfs extension uses for GCS —
    | this requires HMAC keys (Cloud Storage > Settings > Interoperability in
    | the GCP console), NOT a service-account JSON key. Some GCP org policies
    | disable HMAC key creation by default; if yours does, you'll need that
    | policy exception before this will authenticate.
    |
    */
    'gcs' => [
        'bucket' => env('GCS_BUCKET'),
        'data_path_prefix' => env('GCS_DATA_PATH_PREFIX', 'duckpoc/'),
        'key_id' => env('GCS_KEY_ID'),
        'secret' => env('GCS_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local extension/scratch storage
    |--------------------------------------------------------------------------
    |
    | DuckDB defaults to installing extensions (ducklake, httpfs) under the
    | process's home directory, which isn't reliably writable in a container
    | running as a non-root uid. Pin it to a path inside Laravel's own storage
    | directory instead.
    |
    */
    'extension_directory' => env('DUCKDB_EXTENSION_DIRECTORY', storage_path('duckdb/extensions')),

    /*
    |--------------------------------------------------------------------------
    | Name of the attached DuckLake database, as referenced in SQL
    |--------------------------------------------------------------------------
    */
    'attached_alias' => env('DUCKLAKE_ALIAS', 'lake'),

];
