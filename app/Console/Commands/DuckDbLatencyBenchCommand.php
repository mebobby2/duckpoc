<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashFlow\GrossMarginV2Query;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Measures a report against the current storage backend, then projects what it
 * would cost at higher per-request latencies.
 *
 * Exists because every timing in this PoC conflates two things: how much work
 * DuckDB does, and how far away the bytes are. Running against MinIO on
 * localhost removes the second, leaving a clean measurement of the first —
 * and the request count is then enough to model any latency you like.
 *
 * The projection charges every request the full added latency:
 *
 *     projected = measured + (requests x added_latency)
 *
 * That reads as pessimistic, on the assumption 10 threads would divide it. The
 * measurement says otherwise. Running the same farm at the same file layout on
 * GCS and on MinIO, request counts came out identical (215/214/215/645) and the
 * time difference was 7-10 ms per request against an ~8 ms round trip. Latency
 * lands on the critical path once per request, so the formula above is the
 * observed cost rather than an upper bound on it.
 *
 * On the latency values: production co-locates compute and storage in one
 * region, which is ~0.5-1 ms per request. The 8 ms column is this project's
 * measured Auckland-to-Sydney figure. The 10-50 ms columns model something
 * slower than that — worth having as a stress bound, but not what a
 * same-region deployment should be judged against.
 *
 * Each window is measured in its own subprocess. Sharing one connection across
 * windows made the second window report 0 requests and 622 ms for work the
 * browser took 3,575 ms to do: httpfs had cached the first window's reads, and
 * every window after the first was scored against a warm cache. A cold process
 * per window is the only way these rows mean the same thing as a page load.
 */
class DuckDbLatencyBenchCommand extends Command
{
    protected $signature = 'duckdb:bench:latency
        {--farm=gm-dairy-farm-500m : Farm to report on}
        {--window= : Measure only this window and emit JSON (used for the per-window subprocess)}';

    protected $description = 'Benchmark a report locally, then project the cost at higher per-request latencies';

    /** Milliseconds of added per-request latency to project. */
    private const array LATENCIES = [1, 3, 8, 10, 50];

    /** label => [period_from, period_to] */
    private const array WINDOWS = [
        '1 month' => ['2025-06-01', '2025-06-30'],
        '2 months' => ['2025-06-01', '2025-07-31'],
        '1 year' => ['2025-01-01', '2025-12-31'],
        '3 years' => ['2023-04-01', '2026-03-31'],
    ];

    private const string HORIZON = '2026-08-31';

    public function handle(): int
    {
        $farmId = (string) $this->option('farm');
        $farm = DB::table('farms')->where('farm_id', $farmId)->first();

        if ($farm === null) {
            $this->error("Unknown farm: {$farmId}");

            return self::FAILURE;
        }

        if (($window = (string) $this->option('window')) !== '') {
            return $this->measureOne($window, $farmId, (string) $farm->farm_type, (string) $farm->region);
        }

        return $this->report($farmId);
    }

    /**
     * The subprocess path: one window, one cold connection, JSON on stdout.
     *
     * Resolves DuckDB here rather than taking it as a handle() parameter: the
     * parent process must never open the lake, or it holds the catalog lock its
     * own children need and every window fails to attach.
     */
    private function measureOne(string $window, string $farmId, string $farmType, string $region): int
    {
        if (!isset(self::WINDOWS[$window])) {
            $this->error("Unknown window: {$window}");

            return self::FAILURE;
        }

        [$from, $to] = self::WINDOWS[$window];

        $db = $this->laravel->make(DuckDB::class);

        try {
            $this->line(json_encode(
                $this->measure($db, $farmId, $farmType, $region, $from, $to),
                JSON_THROW_ON_ERROR,
            ));
        } catch (Throwable $e) {
            $this->line(json_encode(['error' => $e->getMessage()]));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function report(string $farmId): int
    {
        $this->info(sprintf(
            'Backend: %s   Farm: %s',
            strtoupper((string) config('duckdb.storage')),
            $farmId,
        ));
        $this->line('  Each window runs in a cold subprocess.');
        $this->line('');

        $header = sprintf('  %-9s %14s %7s %9s', 'window', 'rows in scope', 'reqs', 'measured');
        foreach (self::LATENCIES as $ms) {
            $header .= sprintf(' %9s', '+'.$ms.'ms');
        }
        $this->line($header);
        $this->line('  '.str_repeat('-', 52 + count(self::LATENCIES) * 10));

        foreach (array_keys(self::WINDOWS) as $label) {
            $row = $this->runWindow($farmId, $label);

            if ($row === null) {
                continue;
            }

            $line = sprintf(
                '  %-9s %14s %7s %8.0fms',
                $label,
                number_format($row['rows']),
                number_format($row['requests']),
                $row['ms'],
            );

            foreach (self::LATENCIES as $ms) {
                // Pessimistic: every request pays the latency, serially.
                $line .= sprintf(' %8.1fs', ($row['ms'] + $row['requests'] * $ms) / 1000);
            }

            $this->line($line);
        }

        $this->line('');
        $this->line('  Projections charge every request the full latency. Measured');
        $this->line('  GCS-minus-MinIO on identical file layouts came to 7-10ms per');
        $this->line('  request against an ~8ms RTT, so that is the observed behaviour,');
        $this->line('  not a safety margin: thread count does not divide it away.');
        $this->line('');
        $this->line('  Same-region production is ~0.5-1ms per request; +8ms is this');
        $this->line('  project\'s measured Auckland-to-Sydney cost. +10/+50ms are stress');
        $this->line('  bounds, slower than any co-located deployment should see.');

        return self::SUCCESS;
    }

    /**
     * @return array{rows: int, requests: int, ms: float}|null
     */
    private function runWindow(string $farmId, string $label): ?array
    {
        $result = Process::timeout(900)->run([
            PHP_BINARY,
            base_path('artisan'),
            'duckdb:bench:latency',
            '--farm='.$farmId,
            '--window='.$label,
        ]);

        $decoded = json_decode(trim($result->output()), true);

        if (!is_array($decoded) || isset($decoded['error'])) {
            $this->error(sprintf(
                '  %-9s failed: %s',
                $label,
                $decoded['error'] ?? (trim($result->errorOutput()) ?: 'no JSON on stdout'),
            ));

            return null;
        }

        return [
            'rows' => (int) $decoded['rows'],
            'requests' => (int) $decoded['requests'],
            'ms' => (float) $decoded['ms'],
        ];
    }

    /**
     * @return array{rows: int, requests: int, ms: float}
     */
    private function measure(
        DuckDB $db,
        string $farmId,
        string $farmType,
        string $region,
        string $from,
        string $to,
    ): array {
        $db->query("SET logging_mode='DISABLE_SELECTED'");
        $db->query("SET logging_level='TRACE'");
        $db->query('SET enable_logging=true');

        $query = new GrossMarginV2Query($db, config('duckdb.attached_alias'));
        $scope = [$farmId, $farmType, $region, $from, $to, self::HORIZON, 'cash'];

        $before = $this->requestCount($db);
        $startedAt = microtime(true);
        $query->run(...$scope);
        $ms = (microtime(true) - $startedAt) * 1000;
        $requests = $this->requestCount($db) - $before;

        // After the timing on purpose: this is a second full scan, and its cost
        // belongs to the diagnostic, not to the report being measured.
        $summary = $query->sourceRowSummary(...$scope);

        return ['rows' => $summary['n'], 'requests' => $requests, 'ms' => $ms];
    }

    private function requestCount(DuckDB $db): int
    {
        foreach ($db->query("SELECT CAST(count(*) AS BIGINT) c FROM duckdb_logs WHERE type='HTTP'")->rows(true) as $row) {
            return (int) (string) $row['c'];
        }

        return 0;
    }
}
