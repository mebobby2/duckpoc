<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashFlow\CashFlowSchema;
use App\Services\CashFlow\GrossMarginV2Seeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Seeds the mixed-enterprise dairy farm that Gross Margin V2 reports on:
 * milk and livestock trackers on one farm.
 */
class DuckDbGmV2SeedCommand extends Command
{
    protected $signature = 'duckdb:gm2:seed
        {--bulk : Seed the volume variant (gm-dairy-farm-1m) instead of the demo farm}
        {--huge : Seed the large variant (gm-dairy-farm-500m)}
        {--rows=1000000 : Target journal lines when --bulk or --huge is used}';

    protected $description = 'Seed a mixed milk + livestock dairy farm for the Gross Margin V2 report';

    public function handle(DuckDB $db): int
    {
        $alias = config('duckdb.attached_alias');
        $appAlias = config('duckdb.app_database.alias');

        $schema = new CashFlowSchema($db, $alias);
        $schema->ensureWriteOptions();
        $schema->applyWriteTuning();
        $schema->addTrackerColumn();

        $huge = (bool) $this->option('huge');
        $bulk = $huge || (bool) $this->option('bulk');
        $rows = $bulk ? max(1, (int) $this->option('rows')) : 0;

        $farmId = match (true) {
            $huge => GrossMarginV2Seeder::HUGE_FARM_ID,
            $bulk => GrossMarginV2Seeder::BULK_FARM_ID,
            default => GrossMarginV2Seeder::FARM_ID,
        };
        $region = match (true) {
            $huge => GrossMarginV2Seeder::HUGE_REGION,
            $bulk => GrossMarginV2Seeder::BULK_REGION,
            default => GrossMarginV2Seeder::REGION,
        };

        $this->info(sprintf(
            'Seeding %s — milk + livestock trackers, %d-%d%s',
            $farmId,
            GrossMarginV2Seeder::firstYear(),
            GrossMarginV2Seeder::lastYear(),
            $bulk ? sprintf(', target %s journal lines', number_format($rows)) : '',
        ));

        $started = microtime(true);

        try {
            (new GrossMarginV2Seeder($db, $alias, $appAlias, $farmId, $region, $rows))->seed();
        } catch (Throwable $e) {
            $this->error('Seed failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->info(sprintf('✔ Seeded in %.1fs.', microtime(true) - $started));
        $this->line('');
        foreach ($db->query("SELECT CAST(count(*) AS BIGINT) n, CAST(count(DISTINCT account_id) AS BIGINT) a,
                             CAST(count(DISTINCT tracker_id) AS BIGINT) t, CAST(min(date) AS VARCHAR) lo,
                             CAST(max(date) AS VARCHAR) hi
                             FROM {$alias}.transaction_lines WHERE farm_id = '{$farmId}'")->rows(true) as $row) {
            $this->line(sprintf('    journal lines   : %s', number_format((int) (string) $row['n'])));
            $this->line(sprintf('    accounts        : %s', (string) $row['a']));
            $this->line(sprintf('    trackers        : %s', (string) $row['t']));
            $this->line(sprintf('    span            : %s .. %s', $row['lo'], $row['hi']));
        }

        $this->line(sprintf(
            '    milk months     : %s',
            number_format(DB::table('tracker_milk_production')
                ->where('tracker_id', 'like', $farmId.'%')->count()),
        ));
        $this->line(sprintf(
            '    stock movements : %s',
            number_format(DB::table('tracker_stock_movements')
                ->where('tracker_id', 'like', $farmId.'%')->count()),
        ));

        $this->line('');
        $this->line('    tracker types on this farm:');
        foreach (DB::table('trackers')->where('farm_id', $farmId)
            ->select('tracker_type')->selectRaw('COUNT(*) AS n')
            ->groupBy('tracker_type')->get() as $row) {
            $this->line(sprintf('        %-12s %d', $row->tracker_type, $row->n));
        }

        return self::SUCCESS;
    }
}
