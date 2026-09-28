<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AlloyDb\AlloyDbSchema;
use App\Services\CashFlow\CashFlowActualsForecastFarmSeeder;
use App\Services\CashFlow\CashFlowActualsForecastOracleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Seeds the actuals-plus-forecast Cash Flow farms into AlloyDB with the very
 * seeders that fill the lake.
 *
 * Those seeders write dimensions through Laravel's default connection, which
 * is AlloyDB inside app-alloydb, and generate journal lines as DuckDB SQL
 * against a schema alias. Pointing that alias at AlloyDB, attached to an
 * in-memory DuckDB through its postgres extension, sends the same generated
 * rows into PostgreSQL. So the two engines hold identical data, and the
 * report can be compared between them line for line rather than to within
 * the noise of two different generators.
 */
class AlloyDbCashFlowAfSeedCommand extends Command
{
    private const string PG_ALIAS = 'pg';

    protected $signature = 'alloydb:cashflow-af:seed
        {--farm=cfaf-dairy-nz : Farm id for the dairy farm}
        {--lines=200000 : Journal lines to seed the dairy farm with, across four seasons}
        {--milk-trackers=13 : Milk trackers on the dairy farm}
        {--stock-trackers=2 : Livestock trackers on the dairy farm}
        {--oracle : Seed only the hand-computed oracle farm}
        {--columnar : Populate the column store with the report\'s columns afterwards}';

    protected $description = 'Seed the actuals-plus-forecast Cash Flow farms into AlloyDB, identical to the lake';

    public function handle(): int
    {
        if (config('database.default') !== 'alloydb') {
            $this->error('Run this from the app-alloydb container: the seeders write dimensions through the default connection.');

            return self::FAILURE;
        }

        $pg = DB::connection('alloydb');
        $schema = new AlloyDbSchema($pg);
        $lines = max(1, (int) $this->option('lines'));
        $bulkLoad = !$this->option('oracle') && $lines >= 1_000_000;

        try {
            $schema->ensureCashFlowActualsForecast();
            $duck = $this->attachAlloyDb();

            $t = hrtime(true);
            $n = (new CashFlowActualsForecastOracleSeeder($duck, self::PG_ALIAS))->seed();
            $this->line(sprintf('  %-22s %13s lines  %7.1f s', CashFlowActualsForecastOracleSeeder::FARM_ID, number_format($n), (hrtime(true) - $t) / 1e9));

            if (!$this->option('oracle')) {
                // Same trade as alloydb:setup: a btree maintained through a
                // bulk insert costs far more than one sorted build after it.
                if ($bulkLoad) {
                    $this->line('  dropping the index for the load…');
                    $schema->dropIndex();
                }

                $t = hrtime(true);
                $result = (new CashFlowActualsForecastFarmSeeder(
                    $duck,
                    self::PG_ALIAS,
                    self::PG_ALIAS,
                    max(1, (int) $this->option('milk-trackers')),
                    max(1, (int) $this->option('stock-trackers')),
                    (string) $this->option('farm'),
                ))->seed($lines);
                $this->line(sprintf(
                    '  %-22s %13s lines  %7.1f s  (%d trackers, %d lines per account-month)',
                    $this->option('farm'),
                    number_format($result['lines']),
                    (hrtime(true) - $t) / 1e9,
                    $result['trackers'],
                    $result['lines_per_slot'],
                ));
            }

            $t = hrtime(true);
            $this->line($bulkLoad ? '  rebuilding the index and analysing…' : '  analysing…');
            $schema->index();
            $this->line(sprintf('    done in %.1f s', (hrtime(true) - $t) / 1e9));

            if ($this->option('columnar')) {
                $t = hrtime(true);
                $this->line('  populating the column store…');
                foreach ($schema->columnarize($bulkLoad, AlloyDbSchema::CASH_FLOW_COLUMNAR_COLUMNS) as $column) {
                    $this->line(sprintf('    %-12s %-10s %s', $column['column_name'] ?? '?', $column['in_memory'] ?? '?', $column['status'] ?? ''));
                }
                $this->line(sprintf('    done in %.1f s', (hrtime(true) - $t) / 1e9));
            }
        } catch (Throwable $e) {
            $this->error('Seed failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function attachAlloyDb(): DuckDB
    {
        $duck = DuckDB::create();
        $directory = (string) config('duckdb.extension_directory');
        if ($directory !== '') {
            $duck->query(sprintf("SET extension_directory = '%s'", str_replace("'", "''", $directory)));
        }
        $duck->query('INSTALL postgres');
        $duck->query('LOAD postgres');

        $c = config('database.connections.alloydb');
        $dsn = sprintf('host=%s port=%s dbname=%s user=%s password=%s', $c['host'], $c['port'], $c['database'], $c['username'], $c['password']);
        $duck->query(sprintf("ATTACH '%s' AS %s (TYPE postgres)", str_replace("'", "''", $dsn), self::PG_ALIAS));

        return $duck;
    }
}
