<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashFlow\CashFlowQuery;
use App\Services\CashFlow\GrossMarginQuery;
use App\Services\CashFlow\GrossMarginV2Query;
use App\Services\CashFlow\TrackerCashFlowQuery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Traces exactly what DuckDB does while running a report.
 *
 * DuckDB's own logger records far more than elapsed time — every HTTP request
 * with its byte range, every file open, every catalog query DuckLake issues,
 * and every physical operator event. That is the difference between "the
 * report took 4 seconds" and knowing which of those things it spent them on.
 *
 * Worth having because elapsed time alone repeatedly produced wrong diagnoses
 * in this project: the same slow report was blamed on download volume, then on
 * request latency, then on the SQL, before the logger showed 520 requests
 * moving 221 KiB with 0.08s of query engine time.
 *
 * Requires `logging_mode = DISABLE_SELECTED` — counter-intuitively that is the
 * "log everything" mode. `LEVEL_ONLY` (the default) filters to a level and
 * drops the typed loggers, so HTTP and FileSystem events never appear. There
 * is no 'ALL' value; that was the first thing tried and it errors.
 */
class DuckDbTraceCommand extends Command
{
    protected $signature = 'duckdb:trace
        {report=gm2 : cashflow | tracker | gm | gm2}
        {--farm= : Farm id (defaults per report)}
        {--from= : Period start}
        {--to= : Period end}
        {--horizon= : Actuals/forecast boundary}
        {--requests : List every HTTP request with its byte range}
        {--catalog : Show the catalog queries DuckLake issued}
        {--out= : Also write the full log as JSONL to this path}';

    protected $description = 'Trace a report: HTTP requests, file opens, catalog queries, operator events';

    public function handle(DuckDB $db): int
    {
        $this->enableFullLogging($db);

        [$query, $scope] = $this->resolve($db);

        if ($query === null) {
            return self::FAILURE;
        }

        $this->info(sprintf('Tracing %s on %s, %s..%s', $this->argument('report'), $scope[0], $scope[3], $scope[4]));
        $this->line('');

        $startedAt = microtime(true);

        try {
            $rows = $query->run(...$scope);
        } catch (Throwable $e) {
            $this->error('Report failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $elapsed = (microtime(true) - $startedAt) * 1000;

        $this->line(sprintf('  %s rows in %.0f ms', number_format(count($rows)), $elapsed));
        $this->line('');

        $this->summary($db, $elapsed);
        $this->httpBreakdown($db);

        if ($this->option('requests')) {
            $this->listRequests($db);
        }

        if ($this->option('catalog')) {
            $this->catalogQueries($db);
        }

        if ($this->option('out')) {
            $this->dump($db, (string) $this->option('out'));
        }

        return self::SUCCESS;
    }

    /**
     * `DISABLE_SELECTED` with nothing disabled is the everything-on mode; the
     * default `LEVEL_ONLY` silently drops the typed loggers.
     */
    private function enableFullLogging(DuckDB $db): void
    {
        $db->query("SET logging_mode='DISABLE_SELECTED'");
        $db->query("SET logging_level='TRACE'");
        $db->query('SET enable_logging=true');
    }

    /**
     * @return array{0: null|object, 1: array<int, string>}
     */
    private function resolve(DuckDB $db): array
    {
        $alias = config('duckdb.attached_alias');
        $report = (string) $this->argument('report');

        $defaults = match ($report) {
            'cashflow' => ['cashflow-oracle-farm', '2024-01-01', '2024-12-31', '2024-02-28'],
            'tracker' => ['tracker-farm-50', '2021-01-01', '2022-12-31', '2021-12-31'],
            'gm' => ['tracker-farm-10', '2021-01-01', '2021-12-31', '2021-12-31'],
            'gm2' => ['gm-dairy-farm-1m', '2026-06-01', '2027-05-31', '2026-08-31'],
            default => null,
        };

        if ($defaults === null) {
            $this->error("Unknown report '{$report}'. Use: cashflow | tracker | gm | gm2");

            return [null, []];
        }

        $farmId = (string) ($this->option('farm') ?: $defaults[0]);
        $farm = DB::table('farms')->where('farm_id', $farmId)->first();

        if ($farm === null) {
            $this->error("Unknown farm '{$farmId}'.");

            return [null, []];
        }

        $query = match ($report) {
            'cashflow' => new CashFlowQuery($db, $alias),
            'tracker' => new TrackerCashFlowQuery($db, $alias),
            'gm' => new GrossMarginQuery($db, $alias),
            'gm2' => new GrossMarginV2Query($db, $alias),
        };

        return [$query, [
            $farmId,
            (string) $farm->farm_type,
            (string) $farm->region,
            (string) ($this->option('from') ?: $defaults[1]),
            (string) ($this->option('to') ?: $defaults[2]),
            (string) ($this->option('horizon') ?: $defaults[3]),
            'cash',
        ]];
    }

    private function summary(DuckDB $db, float $elapsed): void
    {
        $this->line('  What DuckDB did:');

        $sql = "SELECT type, log_level, CAST(count(*) AS BIGINT) n
                FROM duckdb_logs GROUP BY 1,2 ORDER BY n DESC";

        foreach ($db->query($sql)->rows(true) as $row) {
            $this->line(sprintf(
                '      %-20s %-7s %6s events',
                (string) $row['type'],
                (string) $row['log_level'],
                number_format((int) (string) $row['n']),
            ));
        }
    }

    /**
     * The bottleneck view: how many requests, against how many distinct files,
     * and how much was actually transferred.
     */
    private function httpBreakdown(DuckDB $db): void
    {
        $this->line('');
        $this->line('  Storage access:');

        $sql = <<<SQL
            SELECT
                CAST(count(*) AS BIGINT) AS requests,
                CAST(count(DISTINCT regexp_extract(CAST(message AS VARCHAR), 'ducklake-[0-9a-f-]+', 0)) AS BIGINT) AS files
            FROM duckdb_logs
            WHERE type = 'HTTP'
            SQL;

        foreach ($db->query($sql)->rows(true) as $row) {
            $this->line(sprintf('      %-24s %s', 'HTTP requests', number_format((int) (string) $row['requests'])));
            $this->line(sprintf('      %-24s %s', 'distinct parquet files', number_format((int) (string) $row['files'])));
        }

        $opens = <<<SQL
            SELECT
                CAST(json_extract_string(CAST(message AS VARCHAR), '$.op') AS VARCHAR) AS op,
                CAST(count(*) AS BIGINT) AS n
            FROM duckdb_logs
            WHERE type = 'FileSystem'
            GROUP BY 1 ORDER BY n DESC
            SQL;

        try {
            foreach ($db->query($opens)->rows(true) as $row) {
                $this->line(sprintf('      %-24s %s', 'filesystem '.strtolower((string) $row['op']), (string) $row['n']));
            }
        } catch (Throwable) {
            // FileSystem messages are JSON; a format change should not break
            // the rest of the trace.
        }

        $meta = "SELECT CAST(count(*) AS BIGINT) n FROM duckdb_logs WHERE type='DuckLakeMetadata'";

        foreach ($db->query($meta)->rows(true) as $row) {
            $this->line(sprintf('      %-24s %s', 'catalog queries', (string) $row['n']));
        }
    }

    private function listRequests(DuckDB $db): void
    {
        $this->line('');
        $this->line('  Every HTTP request:');

        $sql = <<<SQL
            SELECT
                CAST(regexp_extract(CAST(message AS VARCHAR), 'ducklake-[0-9a-f-]+', 0) AS VARCHAR) AS file,
                CAST(regexp_extract(CAST(message AS VARCHAR), 'year%3D([0-9]+)', 1) AS VARCHAR) AS yr,
                CAST(regexp_extract(CAST(message AS VARCHAR), 'bytes=([0-9]+-[0-9]*)', 1) AS VARCHAR) AS byte_range
            FROM duckdb_logs
            WHERE type = 'HTTP'
            SQL;

        $n = 0;
        foreach ($db->query($sql)->rows(true) as $row) {
            $this->line(sprintf(
                '      %3d  year=%-6s %-28s %s',
                ++$n,
                (string) $row['yr'] ?: '-',
                substr((string) $row['file'], 0, 28) ?: '-',
                (string) $row['byte_range'] ?: 'full object',
            ));
        }
    }

    private function catalogQueries(DuckDB $db): void
    {
        $this->line('');
        $this->line('  Catalog queries DuckLake issued (against its own metadata):');

        $sql = "SELECT CAST(message AS VARCHAR) m FROM duckdb_logs WHERE type='DuckLakeMetadata'";

        $n = 0;
        foreach ($db->query($sql)->rows(true) as $row) {
            $message = preg_replace('/\s+/', ' ', (string) $row['m']);
            $this->line(sprintf('      %2d  %s', ++$n, substr((string) $message, 0, 150)));
        }
    }

    private function dump(DuckDB $db, string $path): void
    {
        $sql = "SELECT CAST(timestamp AS VARCHAR) ts, type, log_level, CAST(message AS VARCHAR) m
                FROM duckdb_logs ORDER BY timestamp";

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            $this->warn("  Could not write {$path}");

            return;
        }

        $n = 0;
        foreach ($db->query($sql)->rows(true) as $row) {
            fwrite($handle, json_encode([
                'ts' => (string) $row['ts'],
                'type' => (string) $row['type'],
                'level' => (string) $row['log_level'],
                'message' => (string) $row['m'],
            ], JSON_UNESCAPED_SLASHES)."\n");
            $n++;
        }

        fclose($handle);

        $this->line('');
        $this->info(sprintf('  Wrote %s log lines to %s', number_format($n), $path));
    }
}
