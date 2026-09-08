<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashFlow\CashFlowSchema;
use App\Services\CashFlow\HeroTrackerSeeder;
use Illuminate\Console\Command;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Seeds the hero stress-test farm: one farm, 50 trackers, up to a billion
 * journal lines.
 *
 * Defaults to 10M so an accidental run is cheap. The billion-row run has to be
 * asked for explicitly.
 */
class DuckDbHeroSeedCommand extends Command
{
    protected $signature = 'duckdb:hero:seed
        {--rows=10000000 : Total journal lines to write}
        {--chunk=25000000 : Rows per INSERT}';

    protected $description = 'Seed the hero stress-test farm (50 trackers, configurable row count)';

    public function handle(DuckDB $db): int
    {
        $totalRows = (int) $this->option('rows');
        $chunkRows = min((int) $this->option('chunk'), $totalRows);

        if ($totalRows < 1 || $chunkRows < 1) {
            $this->error('--rows and --chunk must both be positive.');

            return self::FAILURE;
        }

        $alias = config('duckdb.attached_alias');
        $appAlias = config('duckdb.app_database.alias');

        $schema = new CashFlowSchema($db, $alias);
        $schema->ensureWriteOptions();
        $schema->addTrackerColumn();

        $seeder = new HeroTrackerSeeder($db, $alias, $appAlias);
        $seeder->ensureAccounts();

        // The seeder plans one insert per year (split further if a year
        // exceeds --chunk), so the insert count is not simply rows/chunk —
        // it is at least one per year. Asking the seeder avoids a header that
        // disagrees with the progress lines below it.
        $chunks = $seeder->plannedChunkCount($totalRows, $chunkRows);

        $this->info(sprintf(
            'Seeding %s to %s — %s rows in %d chunk(s) of %s, %d trackers',
            number_format($totalRows),
            HeroTrackerSeeder::FARM_ID,
            number_format($totalRows),
            $chunks,
            number_format($chunkRows),
            HeroTrackerSeeder::TRACKER_COUNT,
        ));
        $this->line('');

        try {
            $seeder->seed(
                $totalRows,
                $chunkRows,
                function (int $chunk, int $chunkCount, int $written, float $elapsed) use ($totalRows): void {
                    $rate = $elapsed > 0 ? $written / $elapsed : 0;
                    $remaining = $rate > 0 ? ($totalRows - $written) / $rate : 0;

                    $this->line(sprintf(
                        '    chunk %3d/%-3d  %14s rows  %6.0f s elapsed  %s rows/s  eta %s',
                        $chunk,
                        $chunkCount,
                        number_format($written),
                        $elapsed,
                        number_format($rate),
                        $this->humanSeconds($remaining),
                    ));
                }
            );
        } catch (Throwable $e) {
            $this->error('Seed failed: '.$e->getMessage());
            $this->line('');
            $this->line('Rows written before the failure are still in the lake —');
            $this->line('re-running starts over, since the seeder clears this farm first.');

            return self::FAILURE;
        }

        $this->line('');
        $this->info('✔ Seeded.');

        $this->reportLayout($db, $alias);

        return self::SUCCESS;
    }

    private function reportLayout(DuckDB $db, string $alias): void
    {
        $farmId = HeroTrackerSeeder::FARM_ID;

        try {
            $sql = <<<SQL
                SELECT
                    CAST(count(*) AS BIGINT) AS n,
                    CAST(count(tracker_id) AS BIGINT) AS tagged,
                    CAST(count(DISTINCT tracker_id) AS BIGINT) AS trackers,
                    min(date) AS lo,
                    max(date) AS hi
                FROM {$alias}.transaction_lines
                WHERE farm_id = '{$farmId}'
                SQL;

            foreach ($db->query($sql)->rows(true) as $row) {
                $this->line('');
                $this->line(sprintf('    journals       : %s', number_format((int) (string) $row['n'])));
                $this->line(sprintf(
                    '    tracker-tagged : %s across %s trackers',
                    number_format((int) (string) $row['tagged']),
                    (string) $row['trackers'],
                ));
                $this->line(sprintf('    date span      : %s .. %s', $row['lo'], $row['hi']));
            }

            $files = <<<SQL
                SELECT
                    CAST(count(*) AS BIGINT) AS f,
                    CAST(sum(data_file_size_bytes) AS BIGINT) AS b
                FROM ducklake_list_files('{$alias}', 'transaction_lines')
                SQL;

            foreach ($db->query($files)->rows(true) as $row) {
                $this->line(sprintf(
                    '    lake total     : %s parquet file(s), %.2f GB (all farms)',
                    number_format((int) (string) $row['f']),
                    ((int) (string) $row['b']) / 1024 / 1024 / 1024,
                ));
            }
        } catch (Throwable $e) {
            $this->warn('    could not summarise layout: '.$e->getMessage());
        }
    }

    private function humanSeconds(float $seconds): string
    {
        if ($seconds < 90) {
            return sprintf('%ds', (int) $seconds);
        }

        if ($seconds < 5400) {
            return sprintf('%.0fm', $seconds / 60);
        }

        return sprintf('%.1fh', $seconds / 3600);
    }
}
