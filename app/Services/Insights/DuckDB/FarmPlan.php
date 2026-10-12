<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;
use InvalidArgumentException;
use Saturio\DuckDB\DuckDB;

/**
 * One farm's active plan as a model to query: plan against actuals on
 * either basis, milk, and livestock, each one DuckDB statement over the lake
 * and MySQL. It keeps its DuckDB connection for the life of the process, so
 * a long-running caller (the MCP server) pays the lake's start-up once.
 */
final class FarmPlan
{
    private ?DuckDB $db = null;

    public function __construct(public readonly ActivePlan $plan)
    {
    }

    public static function forFarm(int $farmId): self
    {
        return new self(ActivePlan::forFarm($farmId));
    }

    /**
     * The farm, its plan, and the milk supplies and livestock mobs a question
     * can name.
     *
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        $mysql = InsightsDuckDb::mysql();
        $farmId = $this->plan->farmId;
        [$from, $to] = $this->plan->period(ReportBasis::Accrual);

        $farm = $mysql->selectOne(
            'SELECT f.id, f.name, f.region_primary AS region, ft.name AS farm_type,
                    f.financial_year_end_day AS balance_day, f.financial_year_end_month AS balance_month
             FROM farms f
             LEFT JOIN farms_operation_types fo ON fo.farm_id = f.id AND fo._valid_to IS NULL
             LEFT JOIN farm_types ft ON ft.uuid = fo.farm_type_uuid
             WHERE f.id = ? AND f._valid_to IS NULL',
            [$farmId],
        );

        $mobs = $mysql->select(
            'SELECT tr.id, tr.name, st.name AS stock_type, GROUP_CONCAT(DISTINCT sc.name ORDER BY sc.name) AS classes
             FROM trackers tr
             JOIN stock_types st ON st.uuid = tr.stock_type_uuid
             LEFT JOIN stock_transactions tx ON tx.tracker_id = tr.id AND tx._valid_to IS NULL
             LEFT JOIN stock_classes sc ON sc.uuid = tx.stock_class_uuid
             WHERE tr.farm_id = ? AND tr._valid_to IS NULL
             GROUP BY tr.id, tr.name, st.name
             ORDER BY tr.id',
            [$farmId],
        );

        return [
            'farm' => [
                'id' => (int) $farm->id,
                'name' => (string) $farm->name,
                'type' => $farm->farm_type,
                'region' => (string) $farm->region,
                'balance_date' => sprintf('%d %s', $farm->balance_day, date('F', mktime(0, 0, 0, (int) $farm->balance_month, 1))),
            ],
            'plan' => [
                'name' => $this->plan->name,
                'season' => $this->plan->season,
                'season_from' => $from,
                'season_to' => $to,
                'plan_as_at' => $this->plan->planHorizon,
                'actuals_to' => $this->plan->actualsTo,
            ],
            'milk_supplies' => array_map(
                static fn (object $t): array => ['tracker_id' => (int) $t->id, 'name' => (string) $t->name, 'company' => (string) $t->company],
                $mysql->select('SELECT id, name, company FROM milk_trackers WHERE farm_id = ? AND _valid_to IS NULL ORDER BY id', [$farmId]),
            ),
            'livestock_mobs' => array_map(
                static fn (object $m): array => [
                    'tracker_id' => (int) $m->id,
                    'name' => (string) $m->name,
                    'stock_type' => (string) $m->stock_type,
                    'classes' => $m->classes === null ? [] : explode(',', (string) $m->classes),
                ],
                $mobs,
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function comparison(ReportBasis $basis, ?string $period = null, bool $withCategories = true): array
    {
        $this->assertInSeason($period);

        return (new PlanComparisonQuery($this->db()))->run($this->plan, $basis, $period, $withCategories);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function milk(?string $period = null): array
    {
        $this->assertInSeason($period);
        $rows = DuckDbStatement::rows($this->db(), (new MilkProductionSqlBuilder($this->plan->farmId, $period))->build(), $this->bindings());

        return array_map(static fn (array $r): array => self::typed($r, ['tracker', 'period_type', 'period', 'status']), $rows);
    }

    /**
     * @param string $asAt any day in the month whose closing head is wanted
     * @return list<array<string, mixed>>
     */
    public function herd(string $asAt, ?int $trackerId = null): array
    {
        [$from, $to] = $this->plan->period(ReportBasis::Accrual);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $asAt) !== 1 || $asAt < $from || $asAt > $to) {
            throw new InvalidArgumentException("The valuation date must be a day in the plan's season, {$from} to {$to}.");
        }

        $rows = DuckDbStatement::rows(
            $this->db(),
            (new HerdValuationSqlBuilder($this->plan->farmId, $trackerId))->build(),
            $this->bindings() + ['as_at' => $asAt],
        );

        return array_map(static fn (array $r): array => self::typed($r, ['tracker', 'stock_type', 'stock_class', 'level']), $rows);
    }

    /**
     * Plan status: the headline bottom lines, and the signals ranked by
     * attention, with the farm's own notes for whoever judges them.
     *
     * @return array{headline: list<array<string, mixed>>, peer_group: array{farm_type: ?string, farms: int}, signals: list<array<string, mixed>>, notes: list<array{author: string, note: string, written: string}>}
     */
    public function status(int $limit = 8): array
    {
        [$from] = $this->plan->period(ReportBasis::Accrual);
        $peers = $this->peers();

        $headline = DuckDbStatement::rows(
            $this->db(),
            (new PlanHeadlineSqlBuilder($this->plan->farmId))->build(),
            $this->bindings() + ['opening_before' => $from],
        );
        $signals = DuckDbStatement::rows(
            $this->db(),
            (new PlanSignalsSqlBuilder($this->plan->farmId, $peers['farm_ids'], $limit))->build(),
            [
                'period_from' => date('Y-m-d', (int) strtotime($from.' -3 years')),
                PlanSignalsSqlBuilder::SEASON_FROM => $from,
            ] + $this->bindings(),
        );

        return [
            'headline' => array_map(static fn (array $r): array => self::typed($r, ['measure', 'period', 'status', 'effect', 'plan_month', 'current_month']), $headline),
            'peer_group' => ['farm_type' => $peers['farm_type'], 'farms' => count($peers['farm_ids']) - 1],
            'signals' => array_map(static fn (array $r): array => self::typed($r, ['level', 'section', 'row', 'effect']), $signals),
            'notes' => $this->notes(),
        ];
    }

    /** Context or a priority from the farmer or advisor, for plan status to weigh. */
    public function addNote(string $author, string $note): void
    {
        InsightsDuckDb::mysql()->insert(
            'INSERT INTO plan_notes (farm_id, author, note, created_at) VALUES (?, ?, ?, NOW())',
            [$this->plan->farmId, $author, $note],
        );
    }

    /**
     * @return list<array{author: string, note: string, written: string}>
     */
    private function notes(): array
    {
        return array_map(
            static fn (object $n): array => ['author' => (string) $n->author, 'note' => (string) $n->note, 'written' => (string) $n->created_at],
            InsightsDuckDb::mysql()->select('SELECT author, note, created_at FROM plan_notes WHERE farm_id = ? ORDER BY created_at, id', [$this->plan->farmId]),
        );
    }

    /**
     * Farms of the same type in the same practice, on the same plan dates,
     * this one included: the group a variance is compared across, the
     * advisor's own book.
     *
     * @return array{farm_type: ?string, farm_ids: non-empty-list<int>}
     */
    private function peers(): array
    {
        $mysql = InsightsDuckDb::mysql();
        $type = $mysql->selectOne(
            'SELECT fo.farm_type_uuid, ft.name FROM farms_operation_types fo JOIN farm_types ft ON ft.uuid = fo.farm_type_uuid
             WHERE fo.farm_id = ? AND fo._valid_to IS NULL LIMIT 1',
            [$this->plan->farmId],
        );
        $ids = array_map(static fn (object $r): int => (int) $r->farm_id, $mysql->select(
            'SELECT ap.farm_id FROM active_plans ap
             JOIN farms_operation_types fo ON fo.farm_id = ap.farm_id AND fo._valid_to IS NULL
             JOIN farm_practice fp ON fp.farm_id = ap.farm_id
             WHERE ap.season = ? AND ap.plan_horizon = ? AND ap.actuals_to = ? AND fo.farm_type_uuid = ?
               AND fp.practice_id IN (SELECT practice_id FROM farm_practice WHERE farm_id = ?)
             GROUP BY ap.farm_id
             ORDER BY ap.farm_id LIMIT 500',
            [$this->plan->season, $this->plan->planHorizon, $this->plan->actualsTo, $type?->farm_type_uuid, $this->plan->farmId],
        ));

        return [
            'farm_type' => $type?->name,
            'farm_ids' => in_array($this->plan->farmId, $ids, true) ? $ids : [$this->plan->farmId, ...$ids],
        ];
    }

    /** A month outside the season would come back empty rather than wrong; say so instead. */
    private function assertInSeason(?string $period): void
    {
        if ($period === null || preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
            return;
        }
        [$from, $to] = $this->plan->period(ReportBasis::Accrual);
        if ($period < substr($from, 0, 7) || $period > substr($to, 0, 7)) {
            throw new InvalidArgumentException(sprintf('The plan season runs from %s to %s; %s is outside it.', substr($from, 0, 7), substr($to, 0, 7), $period));
        }
    }

    /**
     * @return array<string, string>
     */
    private function bindings(): array
    {
        [$from, $to] = $this->plan->period(ReportBasis::Accrual);

        return [
            PlanComparisonSqlBuilder::PLAN_HORIZON => $this->plan->planHorizon,
            PlanComparisonSqlBuilder::CURRENT_HORIZON => $this->plan->actualsTo,
            'period_from' => $from,
            'period_to' => $to,
        ];
    }

    /**
     * DuckDB hands every value back as text: numbers become numbers, the
     * named text columns stay text.
     *
     * @param array<string, ?string> $row
     * @param list<string> $text
     * @return array<string, mixed>
     */
    private static function typed(array $row, array $text): array
    {
        $typed = [];
        foreach ($row as $column => $value) {
            $typed[$column] = match (true) {
                $value === null, in_array($column, $text, true) => $value,
                ctype_digit(ltrim($value, '-')) => (int) $value,
                default => (float) $value,
            };
        }

        return $typed;
    }

    private function db(): DuckDB
    {
        return $this->db ??= InsightsDuckDb::connect();
    }
}
