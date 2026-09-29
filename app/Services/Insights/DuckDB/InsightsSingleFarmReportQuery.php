<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;
use Saturio\DuckDB\DuckDB;

/**
 * Runs a single-farm monthly report on DuckDB: the cash flow on cash basis,
 * the profit and loss on accrual.
 */
final class InsightsSingleFarmReportQuery
{
    public function __construct(
        private readonly DuckDB $db,
        private readonly ReportBasis $basis = ReportBasis::Cash,
    ) {
    }

    public function builder(int $farmId): InsightsMonthlyReportSqlBuilder
    {
        return match ($this->basis) {
            ReportBasis::Cash => new InsightsCashFlowSqlBuilder($farmId),
            ReportBasis::Accrual => new InsightsProfitLossSqlBuilder($farmId),
        };
    }

    /**
     * @return list<array{kind: string, field: string, label: string, category: ?string, interval_index: int, month: string, column_type: string, amount: float}>
     */
    public function run(int $farmId, string $periodFrom, string $periodTo, string $horizon): array
    {
        $bindings = ['horizon' => $horizon, 'period_from' => $periodFrom, 'period_to' => $periodTo];
        if ($this->basis === ReportBasis::Cash) {
            $bindings['opening_before'] = $periodFrom;
        }

        return array_map(static fn (array $r): array => [
            'kind' => (string) $r['kind'],
            'field' => (string) $r['field'],
            'label' => (string) $r['label'],
            'category' => $r['category'],
            'interval_index' => (int) $r['interval_index'],
            'month' => (string) $r['month'],
            'column_type' => (string) $r['column_type'],
            'amount' => (float) $r['amount'],
        ], DuckDbStatement::rows($this->db, $this->builder($farmId)->build(), $bindings));
    }
}
