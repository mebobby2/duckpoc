<?php

declare(strict_types=1);

namespace App\Services\AlloyDb;

use App\Services\CashFlow\CashFlowActualsForecastOptions;
use App\Services\CashFlow\CashFlowActualsForecastReport;
use App\Services\CashFlow\Definition\CashFlowActualsForecastReportDefinition;
use App\Services\CashFlow\ReportPeriod;
use Illuminate\Database\ConnectionInterface;

/**
 * Runs the actuals-plus-forecast Cash Flow against AlloyDB. The same five
 * parameters as the lake's query, bound as PDO named placeholders.
 */
final class CashFlowActualsForecastPgQuery implements CashFlowActualsForecastReport
{
    /**
     * The lake's DuckDB runs this statement on all 16 threads; PostgreSQL's
     * defaults give it 2 parallel workers, capped at 8 server-wide. Raised
     * for this session only, so the server settings behind the earlier
     * Gross Margin measurements are untouched. On the whole 500M-line farm
     * the scan went from 4.27 s at 2 workers to 1.56 s at 8 and 1.36 s at the
     * 10 the planner launches from here.
     */
    private const int PARALLEL_WORKERS = 16;

    private bool $sessionConfigured = false;

    private readonly CashFlowActualsForecastReportDefinition $definition;

    private readonly CashFlowActualsForecastPgSqlBuilder $builder;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CashFlowActualsForecastOptions $options,
        ?CashFlowActualsForecastReportDefinition $definition = null,
    ) {
        $this->definition = $definition ?? new CashFlowActualsForecastReportDefinition();
        $this->builder = new CashFlowActualsForecastPgSqlBuilder($this->definition, $this->options);
    }

    public function definition(): CashFlowActualsForecastReportDefinition
    {
        return $this->definition;
    }

    public function options(): CashFlowActualsForecastOptions
    {
        return $this->options;
    }

    public function sql(): string
    {
        return $this->builder->build();
    }

    public function run(string $farmId, ReportPeriod $period, string $horizon): array
    {
        return $this->select($this->builder->build(), $this->scope($farmId, $period, $horizon));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function virtualJournals(string $farmId, ReportPeriod $period, string $horizon, int $limit = 500): array
    {
        return $this->select($this->builder->buildVirtualJournalsSql(), $this->scope($farmId, $period, $horizon) + ['row_limit' => $limit]);
    }

    /**
     * @return array<string, array{n: int, net_dollars: float}>
     */
    public function virtualJournalSummary(string $farmId, ReportPeriod $period, string $horizon): array
    {
        $summary = [];
        foreach ($this->select($this->builder->buildVirtualJournalSummarySql(), $this->scope($farmId, $period, $horizon)) as $row) {
            $summary[(string) $row['source']] = ['n' => (int) $row['n'], 'net_dollars' => (float) $row['net_dollars']];
        }

        return $summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sourceRows(string $farmId, ReportPeriod $period, string $horizon, int $limit = 500): array
    {
        return $this->select($this->builder->buildSourceRowsSql(), $this->scope($farmId, $period, $horizon) + ['row_limit' => $limit]);
    }

    /**
     * @return array{n: int, n_tracker_tagged: int, net_dollars: float, period_from: string, period_to: string}
     */
    public function sourceRowSummary(string $farmId, ReportPeriod $period, string $horizon): array
    {
        $row = $this->select($this->builder->buildSourceRowCountSql(), $this->scope($farmId, $period, $horizon))[0] ?? [];

        return [
            'n' => (int) ($row['n'] ?? 0),
            'n_tracker_tagged' => (int) ($row['n_tracker_tagged'] ?? 0),
            'net_dollars' => (float) ($row['net_dollars'] ?? 0),
            'period_from' => (string) ($row['period_from'] ?? ''),
            'period_to' => (string) ($row['period_to'] ?? ''),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function scope(string $farmId, ReportPeriod $period, string $horizon): array
    {
        return [
            'farm_id' => $farmId,
            'period_from' => $period->from,
            'period_to' => $period->to,
            'horizon' => $horizon,
            'basis' => $this->options->basis,
        ];
    }

    /**
     * @param array<string, int|string> $bindings
     * @return list<array<string, mixed>>
     */
    private function select(string $sql, array $bindings): array
    {
        if (!$this->sessionConfigured) {
            $this->db->statement(sprintf('SET max_parallel_workers = %d', self::PARALLEL_WORKERS));
            $this->db->statement(sprintf('SET max_parallel_workers_per_gather = %d', self::PARALLEL_WORKERS - 1));
            $this->sessionConfigured = true;
        }

        return array_map(static fn (object $row): array => (array) $row, $this->db->select($sql, $bindings));
    }
}
