<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashFlow\CashFlowSchema;
use App\Services\CashFlow\TrackerScaleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Seeds the tracker-count experiment: three farms, same journal volume,
 * 1 / 10 / 50 trackers.
 */
class DuckDbTrackerSeedCommand extends Command
{
    protected $signature = 'duckdb:tracker:seed';

    protected $description = 'Seed three farms differing only in tracker count (1/10/50) for the tracker-scaling test';

    public function handle(DuckDB $db): int
    {
        $alias = config('duckdb.attached_alias');
        $appAlias = config('duckdb.app_database.alias');

        // Additive, idempotent — existing rows read back NULL rather than
        // being dropped and re-seeded.
        $schema = new CashFlowSchema($db, $alias);
        $schema->ensureWriteOptions();
        $schema->addTrackerColumn();

        $rowsPer = TrackerScaleSeeder::rowsPerFarm();

        $this->info(sprintf(
            'Seeding 3 farms x %s journals, varying only tracker count...',
            number_format($rowsPer),
        ));
        $this->line('');

        $started = microtime(true);

        try {
            (new TrackerScaleSeeder($db, $alias, $appAlias))->seed(
                function (string $farmId, int $rows, int $trackers) use ($started): void {
                    $this->line(sprintf(
                        '    ✔ %-18s %s rows   %2d tracker(s)   (%.1fs elapsed)',
                        $farmId,
                        number_format($rows),
                        $trackers,
                        microtime(true) - $started,
                    ));
                }
            );
        } catch (Throwable $e) {
            $this->error('Seed failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->info(sprintf('✔ Seeded in %.1fs.', microtime(true) - $started));

        $this->reportTagging($db, $alias);

        $this->line('');
        $this->line('Next:');
        $this->line('    duckdb:tracker:curve    — the 1 vs 10 vs 50 timing curve');
        $this->line('    /tracker-cashflow       — the report viewer for this report');

        return self::SUCCESS;
    }

    /**
     * The check that matters: same journal count per farm, different tracker
     * cardinality. If volume differs, the curve measures the wrong thing.
     */
    private function reportTagging(DuckDB $db, string $alias): void
    {
        $this->line('');
        $this->line('Per farm — journal volume held constant, tracker count varying:');

        $sql = <<<SQL
            SELECT
                farm_id,
                CAST(count(*) AS BIGINT) AS rows_total,
                CAST(count(tracker_id) AS BIGINT) AS rows_tagged,
                CAST(count(DISTINCT tracker_id) AS BIGINT) AS trackers
            FROM {$alias}.transaction_lines
            WHERE farm_id LIKE 'tracker-%'
            GROUP BY farm_id
            ORDER BY farm_id
            SQL;

        foreach ($db->query($sql)->rows(true) as $row) {
            $this->line(sprintf(
                '    %-18s %9s journals  %9s tagged  %3s distinct tracker(s)',
                $row['farm_id'],
                number_format((int) (string) $row['rows_total']),
                number_format((int) (string) $row['rows_tagged']),
                (string) $row['trackers'],
            ));
        }

        $this->line('');
        $this->line(sprintf(
            '    trackers in MySQL: %d row(s)',
            DB::table('trackers')->where('farm_id', 'like', 'tracker-%')->count(),
        ));
    }
}
