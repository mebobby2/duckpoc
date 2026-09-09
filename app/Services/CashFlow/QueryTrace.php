<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * A chronological waterfall of everything DuckDB did during one request.
 *
 * `duckdb_logs` timestamps to the microsecond, which is what makes this
 * possible: the gap between one event and the next is the time spent after
 * that event, so ordering the whole log and taking `LEAD(timestamp)` yields a
 * per-step duration without instrumenting anything ourselves.
 *
 * That attribution is the point, and worth stating precisely: a step's
 * duration is *the wait that followed it*, not its own execution time. A
 * 317 ms HTTPFSInfo entry means DuckDB logged a connection event and then
 * spent 317 ms before logging anything else — it was blocked on that network
 * read. Reading it as "this log call took 317 ms" would be wrong.
 *
 * Built after a first attempt that listed HTTP byte ranges with no timings,
 * which was accurate and useless: it could not answer "where did the time go",
 * the only question worth asking of a trace.
 */
final class QueryTrace
{
    /** Steps at or above this are called out as hotspots. */
    private const float SLOW_STEP_MS = 5.0;

    public function __construct(
        private readonly DuckDB $db,
    ) {
    }

    /**
     * `DISABLE_SELECTED` with nothing disabled is the everything-on mode. The
     * default `LEVEL_ONLY` filters to a level and drops the typed loggers, so
     * HTTP and FileSystem events never appear — and there is no 'ALL' value,
     * which is the obvious first guess and errors.
     */
    public function start(): void
    {
        try {
            $this->db->query("SET logging_mode='DISABLE_SELECTED'");
            $this->db->query("SET logging_level='TRACE'");
            $this->db->query('SET enable_logging=true');
        } catch (Throwable) {
            // Diagnostics must never break the report.
        }
    }

    /**
     * @return array{ok: bool, error: null|string, total_ms: float, steps: list<array<string, mixed>>,
     *               by_type: list<array<string, mixed>>, hotspots: list<array<string, mixed>>}
     */
    public function collect(): array
    {
        try {
            $steps = $this->steps();
            $total = 0.0;

            foreach ($steps as $step) {
                $total += $step['dur_ms'];
            }

            return [
                'ok' => true,
                'error' => null,
                'total_ms' => $total,
                'steps' => $steps,
                'by_type' => $this->byType($steps, $total),
                'hotspots' => $this->hotspots($steps),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'total_ms' => 0.0,
                    'steps' => [], 'by_type' => [], 'hotspots' => []];
        }
    }

    /**
     * Every logged event in order, with the gap to the next one.
     *
     * @return list<array<string, mixed>>
     */
    private function steps(): array
    {
        $sql = <<<SQL
            WITH ev AS (
                SELECT
                    timestamp,
                    type,
                    CAST(message AS VARCHAR) AS m,
                    datediff('microsecond', timestamp,
                             lead(timestamp) OVER (ORDER BY timestamp)) AS gap_us,
                    datediff('microsecond', min(timestamp) OVER (), timestamp) AS at_us
                FROM duckdb_logs
            )
            SELECT CAST(at_us AS BIGINT) AS at_us,
                   CAST(COALESCE(gap_us, 0) AS BIGINT) AS gap_us,
                   type, m
            FROM ev
            ORDER BY timestamp
            SQL;

        $steps = [];

        foreach ($this->db->query($sql)->rows(true) as $row) {
            $dur = ((int) (string) $row['gap_us']) / 1000;

            $steps[] = [
                'at_ms' => ((int) (string) $row['at_us']) / 1000,
                'dur_ms' => $dur,
                'type' => (string) $row['type'],
                'detail' => $this->describe((string) $row['type'], (string) $row['m']),
                'slow' => $dur >= self::SLOW_STEP_MS,
            ];
        }

        return $steps;
    }

    /**
     * Turns a raw log message into something readable, per type.
     *
     * Done in PHP rather than SQL because the messages are pseudo-JSON with
     * single quotes — not parseable by `json_extract`, and regex patterns
     * containing quotes are painful in DuckDB, where double quotes mean an
     * identifier rather than a string. That mistake cost a broken panel once
     * already.
     */
    private function describe(string $type, string $message): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $message));

        return match ($type) {
            'HTTP' => $this->describeHttp($flat),
            'FileSystem' => $this->describeFileSystem($flat),
            'DuckLakeMetadata' => 'catalog · '.$this->extract($flat, "/'query': '(.*?)',? ?'?elapsed_ms/", 120, mb_substr($flat, 0, 110)),
            'PhysicalOperator' => $this->describeOperator($flat),
            'HTTPFSInfo' => 'httpfs · '.$this->extract($flat, '/"type":"([^"]+)"/', 60, 'connection event'),
            'QueryLog' => 'SQL · '.mb_substr($flat, 0, 120),
            'Transaction' => 'txn · '.mb_substr($flat, 0, 60),
            default => mb_substr($flat, 0, 120),
        };
    }

    private function describeHttp(string $flat): string
    {
        $range = $this->extract($flat, '/bytes=([0-9]+-[0-9]*)/', 30, 'full object');
        $year = $this->extract($flat, '/year%3D([0-9]+)/', 6);

        $size = '';
        if (preg_match('/bytes=([0-9]+)-([0-9]+)/', $flat, $m) === 1) {
            $size = sprintf(' · %s B', number_format((int) $m[2] - (int) $m[1] + 1));
        }

        return sprintf('GET %s%s%s', $range, $size, $year !== '' ? '  year='.$year : '');
    }

    private function describeFileSystem(string $flat): string
    {
        return sprintf(
            'fs %s · %s',
            strtolower($this->extract($flat, '/"op":"([^"]+)"/', 20, 'op')),
            $this->extract($flat, '|/([^/"]+\.parquet)|', 55),
        );
    }

    private function describeOperator(string $flat): string
    {
        $rows = $this->extract($flat, '/rows=([0-9]+)/', 15);

        return sprintf(
            '%s %s%s',
            $this->extract($flat, "/'operator_type': ([A-Z_]+)/", 30, 'operator'),
            $this->extract($flat, "/'event': ([A-Za-z]+)/", 20),
            $rows !== '' ? ' · rows='.number_format((int) $rows) : '',
        );
    }

    private function extract(string $subject, string $pattern, int $limit, string $fallback = ''): string
    {
        return preg_match($pattern, $subject, $m) === 1 ? mb_substr($m[1], 0, $limit) : $fallback;
    }

    /**
     * @param list<array<string, mixed>> $steps
     * @return list<array<string, mixed>>
     */
    private function byType(array $steps, float $total): array
    {
        $agg = [];

        foreach ($steps as $step) {
            $type = $step['type'];

            $agg[$type] ??= ['type' => $type, 'ms' => 0.0, 'events' => 0, 'worst_ms' => 0.0, 'share' => 0.0];
            $agg[$type]['ms'] += $step['dur_ms'];
            $agg[$type]['events']++;
            $agg[$type]['worst_ms'] = max($agg[$type]['worst_ms'], $step['dur_ms']);
        }

        foreach ($agg as $type => $row) {
            $agg[$type]['share'] = $total > 0 ? $row['ms'] / $total * 100 : 0.0;
        }

        usort($agg, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        return array_values($agg);
    }

    /**
     * @param list<array<string, mixed>> $steps
     * @return list<array<string, mixed>>
     */
    private function hotspots(array $steps): array
    {
        $slow = array_values(array_filter($steps, static fn (array $s): bool => $s['slow']));

        usort($slow, static fn (array $a, array $b): int => $b['dur_ms'] <=> $a['dur_ms']);

        return array_slice($slow, 0, 15);
    }
}
