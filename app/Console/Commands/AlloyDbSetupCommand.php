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
        {--farm=gm-dairy-farm : Farm to load}
        {--region=gm-waikato : Region recorded on the farm}
        {--rows=0 : Target journal lines (0 = demo scale, one line per account-month)}
        {--fresh : Recreate the schema, dropping whatever is there}
        {--raw : Also emit the GST, payable and bank lines a real chart of accounts carries (~3.5x the rows)}
        {--columnar : Populate the in-memory columnar engine after loading}';

    protected $description = 'Load a farm into AlloyDB: schema, dimensions, journals, and optionally the columnar engine';

    public function handle(): int
    {
        $connection = DB::connection('alloydb');
        $farmId = (string) $this->option('farm');
        $region = (string) $this->option('region');
        $rows = max(0, (int) $this->option('rows'));

        $schema = new AlloyDbSchema($connection);

        if ($this->option('fresh')) {
            $this->info('Recreating schema…');
            $schema->create();
        }

        $this->info(sprintf(
            'Loading %s%s — everything in Postgres, nothing read from MySQL',
            $farmId,
            $rows > 0 ? sprintf(
                ' — target %s report lines%s',
                number_format($rows),
                $this->option('raw') ? sprintf(' (~%s rows with bookkeeping legs)', number_format((int) ($rows * 3.5))) : '',
            ) : ' — demo scale',
        ));

        $started = microtime(true);

        // Only for bulk loads. Dropping the index needs an ACCESS EXCLUSIVE lock
        // on the whole table, which the columnar engine's background rebuild
        // blocks — a 620-row demo seed sat waiting on that lock for ten minutes
        // and looked like a hang. At bulk scale the drop is still worth it,
        // because maintaining a btree per inserted row costs far more than one
        // sorted build afterwards.
        $bulkLoad = $rows >= 1_000_000;

        if ($bulkLoad) {
            $this->line('  dropping the index for the load…');
            $schema->dropIndex();
        }

        try {
            $result = (new AlloyDbSeeder($connection, $farmId, $region, $rows, (bool) $this->option('raw')))->seed();
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

        $this->info($bulkLoad ? 'Rebuilding the index and analysing…' : 'Analysing…');
        $started = microtime(true);
        $schema->index();
        $this->line(sprintf('  done in %.1fs', microtime(true) - $started));

        if ($this->option('columnar')) {
            $this->info('Populating the columnar engine…');
            $started = microtime(true);
            $columns = $schema->columnarize(forceRefresh: $bulkLoad);
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
