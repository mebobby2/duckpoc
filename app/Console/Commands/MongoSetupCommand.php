<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Mongo\MongoConnectionFactory;
use App\Services\Mongo\MongoSeeder;
use Illuminate\Console\Command;
use Throwable;

class MongoSetupCommand extends Command
{
    protected $signature = 'mongo:setup
        {--farm=gm-dairy-farm : Farm to load}
        {--region=gm-waikato : Region recorded on the farm}
        {--rows=0 : Target journal lines (0 = demo scale, one line per account-month)}
        {--fresh : Drop the journal collection before loading}';

    protected $description = 'Load a farm the way Figured stores one: journals in MongoDB, dimensions in MySQL';

    public function handle(MongoConnectionFactory $mongo): int
    {
        $farmId = (string) $this->option('farm');
        $region = (string) $this->option('region');
        $rows = max(0, (int) $this->option('rows'));

        if ($this->option('fresh')) {
            $this->info('Dropping the journal collection…');
            $mongo->journals()->drop();
        }

        $this->info(sprintf(
            'Loading %s%s — journals to MongoDB, dimensions to MySQL',
            $farmId,
            $rows > 0 ? sprintf(' — target %s journal lines', number_format($rows)) : ' — demo scale',
        ));

        $seeder = new MongoSeeder($mongo, $farmId, $region, $rows);

        // Same trade as the Postgres loader: maintaining the btree through a
        // bulk insert costs more than one build afterwards. Only worth the
        // index rebuild at volume — at demo scale the drop is pure overhead.
        $bulkLoad = $rows >= 1_000_000;

        if ($bulkLoad) {
            $this->line('  dropping the index for the load…');
            $seeder->dropIndex();
        }

        $started = microtime(true);
        $lastReport = $started;

        try {
            $result = $seeder->seed(function (int $written) use (&$lastReport, $started): void {
                // Client-side loading is slow enough at bulk scale that a
                // silent command looks hung. Reporting on a time interval
                // rather than a document count keeps the output readable
                // whatever the batch size is.
                if (microtime(true) - $lastReport < 10.0) {
                    return;
                }

                $lastReport = microtime(true);
                $elapsed = $lastReport - $started;

                $this->line(sprintf(
                    '    %s documents · %.0fs · %s docs/sec',
                    number_format($written),
                    $elapsed,
                    number_format($elapsed > 0 ? (int) ($written / $elapsed) : 0),
                ));
            });
        } catch (Throwable $e) {
            $this->error('Load failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $elapsed = microtime(true) - $started;

        $this->line(sprintf(
            '  loaded %s journal documents (+%s dimension rows in MySQL) in %.1fs — %s docs/sec',
            number_format($result['fact_rows']),
            number_format($result['dimension_rows']),
            $elapsed,
            number_format($elapsed > 0 ? (int) ($result['fact_rows'] / $elapsed) : 0),
        ));

        $this->info('Building the index…');
        $started = microtime(true);
        $seeder->index();
        $this->line(sprintf('  done in %.1fs', microtime(true) - $started));

        $stats = $mongo->database()->command([
            'collStats' => (string) config('mongo.collection'),
        ])->toArray()[0];

        $this->line(sprintf(
            '  collection: %s documents · %s data · %s indexes',
            number_format((int) ($stats['count'] ?? 0)),
            $this->bytes((int) ($stats['size'] ?? 0)),
            $this->bytes((int) ($stats['totalIndexSize'] ?? 0)),
        ));

        return self::SUCCESS;
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $bytes, $units[$i]);
    }
}
