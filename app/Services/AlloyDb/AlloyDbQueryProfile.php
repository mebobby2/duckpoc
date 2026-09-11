<?php

declare(strict_types=1);

namespace App\Services\AlloyDb;

use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * The diagnostics that matter for an in-memory columnar engine.
 *
 * One question dominates all the others: **did the columnar engine actually
 * serve this query, or did it fall back to a heap scan?** The column store is
 * memory-resident with a fixed budget, so a query that outgrows it degrades
 * silently to row-store scanning — same answers, an order of magnitude slower.
 * `usedColumnarScan()` reads that off the plan rather than inferring it from
 * timing.
 *
 * The rest follows from the same concern:
 *
 * - **Buffer hits vs reads** tell you whether pages came from shared_buffers or
 *   from storage. This is the analogue of asking where the bytes came from.
 * - **Column store used vs budget** is the capacity headroom, and the ratio of
 *   store size to row count is what predicts the ceiling at any volume.
 * - **Planning vs execution time** separates the optimiser's cost from the
 *   scan's, which matters because the query has nine CTEs and a GROUPING SETS
 *   aggregate — non-trivial to plan.
 */
final class AlloyDbQueryProfile
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
    }

    /**
     * @param array<string, mixed> $bindings
     * @return array{
     *     plan: string, columnar_scan: bool, columnar_nodes: list<string>,
     *     execution_ms: float|null, planning_ms: float|null,
     *     shared_hit: int, shared_read: int, temp_written: int,
     *     error: string|null
     * }
     */
    public function explain(string $sql, array $bindings): array
    {
        $empty = [
            'plan' => '', 'columnar_scan' => false, 'columnar_nodes' => [],
            'execution_ms' => null, 'planning_ms' => null,
            'shared_hit' => 0, 'shared_read' => 0, 'temp_written' => 0,
            'error' => null,
        ];

        try {
            $rows = $this->db->select(
                'EXPLAIN (ANALYZE, BUFFERS, VERBOSE, FORMAT JSON) '.$sql,
                $bindings
            );
        } catch (Throwable $e) {
            return [...$empty, 'error' => $e->getMessage()];
        }

        $raw = (array) ($rows[0] ?? []);
        $json = (string) (reset($raw) ?: '[]');
        $decoded = json_decode($json, true);

        if (!is_array($decoded) || !isset($decoded[0])) {
            return [...$empty, 'error' => 'Could not parse the plan'];
        }

        $root = $decoded[0];
        $plan = $root['Plan'] ?? [];

        $nodes = [];
        $buffers = ['shared_hit' => 0, 'shared_read' => 0, 'temp_written' => 0];
        $this->walk($plan, $nodes, $buffers);

        $columnar = array_values(array_filter(
            $nodes,
            static fn (string $n): bool => stripos($n, 'columnar') !== false
        ));

        return [
            'plan' => $this->render($plan, 0),
            'columnar_scan' => $columnar !== [],
            'columnar_nodes' => $columnar,
            'execution_ms' => isset($root['Execution Time']) ? (float) $root['Execution Time'] : null,
            'planning_ms' => isset($root['Planning Time']) ? (float) $root['Planning Time'] : null,
            ...$buffers,
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @param list<string> $nodes
     * @param array{shared_hit: int, shared_read: int, temp_written: int} $buffers
     */
    private function walk(array $plan, array &$nodes, array &$buffers): void
    {
        $label = (string) ($plan['Node Type'] ?? '');

        if (($custom = $plan['Custom Plan Provider'] ?? null) !== null) {
            $label .= ' ('.$custom.')';
        }

        if ($label !== '') {
            $nodes[] = $label;
        }

        $buffers['shared_hit'] += (int) ($plan['Shared Hit Blocks'] ?? 0);
        $buffers['shared_read'] += (int) ($plan['Shared Read Blocks'] ?? 0);
        $buffers['temp_written'] += (int) ($plan['Temp Written Blocks'] ?? 0);

        foreach ($plan['Plans'] ?? [] as $child) {
            if (is_array($child)) {
                $this->walk($child, $nodes, $buffers);
            }
        }
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function render(array $plan, int $depth): string
    {
        $label = (string) ($plan['Node Type'] ?? '?');

        if (($custom = $plan['Custom Plan Provider'] ?? null) !== null) {
            $label .= ' ('.$custom.')';
        }

        $detail = [];

        if (isset($plan['Relation Name'])) {
            $detail[] = 'on '.$plan['Relation Name'];
        }
        if (isset($plan['Actual Total Time'])) {
            $detail[] = sprintf('%.1f ms', (float) $plan['Actual Total Time']);
        }
        if (isset($plan['Actual Rows'])) {
            $detail[] = number_format((float) $plan['Actual Rows']).' rows';
        }
        if (isset($plan['Actual Loops']) && (int) $plan['Actual Loops'] > 1) {
            $detail[] = $plan['Actual Loops'].' loops';
        }

        $line = str_repeat('  ', $depth).'-> '.$label;

        if ($detail !== []) {
            $line .= '  ('.implode(', ', $detail).')';
        }

        $out = [$line];

        foreach ($plan['Plans'] ?? [] as $child) {
            if (is_array($child)) {
                $out[] = $this->render($child, $depth + 1);
            }
        }

        return implode("\n", $out);
    }

    /**
     * State of the in-memory column store.
     *
     * @return array{columns: list<array<string, mixed>>, used_bytes: int, budget_mb: int, table_bytes: int, row_estimate: int}
     */
    public function columnarState(): array
    {
        $columns = [];
        $used = 0;

        try {
            $columns = array_map(
                static fn (object $r): array => (array) $r,
                $this->db->select(
                    "SELECT column_name, column_type, status,
                            size_in_bytes,
                            pg_size_pretty(size_in_bytes) AS in_memory,
                            num_times_accessed
                     FROM g_columnar_columns
                     WHERE relation_name = 'transaction_lines'
                     ORDER BY size_in_bytes DESC"
                )
            );

            foreach ($columns as $column) {
                $used += (int) ($column['size_in_bytes'] ?? 0);
            }
        } catch (Throwable) {
            // The engine may be off; the page states that rather than failing.
        }

        $budget = $this->db->selectOne(
            "SELECT setting FROM pg_settings WHERE name = 'google_columnar_engine.memory_size_in_mb'"
        );

        $table = $this->db->selectOne(
            "SELECT pg_total_relation_size('transaction_lines') AS bytes,
                    (SELECT reltuples::BIGINT FROM pg_class WHERE relname = 'transaction_lines') AS rows"
        );

        return [
            'columns' => $columns,
            'used_bytes' => $used,
            'budget_mb' => (int) ($budget->setting ?? 0),
            'table_bytes' => (int) ($table->bytes ?? 0),
            'row_estimate' => (int) ($table->rows ?? 0),
        ];
    }
}
