<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashFlow\CashFlowActualsForecastFarmSeeder;
use App\Services\CashFlow\CashFlowActualsForecastOracleSeeder;
use App\Services\CashFlow\CashFlowSchema;
use Illuminate\Console\Command;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Seeds the farms the actuals-plus-forecast Cash Flow runs against: the
 * hand-computable oracle, and the thirteen-milk-tracker dairy farm shaped
 * like Figured's benchmark farm at a chosen line volume.
 */
class DuckDbCashFlowAfSeedCommand extends Command
{
    protected $signature = 'duckdb:cashflow-af:seed
        {--lines=200000 : Journal lines to seed the dairy farm with, across four seasons}
        {--milk-trackers=13 : Milk trackers on the dairy farm; past thirteen they are generated blocks}
        {--stock-trackers=2 : Livestock trackers on the dairy farm; past two they are generated herds}
        {--farm=cfaf-dairy-nz : Farm id for the dairy farm; another id seeds a second farm beside it}
        {--oracle : Seed only the hand-computed oracle farm}
        {--skip-farm : Seed only the oracle farm (alias of --oracle)}';

    protected $description = 'Seed the oracle and the 13-milk-tracker dairy farm for the actuals-plus-forecast Cash Flow';

    public function handle(DuckDB $db): int
    {
        $alias = config('duckdb.attached_alias');
        $appAlias = config('duckdb.app_database.alias');

        try {
            $schema = new CashFlowSchema($db, $alias);
            $schema->addTrackerColumn();
            $schema->addTagColumn();
            $schema->applyWriteTuning();

            $t = hrtime(true);
            $n = (new CashFlowActualsForecastOracleSeeder($db, $alias))->seed();
            $this->line(sprintf('  %-22s %6d lines  %6.1f s', CashFlowActualsForecastOracleSeeder::FARM_ID, $n, (hrtime(true) - $t) / 1e9));

            if ($this->option('oracle') || $this->option('skip-farm')) {
                return self::SUCCESS;
            }

            $t = hrtime(true);
            $result = (new CashFlowActualsForecastFarmSeeder(
                $db,
                $alias,
                $appAlias,
                max(1, (int) $this->option('milk-trackers')),
                max(1, (int) $this->option('stock-trackers')),
                (string) $this->option('farm'),
            ))->seed((int) $this->option('lines'));
            $this->line(sprintf(
                '  %-22s %s lines  %6.1f s  (%d trackers, %d lines per account-month)',
                (string) $this->option('farm'),
                number_format($result['lines']),
                (hrtime(true) - $t) / 1e9,
                $result['trackers'],
                $result['lines_per_slot'],
            ));
        } catch (Throwable $e) {
            $this->error('Seed failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
