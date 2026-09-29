<?php

declare(strict_types=1);

namespace App\Services\Insights\AlloyDB;

use App\Services\Insights\ReportBasis;
use Illuminate\Database\ConnectionInterface;

/**
 * Runs a single-farm monthly report over the `insights` tables: the cash
 * flow on cash basis, the profit and loss on accrual.
 */
final class InsightsSingleFarmReportQuery
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ReportBasis $basis = ReportBasis::Cash,
    ) {
    }

    public function builder(): InsightsMonthlyReportSqlBuilder
    {
        return match ($this->basis) {
            ReportBasis::Cash => new InsightsCashFlowSqlBuilder(),
            ReportBasis::Accrual => new InsightsProfitLossSqlBuilder(),
        };
    }

    /**
     * @return list<array{kind: string, field: string, label: string, category: ?string, interval_index: int, month: string, column_type: string, amount: float}>
     */
    public function run(int $farmId, string $periodFrom, string $periodTo, string $horizon): array
    {
        InsightsSession::configure($this->db);

        $bindings = [
            'farm_ids' => '{'.$farmId.'}',
            'horizon' => $horizon,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
        ];
        if ($this->basis === ReportBasis::Cash) {
            $bindings['opening_before'] = $periodFrom;
        }

        return array_map(static fn (object $r): array => [
            'kind' => (string) $r->kind,
            'field' => (string) $r->field,
            'label' => (string) $r->label,
            'category' => $r->category === null ? null : (string) $r->category,
            'interval_index' => (int) $r->interval_index,
            'month' => (string) $r->month,
            'column_type' => (string) $r->column_type,
            'amount' => (float) $r->amount,
        ], $this->db->select($this->builder()->build(), $bindings));
    }
}
