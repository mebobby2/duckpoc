<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DuckLake\DuckLakeConnectionFactory;
use Illuminate\Console\Command;
use Throwable;

/**
 * End-to-end smoke test for the DuckDB + DuckLake + GCS chain: extension
 * loading, catalog attach, and a real write + read round trip through the
 * attached DuckLake table. Run this first after any config change before
 * trusting anything built on top of it.
 */
class DuckDbTestCommand extends Command
{
    protected $signature = 'duckdb:test';

    protected $description = 'Smoke-test the DuckDB + DuckLake + GCS connection end to end';

    public function handle(DuckLakeConnectionFactory $factory): int
    {
        $this->info('Connecting and loading extensions (ducklake, httpfs)...');

        try {
            $db = $factory->connect();
        } catch (Throwable $e) {
            $this->error('Failed during connect/attach: '.$e->getMessage());
            $this->line('');
            $this->warn('Common causes:');
            $this->line('  - GCS_KEY_ID / GCS_SECRET missing or wrong (needs HMAC keys, not a service-account JSON)');
            $this->line('  - GCS_BUCKET does not exist or the HMAC key lacks access to it');
            $this->line('  - DUCKLAKE_CATALOG_DRIVER=mysql or postgres pointing at an unreachable host');

            return self::FAILURE;
        }

        $this->info('✔ Connected, extensions loaded, catalog attached.');

        $version = $db->query('SELECT version() AS v')->rows(true)->current()['v'] ?? null;
        $this->line("  DuckDB version: {$version}");

        $alias = config('duckdb.attached_alias');

        $this->info('Running a write + read round trip through the attached DuckLake catalog...');

        try {
            $db->query("CREATE TABLE IF NOT EXISTS {$alias}.duckdb_smoke_test (id INTEGER, checked_at TIMESTAMP)");
            $db->query("INSERT INTO {$alias}.duckdb_smoke_test VALUES (1, now())");

            $rows = iterator_to_array($db->query(
                "SELECT count(*) AS n FROM {$alias}.duckdb_smoke_test"
            )->rows(true));

            $count = $rows[0]['n'] ?? null;
        } catch (Throwable $e) {
            $this->error('Failed during write/read round trip: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("✔ Round trip succeeded — duckdb_smoke_test now has {$count} row(s).");
        $this->line('');
        $this->info('DuckDB + DuckLake + GCS stack is working end to end.');

        return self::SUCCESS;
    }
}
