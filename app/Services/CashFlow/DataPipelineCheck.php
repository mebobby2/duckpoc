<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Saturio\DuckDB\DuckDB;
use Saturio\DuckDB\Type\Type;
use Throwable;

/**
 * Runs the pipeline statement stage by stage and diffs each against the
 * transliteration — the check `duckdb:pipeline` prints, packaged so the
 * overdraft page can render the same thing.
 */
final class DataPipelineCheck
{
    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
        private readonly string $appAlias,
    ) {
    }

    /**
     * @return array{
     *     stages: list<array{stage: string, cells: int, ms: float, ok: bool, detail: ?string}>,
     *     final: array<string, array<int, float>>,
     *     merged: array<string, array<int, int>>,
     *     months: list<string>,
     *     passed: int,
     *     oracle_ms: float,
     *     statement_ms: float,
     *     sql: string
     * }
     */
    public function run(string $farmId, string $from, string $to, string $horizon, PipelineOptions $options): array
    {
        $builder = new DataPipelineSqlBuilder($this->alias, $this->appAlias, $options);

        $t = hrtime(true);
        $expected = (new DataPipelineOracle($this->db, $this->alias))->run($farmId, $from, $to, $horizon, $options);
        $oracleMs = (hrtime(true) - $t) / 1e6;

        $stages = [];
        $passed = 0;
        $final = [];
        $merged = [];

        foreach (DataPipelineSqlBuilder::STAGES as $stage) {
            try {
                $t = hrtime(true);
                $actual = $this->stage($builder, $stage, $farmId, $from, $to, $horizon);
                $ms = (hrtime(true) - $t) / 1e6;
            } catch (Throwable $e) {
                $stages[] = ['stage' => $stage, 'cells' => 0, 'ms' => 0.0, 'ok' => false, 'detail' => substr($e->getMessage(), 0, 160)];
                continue;
            }

            // A farm with nothing in scope passes every stage trivially — both
            // sides agree on zeros. That is not parity, it is absence, and it
            // was mistaken for a result once. Refuse it at the scan.
            if ($stage === 'p02_scan' && $actual === []) {
                $stages[] = ['stage' => $stage, 'cells' => 0, 'ms' => $ms, 'ok' => false, 'detail' => 'scan is empty — no lines in scope for this farm and period; nothing to check'];
                continue;
            }

            $detail = $this->diff($actual, $expected[$stage] ?? [], $stage === 'p24_format');
            $ok = $detail === null;
            $passed += $ok ? 1 : 0;
            $stages[] = ['stage' => $stage, 'cells' => count($actual, COUNT_RECURSIVE) - count($actual), 'ms' => $ms, 'ok' => $ok, 'detail' => $detail];

            if ($stage === 'p24_format') {
                $final = $actual;
            }
            if ($stage === 'p08_merge_vj') {
                $merged = $actual;
            }
        }

        $t = hrtime(true);
        $this->stage($builder, 'p24_format', $farmId, $from, $to, $horizon);
        $statementMs = (hrtime(true) - $t) / 1e6;

        $months = [];
        $cursor = strtotime(substr($from, 0, 7).'-01');
        $end = strtotime(substr($to, 0, 7).'-01');
        while ($cursor <= $end) {
            $months[] = date('Y-m', $cursor);
            $cursor = strtotime(date('Y-m-d', $cursor).' +1 month');
        }

        ksort($final);
        ksort($merged);

        return [
            'stages' => $stages,
            'final' => $final,
            'merged' => $merged,
            'months' => $months,
            'passed' => $passed,
            'oracle_ms' => $oracleMs,
            'statement_ms' => $statementMs,
            'sql' => $builder->build(),
        ];
    }

    /** @return array<string, array<int, int|float>> */
    private function stage(DataPipelineSqlBuilder $builder, string $stage, string $farmId, string $from, string $to, string $horizon): array
    {
        $statement = $this->db->preparedStatement($builder->build($stage));
        foreach (['farm_id' => $farmId, 'period_from' => $from, 'period_to' => $to, 'horizon' => $horizon] as $p => $v) {
            $statement->bindParam($p, $v, Type::DUCKDB_TYPE_VARCHAR);
        }

        $out = [];
        foreach ($statement->execute()->rows(true) as $row) {
            $out[(string) $row['account_id']][(int) (string) $row['interval_index']] = $stage === 'p24_format'
                ? (float) (string) $row['amount']
                : (int) (string) $row['amount'];
        }

        return $out;
    }

    private function diff(array $actual, array $expected, bool $float): ?string
    {
        foreach ($expected as $id => $byIdx) {
            foreach ($byIdx as $idx => $want) {
                $got = $actual[$id][$idx] ?? null;
                if ($got === null) {
                    return "missing {$id}[{$idx}] (want {$want})";
                }
                if ($float ? abs($got - $want) >= 1e-6 : $got !== $want) {
                    return "{$id}[{$idx}] got {$got} want {$want}";
                }
            }
        }
        foreach ($actual as $id => $byIdx) {
            foreach ($byIdx as $idx => $got) {
                if (!isset($expected[$id][$idx]) && $got != 0) {
                    return "unexpected {$id}[{$idx}] = {$got}";
                }
            }
        }

        return null;
    }
}
