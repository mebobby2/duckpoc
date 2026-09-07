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
        $table = 'duckdb_smoke_test';

        $this->info('Running a write + read round trip through the attached DuckLake catalog...');

        try {
            $db->query("CREATE TABLE IF NOT EXISTS {$alias}.{$table} (id INTEGER, checked_at TIMESTAMP)");

            // DuckLake inlines small inserts (default threshold: 10 rows)
            // straight into the catalog database rather than writing a
            // Parquet file — by design, so tiny transactional writes don't
            // each force a round trip to GCS. A single-row INSERT is exactly
            // this case: it lands in the catalog, but no Parquet file
            // appears in the bucket until enough data accumulates or
            // ducklake_flush_inlined_data() is called.
            //
            // 20 rows in ONE insert deliberately clears that threshold, so
            // this write bypasses inlining and goes straight to a real
            // Parquet file.
            $db->query(<<<SQL
                INSERT INTO {$alias}.{$table} (id, checked_at)
                SELECT i, now() FROM range(1, 21) AS t(i)
                SQL);

            $catalogRows = iterator_to_array($db->query(
                "SELECT count(*) AS n FROM {$alias}.{$table}"
            )->rows(true));

            $catalogCount = $catalogRows[0]['n'] ?? null;
        } catch (Throwable $e) {
            $this->error('Failed during write/read round trip: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("✔ Catalog reports {$catalogCount} row(s) in {$table}.");
        $this->line('  (This alone does NOT prove a real Parquet file was written — DuckLake');
        $this->line('   inlining could satisfy this count from the catalog database alone.)');
        $this->line('');

        // The actual proof: ask DuckLake which physical data files it has
        // registered for this table, then read each one back directly with
        // read_parquet() — bypassing the catalog entirely — to confirm real
        // file I/O against the configured DATA_PATH (local disk or GCS)
        // actually happened, not just a catalog-level row count.
        $this->info('Verifying real Parquet file(s) exist at the configured DATA_PATH...');

        try {
            $files = iterator_to_array($db->query(
                "SELECT data_file FROM ducklake_list_files('{$alias}', '{$table}')"
            )->rows(true));
        } catch (Throwable $e) {
            $this->error('Failed to query ducklake_list_files(): '.$e->getMessage());

            return self::FAILURE;
        }

        if (empty($files)) {
            $this->error('✘ No Parquet files are registered for this table.');
            $this->line('  All rows are still inlined in the catalog database — nothing has been');
            $this->line('  written to the DATA_PATH yet. This does NOT confirm GCS/local file writes');
            $this->line('  are working. Either insert more rows in one statement (>10, the default');
            $this->line('  inline threshold) or call ducklake_flush_inlined_data(\''.$alias.'\').');

            return self::FAILURE;
        }

        $this->info('✔ Found '.count($files)." real Parquet file(s) registered for {$table}:");
        foreach ($files as $file) {
            $this->line("    {$file['data_file']}");
        }

        $parquetRowCount = 0;

        try {
            foreach ($files as $file) {
                $escapedPath = str_replace("'", "''", $file['data_file']);

                $result = iterator_to_array($db->query(
                    "SELECT count(*) AS n FROM read_parquet('{$escapedPath}')"
                )->rows(true));

                $parquetRowCount += (int) ($result[0]['n'] ?? 0);
            }
        } catch (Throwable $e) {
            $this->error('Failed reading a Parquet file back directly: '.$e->getMessage());

            return self::FAILURE;
        }

        $inlinedCount = (int) $catalogCount - $parquetRowCount;

        $this->line('');
        $this->info("✔ Read {$parquetRowCount} row(s) directly back from those Parquet file(s),");
        $this->info('  bypassing the DuckLake catalog entirely — this is the real proof.');

        if ($inlinedCount > 0) {
            $this->line("  ({$inlinedCount} additional row(s) still sitting inlined in the catalog —");
            $this->line('   expected if earlier runs inserted fewer than the inline threshold.)');
        }

        $this->line('');
        $this->info('DuckDB + DuckLake + '.(empty(config('duckdb.gcs.bucket')) ? 'local storage' : 'GCS').' stack is verified end to end.');

        return self::SUCCESS;
    }
}
