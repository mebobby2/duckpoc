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

    /**
     * What a scenario does to the plan, worked out by Figured's own reports:
     * the bottom lines and every changed row on plan, current and scenario,
     * the month-end bank balance through the season, and what each change
     * books. Nothing is saved.
     *
     * @param mixed $changes as ScenarioChanges takes them
     * @return array{headline: list<array<string, mixed>>, changed_rows: list<array<string, mixed>>, bank_by_month: list<array<string, mixed>>, booked: list<array<string, mixed>>}
     */
    public function modelScenario(mixed $changes): array
    {
        return $this->evaluate($this->scenarioChanges($changes));
    }

    /**
     * Saves a scenario for the farmer or advisor to come back to, or adopt.
     *
     * @return array{scenario_id: int}
     */
    public function saveScenario(string $name, mixed $changes): array
    {
        $scenario = $this->scenarioChanges($changes);
        $id = InsightsDuckDb::mysql()->table('plan_scenarios')->insertGetId([
            'farm_id' => $this->plan->farmId,
            'name' => $name,
            'changes' => $scenario->json(),
            'status' => 'draft',
            'created_at' => now(),
        ]);

        return ['scenario_id' => (int) $id];
    }

    /**
     * @return list<array{scenario_id: int, name: string, status: string, saved: string, adopted: ?string, changes: list<array<string, mixed>>}>
     */
    public function scenarios(): array
    {
        return array_map(
            static fn (object $s): array => [
                'scenario_id' => (int) $s->id,
                'name' => (string) $s->name,
                'status' => (string) $s->status,
                'saved' => (string) $s->created_at,
                'adopted' => $s->adopted_at,
                'changes' => json_decode((string) $s->changes, true, 512, JSON_THROW_ON_ERROR),
            ],
            InsightsDuckDb::mysql()->select(
                'SELECT id, name, status, created_at, adopted_at, changes FROM plan_scenarios WHERE farm_id = ? ORDER BY id',
                [$this->plan->farmId],
            ),
        );
    }

    /**
     * Writes a saved scenario into the farm's forecast: its journal lines
     * join the forecast every current-side report reads, the agreed plan
     * stays as it was, and a plan note records that the change was chosen.
     *
     * @return array{scenario_id: int, name: string, lines_written: int, note: string}
     */
    public function adoptScenario(int $scenarioId): array
    {
        $mysql = InsightsDuckDb::mysql();
        $saved = $mysql->selectOne('SELECT id, name, status, changes FROM plan_scenarios WHERE id = ? AND farm_id = ?', [$scenarioId, $this->plan->farmId])
            ?? throw new InvalidArgumentException("This farm has no scenario {$scenarioId}.");
        if ($saved->status !== 'draft') {
            throw new InvalidArgumentException("Scenario {$scenarioId} is already {$saved->status}.");
        }
        $scenario = $this->scenarioChanges(json_decode((string) $saved->changes, true, 512, JSON_THROW_ON_ERROR));

        $lines = DuckDbStatement::rows(
            $this->db(),
            ReportLinesSqlBuilder::statement(
                $this->proposalLines()->editCtes(ReportLinesSqlBuilder::periodWindow()),
                "SELECT basis, date, account_id, net_amount, tax_amount FROM forecast_edits WHERE source = 'proposed'",
            ),
            $this->proposalBindings($scenario),
        );
        $note = sprintf('Adopted the "%s" scenario into the forecast: %s.', $saved->name, $scenario->describe());

        $mysql->transaction(function () use ($mysql, $lines, $scenarioId, $note): void {
            foreach (array_chunk($lines, 500) as $chunk) {
                $mysql->table('forecast_edit_lines')->insert(array_map(fn (array $l): array => [
                    'scenario_id' => $scenarioId,
                    'farm_id' => $this->plan->farmId,
                    'basis' => $l['basis'],
                    'date' => $l['date'],
                    'account_id' => $l['account_id'],
                    'net_amount' => (int) $l['net_amount'],
                    'tax_amount' => (int) $l['tax_amount'],
                ], $chunk));
            }
            $mysql->update("UPDATE plan_scenarios SET status = 'adopted', adopted_at = NOW() WHERE id = ?", [$scenarioId]);
            $this->addNote('advisor', $note);
        });

        return ['scenario_id' => $scenarioId, 'name' => (string) $saved->name, 'lines_written' => count($lines), 'note' => $note];
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

    /**
     * @return array{headline: list<array<string, mixed>>, changed_rows: list<array<string, mixed>>, bank_by_month: list<array<string, mixed>>, booked: list<array<string, mixed>>}
     */
    private function evaluate(ScenarioChanges $scenario): array
    {
        [$from] = $this->plan->period(ReportBasis::Accrual);
        $bindings = $this->bindings() + [ForecastEditsSqlBuilder::PROPOSAL => $scenario->json()];
        $withOpening = $bindings + ['opening_before' => $from];
        $text = ['kind', 'field', 'label', 'category', 'period_type', 'period', 'status', 'effect', 'scenario_effect'];

        $headline = DuckDbStatement::rows($this->db(), (new PlanHeadlineSqlBuilder($this->plan->farmId, withScenario: true))->build(), $withOpening);
        $rows = [];
        foreach ([ReportBasis::Accrual, ReportBasis::Cash] as $basis) {
            $sql = (new PlanComparisonSqlBuilder($this->plan->farmId, $basis, withScenario: true))->build();
            foreach (DuckDbStatement::rows($this->db(), $sql, $basis === ReportBasis::Cash ? $withOpening : $bindings) as $row) {
                $rows[] = ['report' => $basis === ReportBasis::Cash ? 'cash_flow' : 'profit_and_loss'] + self::typed($row, $text);
            }
        }
        $booked = DuckDbStatement::rows(
            $this->db(),
            ReportLinesSqlBuilder::statement(
                $this->proposalLines()->editCtes(ReportLinesSqlBuilder::periodWindow()),
                ForecastEditsSqlBuilder::workingsSelect(),
            ),
            $this->proposalBindings($scenario),
        );

        return [
            'headline' => array_map(
                static fn (array $r): array => self::typed($r, ['measure', 'period', 'status', 'effect', 'plan_month', 'current_month', 'scenario_effect', 'scenario_month']),
                $headline,
            ),
            'changed_rows' => array_values(array_map(
                static fn (array $r): array => [
                    'report' => $r['report'],
                    'row' => $r['kind'] === 'category' ? $r['category'] : $r['label'],
                    'kind' => $r['kind'],
                    'section' => $r['field'],
                    'plan' => $r['plan'],
                    'current' => $r['current'],
                    'scenario' => $r['scenario'],
                    'change' => $r['scenario_change'],
                    'effect' => $r['scenario_effect'],
                ],
                array_filter($rows, static fn (array $r): bool => $r['period_type'] === 'season' && $r['scenario_change'] != 0),
            )),
            'bank_by_month' => array_values(array_map(
                static fn (array $r): array => [
                    'month' => $r['period'],
                    'status' => $r['status'],
                    'plan' => $r['plan'],
                    'current' => $r['current'],
                    'scenario' => $r['scenario'],
                    'change' => $r['scenario_change'],
                ],
                array_filter($rows, static fn (array $r): bool => $r['report'] === 'cash_flow' && $r['kind'] === 'balance'
                    && $r['field'] === 'closing' && $r['period_type'] === 'month'),
            )),
            'booked' => array_map(static fn (array $r): array => self::typed($r, ['kind', 'category', 'first_month', 'last_month']), $booked),
        ];
    }

    /** Changes are to the forecast: from the month after actuals_to to the season's last. */
    private function scenarioChanges(mixed $changes): ScenarioChanges
    {
        [, $to] = $this->plan->period(ReportBasis::Accrual);

        return ScenarioChanges::fromInput(
            $changes,
            date('Y-m', (int) strtotime(substr($this->plan->actualsTo, 0, 7).'-01 +1 month')),
            substr($to, 0, 7),
            $this->adjustableCategories(),
        );
    }

    /**
     * The categories a scenario can change: the forecast's ledger
     * categories. Milk income and the livestock valuation come from
     * production and the herd, and the opening balance is not a forecast.
     *
     * @return list<string>
     */
    private function adjustableCategories(): array
    {
        $groups = [...ReportBasis::PROFIT_AND_LOSS_GROUPS, 'non_operating_movements', 'equity_movements'];

        return array_map(
            static fn (object $c): string => (string) $c->name,
            InsightsDuckDb::mysql()->select(
                sprintf(
                    "SELECT DISTINCT system_category_name AS name FROM categories
                     WHERE farm_id = ? AND _valid_to IS NULL AND milk_tracker_id IS NULL AND `group` IN (%s)
                       AND system_category_name NOT IN ('Livestock Valuation Change', 'Opening Balances')
                     ORDER BY system_category_name",
                    implode(', ', array_fill(0, count($groups), '?')),
                ),
                [$this->plan->farmId, ...$groups],
            ),
        );
    }

    private function proposalLines(): ReportLinesSqlBuilder
    {
        return new ReportLinesSqlBuilder([$this->plan->farmId], ReportBasis::Accrual, PlanComparisonSqlBuilder::CURRENT_HORIZON, ForecastEdits::AdoptedAndProposed);
    }

    /**
     * @return array<string, string>
     */
    private function proposalBindings(ScenarioChanges $scenario): array
    {
        [$from, $to] = $this->plan->period(ReportBasis::Accrual);

        return [
            PlanComparisonSqlBuilder::CURRENT_HORIZON => $this->plan->actualsTo,
            'period_from' => $from,
            'period_to' => $to,
            ForecastEditsSqlBuilder::PROPOSAL => $scenario->json(),
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
