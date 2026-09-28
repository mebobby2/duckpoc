<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use App\Services\CashFlow\Definition\CashFlowActualsForecastReportDefinition;
use Saturio\DuckDB\DuckDB;
use Saturio\DuckDB\Type\Type;

/**
 * Runs the actuals-plus-forecast Cash Flow. Prepares, binds, executes;
 * the report's meaning is in the definition and its SQL in the builder.
 */
final class CashFlowActualsForecastQuery implements CashFlowActualsForecastReport
{
    private readonly CashFlowActualsForecastReportDefinition $definition;

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
        private readonly string $appAlias,
        private readonly CashFlowActualsForecastOptions $options,
        ?CashFlowActualsForecastReportDefinition $definition = null,
    ) {
        $this->definition = $definition ?? new CashFlowActualsForecastReportDefinition();
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
        return $this->builder()->build();
    }

    /**
     * One row per (scope, column): scope `farm` for the consolidated report,
     * a tracker id for each tracker block; a month or `Total` per column.
     *
     * @return list<array<string, mixed>>
     */
    public function run(string $farmId, ReportPeriod $period, string $horizon): array
    {
        $statement = $this->db->preparedStatement($this->sql());
        $this->bindScope($statement, $farmId, $period, $horizon);

        return iterator_to_array($statement->execute()->rows(true));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function virtualJournals(string $farmId, ReportPeriod $period, string $horizon, int $limit = 500): array
    {
        $statement = $this->db->preparedStatement($this->builder()->buildVirtualJournalsSql());
        $this->bindScope($statement, $farmId, $period, $horizon);
        $statement->bindParam('row_limit', $limit, Type::DUCKDB_TYPE_BIGINT);

        return iterator_to_array($statement->execute()->rows(true));
    }

    /**
     * @return array<string, array{n: int, net_dollars: float}> keyed by handler
     */
    public function virtualJournalSummary(string $farmId, ReportPeriod $period, string $horizon): array
    {
        $statement = $this->db->preparedStatement($this->builder()->buildVirtualJournalSummarySql());
        $this->bindScope($statement, $farmId, $period, $horizon);

        $summary = [];
        foreach ($statement->execute()->rows(true) as $row) {
            $summary[(string) $row['source']] = [
                'n' => (int) (string) $row['n'],
                'net_dollars' => (float) (string) $row['net_dollars'],
            ];
        }

        return $summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sourceRows(string $farmId, ReportPeriod $period, string $horizon, int $limit = 500): array
    {
        $statement = $this->db->preparedStatement($this->builder()->buildSourceRowsSql());
        $this->bindScope($statement, $farmId, $period, $horizon);
        $statement->bindParam('row_limit', $limit, Type::DUCKDB_TYPE_BIGINT);

        return iterator_to_array($statement->execute()->rows(true));
    }

    /**
     * @return array{n: int, n_tracker_tagged: int, net_dollars: float, period_from: string, period_to: string}
     */
    public function sourceRowSummary(string $farmId, ReportPeriod $period, string $horizon): array
    {
        $statement = $this->db->preparedStatement($this->builder()->buildSourceRowCountSql());
        $this->bindScope($statement, $farmId, $period, $horizon);

        $row = iterator_to_array($statement->execute()->rows(true))[0] ?? [];

        return [
            'n' => (int) (string) ($row['n'] ?? 0),
            'n_tracker_tagged' => (int) (string) ($row['n_tracker_tagged'] ?? 0),
            'net_dollars' => (float) (string) ($row['net_dollars'] ?? 0),
            'period_from' => (string) ($row['period_from'] ?? ''),
            'period_to' => (string) ($row['period_to'] ?? ''),
        ];
    }

    private function builder(): CashFlowActualsForecastSqlBuilder
    {
        return new CashFlowActualsForecastSqlBuilder($this->definition, $this->alias, $this->appAlias, $this->options);
    }

    /**
     * Everything bound as VARCHAR and cast in the SQL — the driver cannot
     * infer a type for a parameter wrapped in CAST.
     */
    private function bindScope(object $statement, string $farmId, ReportPeriod $period, string $horizon): void
    {
        foreach ([
            'farm_id' => $farmId,
            'period_from' => $period->from,
            'period_to' => $period->to,
            'horizon' => $horizon,
            'basis' => $this->options->basis,
        ] as $parameter => $value) {
            $statement->bindParam($parameter, $value, Type::DUCKDB_TYPE_VARCHAR);
        }
    }
}
