<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashFlow\StockMovementSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Seeds monthly stock movements for every tracker-bearing farm.
 *
 * The journal year span comes from the lake (each farm was seeded over a
 * different span), and movements must cover all of it — opening stock for any
 * month is every prior movement accumulated, so a short history silently
 * produces wrong head counts.
 */
class DuckDbStockSeedCommand extends Command
{
    protected $signature = 'duckdb:stock:seed';

    protected $description = 'Seed per-tracker monthly stock movements (the quantity half of Gross Margin)';

    public function handle(DuckDB $db): int
    {
        $alias = config('duckdb.attached_alias');

        $farmIds = DB::table('trackers')->distinct()->pluck('farm_id')->all();

        if ($farmIds === []) {
            $this->error('No trackers found. Run duckdb:tracker:seed and/or duckdb:hero:seed first.');

            return self::FAILURE;
        }

        $spans = [];

        foreach ($farmIds as $farmId) {
            $sql = sprintf(
                "SELECT CAST(min(year(date)) AS INTEGER) lo, CAST(max(year(date)) AS INTEGER) hi
                 FROM %s.transaction_lines WHERE farm_id = '%s'",
                $alias,
                str_replace("'", "''", (string) $farmId),
            );

            try {
                foreach ($db->query($sql)->rows(true) as $row) {
                    if ($row['lo'] === null) {
                        continue;
                    }

                    $spans[(string) $farmId] = [(int) (string) $row['lo'], (int) (string) $row['hi']];
                }
            } catch (Throwable $e) {
                $this->error("Could not read span for {$farmId}: ".$e->getMessage());

                return self::FAILURE;
            }
        }

        $this->info(sprintf('Seeding stock movements for %d farm(s)...', count($spans)));
        $this->line('');

        (new StockMovementSeeder())->seed(
            $spans,
            function (string $farmId, int $rows) use ($spans): void {
                [$lo, $hi] = $spans[$farmId];

                $this->line(sprintf(
                    '    %-24s %8s movement rows   %d-%d (%d years)',
                    $farmId,
                    number_format($rows),
                    $lo,
                    $hi,
                    $hi - $lo + 1,
                ));
            }
        );

        $this->line('');
        $this->info(sprintf(
            '✔ %s movement rows across %d tracker(s).',
            number_format(DB::table('tracker_stock_movements')->count()),
            DB::table('trackers')->count(),
        ));

        return self::SUCCESS;
    }
}
