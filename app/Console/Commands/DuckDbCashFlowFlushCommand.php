<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Flushes DuckLake's inlined data out to Parquet.
 *
 * DuckLake sends small inserts (10 rows or fewer, by default) to the catalog
 * database instead of writing a Parquet file per insert — deliberately, so
 * transactional-scale writes don't each pay a round trip to object storage.
 * Nothing flushes them afterwards on its own: there is no background
 * compaction and no timer. Inlined rows become Parquet when a single insert
 * clears the threshold, or when this runs.
 *
 * Queries are correct either way — DuckLake reads inlined rows and Parquet as
 * one table — so a report can pass every assertion while the bucket is still
 * empty. That makes this command the thing that actually proves the storage
 * layer: partition pruning and row-group statistics need files to prune.
 */
class DuckDbCashFlowFlushCommand extends Command
{
    protected $signature = 'duckdb:cashflow:flush';

    protected $description = 'Flush DuckLake inlined data to Parquet and report the resulting files';

    /** Tables this PoC writes; flushed individually so the output is legible. */
    private const array TABLES = ['transaction_lines'];

    public function handle(DuckDB $db): int
    {
        $alias = config('duckdb.attached_alias');

        $this->line('Before flush:');
        $before = $this->fileCounts($db, $alias);

        $this->info('Flushing inlined data...');

        try {
            // Flushes the whole catalog; the per-table listing below is what
            // makes the effect visible.
            $db->query("CALL ducklake_flush_inlined_data('{$alias}')");
        } catch (Throwable $e) {
            $this->error('Flush failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->line('After flush:');
        $after = $this->fileCounts($db, $alias);

        $created = array_sum($after) - array_sum($before);

        $this->line('');

        if ($created <= 0) {
            $this->warn('No new Parquet files were created.');
            $this->line('  Either there was nothing inlined to flush, or the rows had already');
            $this->line('  been written as Parquet by an insert that cleared the threshold.');

            return self::SUCCESS;
        }

        $this->info("✔ {$created} Parquet file(s) written.");
        $this->line('');
        $this->line('Verify the partition layout in the bucket with:');
        $this->line('    gcloud storage ls -r "gs://<bucket>/<prefix>/main/transaction_lines/"');

        return self::SUCCESS;
    }

    /**
     * Prints, and returns, the registered Parquet file count per table.
     *
     * @return array<string, int>
     */
    private function fileCounts(DuckDB $db, string $alias): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            try {
                $files = iterator_to_array(
                    $db->query("SELECT data_file FROM ducklake_list_files('{$alias}', '{$table}')")->rows(true)
                );
            } catch (Throwable $e) {
                $this->error("    {$table}: could not list files — ".$e->getMessage());
                $counts[$table] = 0;

                continue;
            }

            $counts[$table] = count($files);

            $this->line(sprintf('    %-20s %d parquet file(s)', $table, count($files)));

            foreach ($files as $file) {
                // The partition path is the point of interest — it shows
                // whether (farm_type, region, year) actually took effect.
                $this->line('        '.$this->partitionPathOf((string) $file['data_file']));
            }
        }

        return $counts;
    }

    /**
     * Trims the bucket/prefix so the partition directories are readable at a
     * glance, keeping the full path recoverable from the bucket listing.
     */
    private function partitionPathOf(string $dataFile): string
    {
        $marker = '/main/';
        $position = strpos($dataFile, $marker);

        return $position === false
            ? $dataFile
            : '…'.substr($dataFile, $position);
    }
}
