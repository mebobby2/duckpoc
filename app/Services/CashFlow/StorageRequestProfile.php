<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Explains how many range requests a report's Parquet reads will cost, and
 * how many network connections it actually opened.
 *
 * Exists because request count — not data volume — turned out to be the thing
 * that decides how long a report takes over object storage. DuckDB issues one
 * HTTP range request per (row group x column), so the physical layout of the
 * files predicts the cost before the query runs.
 *
 * Two different numbers here, and they are not interchangeable:
 *
 * - **Predicted requests** — arithmetic over Parquet footers: row groups in
 *   the in-scope files, times the columns the report reads. No query needed,
 *   and it is what row group tuning changes. On the billion-row farm this
 *   predicted ~1,000 against 1,014 actually observed by EXPLAIN ANALYZE.
 * - **Measured requests** — `HTTPFSInfo` log entries for the queries that
 *   just ran. Each entry is a connection-cache hit or miss, and DuckDB
 *   consults that cache once per range request, so the count tracks requests
 *   almost exactly. Validated against `EXPLAIN ANALYZE`'s own counter on two
 *   independent windows: 1,014 GETs vs 1,024 events, and 1,104 vs 1,115 —
 *   within 1% both times. That is close enough to report as the request
 *   count, and unlike EXPLAIN ANALYZE it costs nothing, because the report
 *   has already run.
 *
 * The predicted figure is an **upper bound**, not an estimate: it assumes one
 * request per column chunk, while DuckDB coalesces adjacent chunks when
 * prefetching. On the billion-row farm it predicted 1,692 against 1,014
 * measured — the right order, ~1.7x high. Use the measured number for
 * reality and the prediction only to reason about what layout changes would
 * do.
 */
final class StorageRequestProfile
{
    /**
     * Columns the tracker report actually reads from `transaction_lines`.
     *
     * Predicate columns (farm_id, farm_type, region, basis, type) compress to
     * near nothing and are constant per file, but they are still separate
     * column chunks and so still cost a request each. `line_id` is excluded
     * deliberately — it is ~80% of each file's bytes and the report never
     * touches it, which is why column pruning matters so much here.
     */
    private const array COLUMNS_READ = [
        'farm_id', 'farm_type', 'region', 'basis', 'type', 'date', 'account_id', 'amount', 'tracker_id',
    ];

    public function __construct(
        private readonly DuckDB $db,
    ) {
    }

    /**
     * Call before running the report, so connection events can be attributed.
     */
    public function startLogging(): void
    {
        try {
            $this->db->query('SET enable_logging = true');
            $this->db->query("SET enabled_log_types = 'HTTPFSInfo'");
        } catch (Throwable) {
            // Logging is a diagnostic nicety; never let it break the report.
        }
    }

    /**
     * @param list<array<string, mixed>> $files output of ParquetFileLister
     * @return array{
     *     files_in_scope: int, row_groups: null|int, columns_read: int,
     *     predicted_requests: null|int, connection_events: null|int,
     *     connection_misses: null|int, rows_in_files: null|int, note: null|string
     * }
     */
    public function summarise(array $files): array
    {
        $inScope = array_values(array_filter($files, static fn (array $f): bool => (bool) $f['in_scope']));
        $columns = count(self::COLUMNS_READ);

        $rowGroups = null;
        $rows = null;
        $note = null;

        if ($inScope !== []) {
            try {
                [$rowGroups, $rows] = $this->rowGroupTotals(array_column($inScope, 'path'));
            } catch (Throwable $e) {
                $note = 'Could not read Parquet footers: '.$e->getMessage();
            }
        }

        return [
            'files_in_scope' => count($inScope),
            'row_groups' => $rowGroups,
            'columns_read' => $columns,
            'predicted_requests' => $rowGroups === null ? null : $rowGroups * $columns,
            'connection_events' => $this->logCount(null),
            'connection_misses' => $this->logCount('connection_cache_miss'),
            'rows_in_files' => $rows,
            'note' => $note,
        ];
    }

    /**
     * @param list<string> $paths
     * @return array{0: int, 1: int} row groups, rows
     */
    private function rowGroupTotals(array $paths): array
    {
        $list = "['".implode("','", array_map(
            static fn (string $p): string => str_replace("'", "''", $p),
            $paths,
        ))."']";

        $sql = <<<SQL
            SELECT
                CAST(count(DISTINCT (file_name, row_group_id)) AS BIGINT) AS row_groups,
                CAST(sum(row_group_num_rows) FILTER (
                    WHERE column_id = 0
                ) AS BIGINT) AS rows
            FROM parquet_metadata({$list})
            SQL;

        foreach ($this->db->query($sql)->rows(true) as $row) {
            return [
                (int) (string) $row['row_groups'],
                (int) (string) $row['rows'],
            ];
        }

        return [0, 0];
    }

    private function logCount(?string $kind): ?int
    {
        $where = "type = 'HTTPFSInfo'";

        if ($kind !== null) {
            $where .= " AND json_extract_string(message, '\$.type') = '".$kind."'";
        }

        try {
            foreach ($this->db->query("SELECT CAST(count(*) AS BIGINT) n FROM duckdb_logs WHERE {$where}")->rows(true) as $row) {
                return (int) (string) $row['n'];
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
