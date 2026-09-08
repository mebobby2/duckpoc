<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Runs `EXPLAIN ANALYZE` on a report query and extracts the numbers that
 * actually explain where its time went.
 *
 * Built after three wrong diagnoses of the same slow report — first "it is
 * downloading gigabytes", then "it is request latency", then "it is the SQL".
 * Every one of those was a theory fitted to a single elapsed-time number.
 * DuckDB already knows the answer: its profile output carries the HTTPFS
 * request count, the bytes actually transferred, and per-operator timings.
 * The first run of this on the real query settled it in one shot — 4,248 GETs,
 * 26.5 MiB, 56.6s total, of which ~1.7s was SQL.
 *
 * `EXPLAIN ANALYZE` genuinely executes the query, so profiling costs the same
 * as running it. That is why this is opt-in per request rather than always on.
 *
 * Parameters are substituted as literals because `EXPLAIN ANALYZE` cannot run
 * against a prepared statement's placeholders. The values come from the report
 * scope — farm ids and dates resolved from the request — so they are quoted
 * here rather than interpolated raw.
 */
final class QueryProfiler
{
    public function __construct(
        private readonly DuckDB $db,
    ) {
    }

    /**
     * @param array<string, string> $literals parameter name => value
     * @return array{
     *     ok: bool, error: null|string, total_seconds: null|float, http_gets: null|int,
     *     bytes_in: null|string, operators: list<array{name: string, seconds: float}>,
     *     sql_seconds: null|float, raw: string
     * }
     */
    public function profile(string $sql, array $literals): array
    {
        $resolved = $this->substitute($sql, $literals);

        try {
            $raw = '';
            foreach ($this->db->query("EXPLAIN ANALYZE {$resolved}")->rows(true) as $row) {
                foreach ($row as $value) {
                    $raw .= (string) $value."\n";
                }
            }
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'total_seconds' => null,
                'http_gets' => null,
                'bytes_in' => null,
                'operators' => [],
                'sql_seconds' => null,
                'raw' => '',
            ];
        }

        $operators = $this->operators($raw);

        return [
            'ok' => true,
            'error' => null,
            'total_seconds' => $this->matchFloat('/Total Time:\s*([\d.]+)s/', $raw),
            'http_gets' => $this->matchInt('/#GET:\s*(\d+)/', $raw),
            'bytes_in' => $this->matchString('/in:\s*([\d.]+\s*\w+)/', $raw),
            'operators' => $operators,
            // What the query engine itself spent, as opposed to time blocked on
            // storage. The gap between this and total is the interesting part.
            'sql_seconds' => array_sum(array_column($operators, 'seconds')),
            'raw' => $raw,
        ];
    }

    /**
     * @param array<string, string> $literals
     */
    private function substitute(string $sql, array $literals): string
    {
        $replacements = [];
        foreach ($literals as $name => $value) {
            $replacements['$'.$name] = "'".str_replace("'", "''", $value)."'";
        }

        return str_replace(array_keys($replacements), array_values($replacements), $sql);
    }

    /**
     * Operator timings from the box-drawing plan.
     *
     * The plan prints an operator name and then its row count and elapsed time
     * a few lines below, both inside box borders — so a name is held until the
     * next timing line claims it.
     *
     * @return list<array{name: string, seconds: float}>
     */
    private function operators(string $raw): array
    {
        $found = [];
        $pending = null;

        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/│\s*([A-Z_][A-Z_ ]{3,})\s*│/', $line, $m)) {
                $pending = trim($m[1]);
            }

            if ($pending !== null && preg_match('/│\s*(\d+\.\d\d)s\s*│/', $line, $m)) {
                $found[] = ['name' => $pending, 'seconds' => (float) $m[1]];
                $pending = null;
            }
        }

        usort($found, static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

        return array_slice($found, 0, 12);
    }

    private function matchFloat(string $pattern, string $subject): ?float
    {
        return preg_match($pattern, $subject, $m) ? (float) $m[1] : null;
    }

    private function matchInt(string $pattern, string $subject): ?int
    {
        return preg_match($pattern, $subject, $m) ? (int) $m[1] : null;
    }

    private function matchString(string $pattern, string $subject): ?string
    {
        return preg_match($pattern, $subject, $m) ? trim($m[1]) : null;
    }
}
