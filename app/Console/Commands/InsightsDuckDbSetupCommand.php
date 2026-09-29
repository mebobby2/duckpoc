<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Insights\DuckDB\AlloyDbInsightsImporter;
use App\Services\Insights\DuckDB\InsightsDuckDb;
use App\Services\Insights\DuckDB\InsightsDuckDbPracticeSeeder;
use App\Services\Insights\DuckDB\InsightsDuckDbSchema;
use Illuminate\Console\Command;
use Throwable;

class InsightsDuckDbSetupCommand extends Command
{
    protected $signature = 'insights:duckdb:setup
        {--fresh : Drop and recreate the MySQL tables and the lake table first}
        {--from-alloydb : Copy the AlloyDB PoC\'s practices, row for row}
        {--seed-practice= : Generate a practice in DuckDB with this id}
        {--farms=250 : Farms in the generated practice}
        {--first-farm= : Id of the generated practice\'s first farm; practices must not overlap}
        {--batch=50 : Farms per sorted lake insert; the sorted output is held in memory, ~15M lines at 50}
        {--lines-from= : Resume a stopped --seed-practice run: MySQL rows and earlier farms\' lines are in, carry on from this farm id}';

    protected $description = 'Load Insights into DuckDB: dimensions in MySQL, journal lines in DuckLake on MinIO';

    public function handle(): int
    {
        if (config('duckdb.storage') !== 's3') {
            $this->error('Run this from the app-minio container: the lake lives on MinIO.');

            return self::FAILURE;
        }

        $schema = new InsightsDuckDbSchema();

        try {
            $db = InsightsDuckDb::connect(writableMysql: true);
            $schema->applyWriteTuning($db);

            if ($this->option('fresh')) {
                $this->info('Recreating the MySQL tables and the lake table…');
                $schema->createMySql();
                $schema->createLake($db);
            }

            if ($this->option('from-alloydb')) {
                $this->info('Copying from AlloyDB…');
                $started = microtime(true);
                $counts = (new AlloyDbInsightsImporter($db))->import(fn (string $line) => $this->line('  '.$line));
                $this->line(sprintf('  copied %s journal lines in %.1f s', number_format($counts['transaction_lines']), microtime(true) - $started));
            }

            $practice = $this->option('seed-practice');
            if ($practice !== null) {
                $first = $this->option('first-farm') ?? throw new \InvalidArgumentException('--first-farm is required with --seed-practice.');
                $farms = (int) $this->option('farms');
                $this->info(sprintf('Seeding practice %d: %s farms from id %d…', (int) $practice, number_format($farms), (int) $first));
                $started = microtime(true);
                $counts = (new InsightsDuckDbPracticeSeeder($db, (int) $practice, (int) $first, $farms, (int) $this->option('batch')))
                    ->seed(
                        fn (string $line) => $this->line(sprintf('  [%6.1f s] %s', microtime(true) - $started, $line)),
                        $this->option('lines-from') === null ? null : (int) $this->option('lines-from'),
                    );
                $this->line(sprintf('  seeded in %.1f s', microtime(true) - $started));
                foreach ($counts as $what => $n) {
                    $this->line(sprintf('    %-20s %15s', $what, number_format($n)));
                }
            }
        } catch (Throwable $e) {
            $this->error('Setup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
