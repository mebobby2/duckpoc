<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AlloyDb\GrossMarginV2PgQuery;
use App\Services\AlloyDb\OverdraftPgQuery;
use App\Services\CashFlow\GrossMarginV2Query;
use App\Services\CashFlow\DataPipelineSqlBuilder;
use App\Services\CashFlow\OverdraftQuery;
use App\Services\CashFlow\PipelineOptions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;
use Saturio\DuckDB\Type\Type;
use Throwable;

/**
 * Concurrent report load, which every other measurement in this PoC omits.
 *
 * Every number in the README is one query on an idle machine. That is the case
 * that flatters a columnar engine most: DuckDB takes all sixteen cores for a
 * single scan, so a lone query looks superb and says nothing about what ten
 * simultaneous users would get.
 *
 * **Why this measures queries and not HTTP.** `php artisan serve` wraps
 * `php -S`, which is single-process unless `PHP_CLI_SERVER_WORKERS` is set —
 * it is not. Firing concurrent requests at the report pages would queue them
 * at the dev server and measure that queue, not the engine. Production runs
 * PHP-FPM with a worker pool, so separate OS processes are the faithful model,
 * and that is what this spawns.
 *
 * **The asymmetry this exists to expose.** DuckDB is embedded, so each worker
 * process gets its OWN instance with its own memory and thread pool — N
 * concurrent reports mean N DuckDB instances each wanting every core. AlloyDB
 * and MongoDB are shared servers: N concurrent queries arrive at one process
 * that schedules them. Those behave very differently as N rises, and single-
 * query benchmarks cannot see the difference at all.
 *
 * Workers are separate processes because ext-pcntl is not installed, and
 * because one process sharing a DuckDB handle across "concurrent" queries
 * would measure a mutex rather than the engine.
 */
class BenchConcurrencyCommand extends Command
{
    protected $signature = 'bench:concurrency
        {--engine=lake : lake | alloydb}
        {--report=overdraft : overdraft | gross-margin | pipeline}
        {--farm= : Farm to report on; defaults per engine}
        {--concurrency=1 : Worker processes running at once}
        {--runs=5 : Timed iterations per worker}
        {--period-from=2024-01-01}
        {--period-to=2027-12-31}
        {--horizon=2026-08-31}
        {--worker : Internal — run as a child and emit JSON}';

    protected $description = 'Run a report concurrently and report latency and throughput';

    public function handle(): int
    {
        return $this->option('worker') ? $this->runWorker() : $this->runParent();
    }

    private function runParent(): int
    {
        $concurrency = max(1, (int) $this->option('concurrency'));
        $runs = max(1, (int) $this->option('runs'));
        $engine = (string) $this->option('engine');
        $report = (string) $this->option('report');
        $farm = $this->farm();

        $this->info(sprintf(
            '%s · %s · farm %s · concurrency %d · %d runs each',
            $engine, $report, $farm, $concurrency, $runs
        ));

        // Deliberately NOT resolving a DuckDB handle here. The parent only
        // spawns and aggregates: attaching the lake in this process takes the
        // catalog lock and every worker then fails to start, which is a
        // self-inflicted result rather than a measurement.

        $command = [
            PHP_BINARY, base_path('artisan'), 'bench:concurrency', '--worker',
            '--engine='.$engine, '--report='.$report, '--farm='.$farm,
            '--runs='.$runs,
            '--period-from='.$this->option('period-from'),
            '--period-to='.$this->option('period-to'),
            '--horizon='.$this->option('horizon'),
        ];

        $processes = [];
        $pipes = [];
        $startedAt = microtime(true);

        for ($i = 0; $i < $concurrency; $i++) {
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open($command, $descriptors, $workerPipes, base_path());

            if (!is_resource($process)) {
                $this->error('Could not spawn worker '.$i);

                return self::FAILURE;
            }

            $processes[$i] = $process;
            $pipes[$i] = $workerPipes;
        }

        $latencies = [];
        $errors = [];

        foreach ($processes as $i => $process) {
            $out = stream_get_contents($pipes[$i][1]);
            $err = stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($process);

            $decoded = json_decode(trim($out), true);

            if (!is_array($decoded) || !isset($decoded['latencies'])) {
                $errors[] = sprintf('worker %d: %s', $i, trim($err) !== '' ? substr(trim($err), 0, 200) : 'no result');

                continue;
            }

            foreach ($decoded['latencies'] as $ms) {
                $latencies[] = (float) $ms;
            }
        }

        $wall = (microtime(true) - $startedAt) * 1000;

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error('  '.$error);
            }
        }

        if ($latencies === []) {
            return self::FAILURE;
        }

        sort($latencies);
        $n = count($latencies);

        $this->line('');
        $this->line(sprintf('  requests        %d', $n));
        $this->line(sprintf('  wall clock      %s ms', number_format($wall, 0)));
        $this->line(sprintf('  throughput      %s req/sec', number_format($n / ($wall / 1000), 2)));
        $this->line(sprintf('  latency p50     %s ms', number_format($latencies[(int) ($n * 0.50)], 0)));
        $this->line(sprintf('  latency p95     %s ms', number_format($latencies[min($n - 1, (int) ($n * 0.95))], 0)));
        $this->line(sprintf('  latency max     %s ms', number_format($latencies[$n - 1], 0)));

        return self::SUCCESS;
    }

    /**
     * One worker: bootstrap once, then time `runs` iterations.
     *
     * Bootstrap and the DuckDB ATTACH are deliberately outside the timing. They
     * are a real cost, but a per-process one that PHP-FPM pays once per worker
     * rather than once per report, and including it here would swamp the signal
     * this command exists to find.
     */
    private function runWorker(): int
    {
        $runs = max(1, (int) $this->option('runs'));
        $latencies = [];

        try {
            $run = $this->resolveRunner();

            // Warm once, untimed: the first call pays for the attach and any
            // lazy singleton, which every later call does not.
            $run();

            for ($i = 0; $i < $runs; $i++) {
                $startedAt = microtime(true);
                $run();
                $latencies[] = (microtime(true) - $startedAt) * 1000;
            }
        } catch (Throwable $e) {
            fwrite(STDERR, get_class($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        $this->output->write(json_encode(['latencies' => $latencies]));

        return self::SUCCESS;
    }

    private function resolveRunner(): callable
    {
        $farm = $this->farm();
        $from = (string) $this->option('period-from');
        $to = (string) $this->option('period-to');
        $horizon = (string) $this->option('horizon');
        $report = (string) $this->option('report');

        if ((string) $this->option('engine') === 'alloydb') {
            $db = DB::connection('alloydb');

            return $report === 'gross-margin'
                ? static fn () => $db->select((new GrossMarginV2PgQuery($db))->sql(), [
                    'farm_id' => $farm, 'farm_id2' => $farm, 'farm_id3' => $farm, 'basis' => 'cash',
                    'period_from' => $from, 'period_from2' => $from, 'period_to' => $to,
                    'period_to2' => $to, 'horizon' => $horizon, 'horizon2' => $horizon, 'horizon3' => $horizon,
                ])
                : static fn () => (new OverdraftPgQuery($db))->run($farm, $from, $to, $horizon);
        }

        $duck = app(DuckDB::class);
        $alias = config('duckdb.attached_alias');
        $appAlias = config('duckdb.app_database.alias');

        if ($report === 'gross-margin') {
            $query = new GrossMarginV2Query($duck, $alias);

            return static fn () => $query->run($farm, 'dairy', 'gm-bulk', $from, $to, $horizon, 'cash');
        }

        if ($report === 'pipeline') {
            // The whole DataPipeline with every gate on — the report the
            // overdraft page renders — as one prepared statement per run.
            $sql = (new DataPipelineSqlBuilder($alias, $appAlias, new PipelineOptions(
                ytd: true, excludeEoyJournals: true, includeOpeningBudgetGst: true,
                calculateCurrentYearEarnings: true, calculateRetained: true,
                showExpectedSign: true, dynamicBankAccount: true,
            )))->build();

            return static function () use ($duck, $sql, $farm, $from, $to, $horizon): void {
                $statement = $duck->preparedStatement($sql);
                foreach (['farm_id' => $farm, 'period_from' => $from, 'period_to' => $to, 'horizon' => $horizon] as $p => $v) {
                    $statement->bindParam($p, $v, Type::DUCKDB_TYPE_VARCHAR);
                }
                iterator_to_array($statement->execute()->rows(true));
            };
        }

        $query = new OverdraftQuery($duck, $alias, $appAlias);

        return static fn () => $query->run($farm, $from, $to, $horizon);
    }

    private function farm(): string
    {
        $farm = (string) $this->option('farm');

        if ($farm !== '') {
            return $farm;
        }

        return (string) $this->option('report') === 'gross-margin'
            ? 'gm-dairy-farm-1m'
            : 'overdraft-oracle-farm';
    }

    private function duckdbThreads(): string
    {
        try {
            foreach (app(DuckDB::class)->query("SELECT current_setting('threads') AS t")->rows(true) as $row) {
                return (string) $row['t'];
            }
        } catch (Throwable) {
            // Not fatal; the header line is informational.
        }

        return '?';
    }
}
