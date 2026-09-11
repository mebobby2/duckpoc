<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AlloyDb\AlloyDbSchema;
use App\Services\AlloyDb\AlloyDbSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class AlloyDbSetupCommand extends Command
{
    protected $signature = 'alloydb:setup
        {--farm=gm-dairy-farm : Farm to load, copied from MySQL and regenerated in Postgres}
        {--rows=0 : Target journal lines (0 = demo scale, one line per account-month)}
        {--fresh : Recreate the schema, dropping whatever is there}
        {--columnar : Populate the in-memory columnar engine after loading}';

    protected $description = 'Load the AlloyDB comparison: schema, data equivalent to DuckLake, and optionally the columnar engine';

    public function handle(): int
    {
        $connection = DB::connection('alloydb');
        $farmId = (string) $this->option('farm');
        $rows = max(0, (int) $this->option('rows'));

        $schema = new AlloyDbSchema($connection);

        if ($this->option('fresh')) {
            $this->info('Recreating schema…');
            $schema->create();
        }

        $this->info(sprintf(
            'Loading %s%s',
            $farmId,
            $rows > 0 ? sprintf(' — target %s journal lines', number_format($rows)) : ' — demo scale',
        ));

        $started = microtime(true);

        try {
            $result = (new AlloyDbSeeder($connection, $farmId, $rows))->seed();
        } catch (Throwable $e) {
            $this->error('Load failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            '  loaded %s fact rows (+%s dimension rows) in %.1fs',
            number_format($result['fact_rows']),
            number_format($result['dimension_rows']),
            microtime(true) - $started,
        ));

        $this->info('Indexing and analysing…');
        $started = microtime(true);
        $schema->index();
        $this->line(sprintf('  done in %.1fs', microtime(true) - $started));

        if ($this->option('columnar')) {
            $this->info('Populating the columnar engine…');
            $started = microtime(true);
            $columns = $schema->columnarize();
            $this->line(sprintf('  done in %.1fs', microtime(true) - $started));

            foreach ($columns as $column) {
                $this->line(sprintf(
                    '    %-12s %-10s %s',
                    $column['column_name'] ?? '?',
                    $column['in_memory'] ?? '?',
                    $column['status'] ?? '',
                ));
            }

            $budget = $connection->selectOne(
                "SELECT setting FROM pg_settings WHERE name = 'google_columnar_engine.memory_size_in_mb'"
            );
            $this->line(sprintf('  column store budget: %s MB', $budget->setting ?? '?'));
        }

        return self::SUCCESS;
    }
}
