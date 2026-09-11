<?php

declare(strict_types=1);

namespace App\Services\AlloyDb;

use Illuminate\Database\ConnectionInterface;

/**
 * Runs the ported Gross Margin V2 query against AlloyDB.
 *
 * Returns plain arrays in the same shape as `GrossMarginV2Query` so the report
 * controller and Blade view can be reused verbatim — which is also the point:
 * if the two engines return identical rows, the comparison is honest.
 */
final class GrossMarginV2PgQuery
{
    private readonly GrossMarginV2PgSqlBuilder $builder;

    public function __construct(
        private readonly ConnectionInterface $db,
        ?GrossMarginV2PgSqlBuilder $builder = null,
    ) {
        $this->builder = $builder ?? new GrossMarginV2PgSqlBuilder();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function run(
        string $farmId,
        string $periodFrom,
        string $periodTo,
        string $horizon,
        string $basis = 'cash',
    ): array {
        return array_map(
            static fn (object $row): array => (array) $row,
            $this->db->select($this->builder->build(), [
                'farm_id' => $farmId,
                'farm_id2' => $farmId,
                'farm_id3' => $farmId,
                'basis' => $basis,
                'period_from' => $periodFrom,
                'period_from2' => $periodFrom,
                'period_to' => $periodTo,
                'period_to2' => $periodTo,
                'horizon' => $horizon,
                'horizon2' => $horizon,
                'horizon3' => $horizon,
            ])
        );
    }

    /**
     * @return array{n: int, n_groups: int, n_trackers: int, net_dollars: float}
     */
    public function sourceRowSummary(
        string $farmId,
        string $periodFrom,
        string $periodTo,
        string $horizon,
        string $basis = 'cash',
    ): array {
        $row = $this->db->selectOne($this->builder->buildSourceRowCountSql(), [
            'farm_id' => $farmId,
            'basis' => $basis,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'horizon' => $horizon,
            'horizon2' => $horizon,
        ]);

        return [
            'n' => (int) ($row->n ?? 0),
            'n_groups' => (int) ($row->n_groups ?? 0),
            'n_trackers' => (int) ($row->n_trackers ?? 0),
            'net_dollars' => (float) ($row->net_dollars ?? 0),
        ];
    }

    public function sql(): string
    {
        return $this->builder->build();
    }
}
