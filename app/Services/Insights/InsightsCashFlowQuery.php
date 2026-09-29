<?php

declare(strict_types=1);

namespace App\Services\Insights;

use Illuminate\Database\ConnectionInterface;

/**
 * Runs the single-farm cash flow over the `insights` tables.
 */
final class InsightsCashFlowQuery
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
    }

    public function sql(): string
    {
        return (new InsightsCashFlowSqlBuilder())->build();
    }

    /**
     * @return list<array{kind: string, field: string, label: string, category: ?string, interval_index: int, month: string, column_type: string, amount: float}>
     */
    public function run(int $farmId, string $periodFrom, string $periodTo, string $horizon): array
    {
        InsightsSession::configure($this->db);

        $rows = $this->db->select($this->sql(), [
            'farm_ids' => '{'.$farmId.'}',
            'horizon' => $horizon,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'opening_before' => $periodFrom,
        ]);

        return array_map(static fn (object $r): array => [
            'kind' => (string) $r->kind,
            'field' => (string) $r->field,
            'label' => (string) $r->label,
            'category' => $r->category === null ? null : (string) $r->category,
            'interval_index' => (int) $r->interval_index,
            'month' => (string) $r->month,
            'column_type' => (string) $r->column_type,
            'amount' => (float) $r->amount,
        ], $rows);
    }
}
