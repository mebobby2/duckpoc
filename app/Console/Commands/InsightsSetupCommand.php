<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Insights\InsightsPracticeSeeder;
use App\Services\Insights\InsightsSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class InsightsSetupCommand extends Command
{
    protected $signature = 'insights:setup
        {--practice=1 : Practice id to seed}
        {--farms=250 : Farms in the practice}
        {--first-farm=1 : Id of the practice\'s first farm; practices must not overlap}
        {--fresh : Drop and recreate the insights schema first}
        {--columnar : Populate the column store afterwards}';

    protected $description = 'Seed a practice of farms into the insights schema in AlloyDB, shaped like Figured\'s MySQL tables';

    public function handle(): int
    {
        $db = DB::connection('alloydb');
        $schema = new InsightsSchema($db);

        try {
            if ($this->option('fresh')) {
                $this->info('Recreating the insights schema…');
                $schema->create();
            }

            $practice = (int) $this->option('practice');
            $farms = (int) $this->option('farms');
            $first = (int) $this->option('first-farm');

            $this->info(sprintf('Seeding practice %d: %s farms from id %d…', $practice, number_format($farms), $first));
            $started = microtime(true);
            $counts = (new InsightsPracticeSeeder($db, $practice, $first, $farms))->seed();
            $this->line(sprintf('  seeded in %.1f s', microtime(true) - $started));
            foreach ($counts as $what => $n) {
                $this->line(sprintf('    %-20s %15s', $what, number_format($n)));
            }

            $this->info('Indexing and analysing…');
            $started = microtime(true);
            $schema->index();
            $this->line(sprintf('  done in %.1f s', microtime(true) - $started));

            if ($this->option('columnar')) {
                $this->info('Populating the column store…');
                $started = microtime(true);
                foreach ($schema->columnarize() as $row) {
                    $this->line(sprintf('  %s: %s, %s of %s blocks', $row['relation_name'], $row['status'], number_format((int) $row['block_count_in_cc']), number_format((int) $row['total_block_count'])));
                }
                $this->line(sprintf('  done in %.1f s', microtime(true) - $started));
            }
        } catch (Throwable $e) {
            $this->error('Setup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
