<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Insights\DuckDB\AlloyDbInsightsImporter;
use App\Services\Insights\DuckDB\InsightsDuckDb;
use App\Services\Insights\DuckDB\InsightsDuckDbSchema;
use Illuminate\Console\Command;
use Throwable;

class InsightsDuckDbSetupCommand extends Command
{
    protected $signature = 'insights:duckdb:setup
        {--fresh : Drop and recreate the MySQL tables and the lake table first}
        {--from-alloydb : Copy the AlloyDB PoC\'s practices, row for row}';

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
        } catch (Throwable $e) {
            $this->error('Setup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
