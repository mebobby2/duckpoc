<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;
use InvalidArgumentException;
use Saturio\DuckDB\DuckDB;

/**
 * One farm as a model to query: its reports in any version of the forecast
 * and any window, its cash outlook, its position, plan status, milk and
 * livestock, and its what-ifs, each one DuckDB statement over the lake and
 * MySQL. It keeps its DuckDB connection for the life of the process, so a
 * long-running caller (the MCP server) pays the lake's start-up once.
 *
 * A version is named the way the tools take it:
 *
 *   - `current`: actuals to date, then the forecast as it stands now;
 *   - `budget` (or `plan`): the forecast as agreed, actuals to the plan horizon;
 *   - `actual`: actuals only, nothing forecast;
 *   - `last_year`: the same months a year earlier, as they stand now;
 *   - `season:YYYY`: the same months of another season (actuals for a
 *     past one), lined up month by month;
 *   - `snapshot:N`: a saved snapshot, the forecast as it stood when taken;
 *   - `scenario:N`: a saved scenario on top of the current forecast.
 *
 * Choosing versions, windows, enterprises and areas is all this class does;
 * every number is computed in SQL.
 */
final class FarmPlan
{
    /** The first and last seasons the ledger covers in full. */
    public const int FIRST_SEASON = 2024;
    public const int LAST_SEASON = 2029;

    private const string LEDGER_OPENS = InsightsDuckDbPracticeSeeder::OPENING_DATE;
    private const string LEDGER_ENDS = '2029-06-30';

    private const int MAX_MONTHS = 24;

    private ?DuckDB $db = null;

    public function __construct(public readonly ActivePlan $plan)
    {
    }

    public static function forFarm(int $farmId): self
    {
        return new self(ActivePlan::forFarm($farmId));
    }

    /**
     * The farm, its plan, enterprises, overdraft, saved snapshots, and the
     * milk supplies and livestock mobs a question can name.
     *
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        $mysql = InsightsDuckDb::mysql();
        $farmId = $this->plan->farmId;
        [$from, $to] = $this->plan->period();

        $farm = $mysql->selectOne(
            'SELECT f.id, f.name, f.region_primary AS region, ft.name AS farm_type, f.area_ha,
                    f.financial_year_end_day AS balance_day, f.financial_year_end_month AS balance_month
             FROM farms f
             LEFT JOIN farms_operation_types fo ON fo.farm_id = f.id AND fo._valid_to IS NULL
             LEFT JOIN farm_types ft ON ft.uuid = fo.farm_type_uuid
             WHERE f.id = ? AND f._valid_to IS NULL',
            [$farmId],
        );
        $facility = $mysql->selectOne('SELECT overdraft_limit, rate_pct FROM bank_facilities WHERE farm_id = ?', [$farmId]);

        $mobs = $mysql->select(
            'SELECT tr.id, tr.name, tr.enterprise, st.name AS stock_type, GROUP_CONCAT(DISTINCT sc.name ORDER BY sc.name) AS classes
             FROM trackers tr
             JOIN stock_types st ON st.uuid = tr.stock_type_uuid
             LEFT JOIN stock_transactions tx ON tx.tracker_id = tr.id AND tx._valid_to IS NULL
             LEFT JOIN stock_classes sc ON sc.uuid = tx.stock_class_uuid
             WHERE tr.farm_id = ? AND tr._valid_to IS NULL
             GROUP BY tr.id, tr.name, tr.enterprise, st.name
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
                'area_ha' => $farm->area_ha === null ? null : (float) $farm->area_ha,
                'enterprises' => array_map(
                    static fn (object $e): array => ['name' => (string) $e->enterprise, 'area_ha' => (float) $e->area_ha],
                    $mysql->select('SELECT enterprise, area_ha FROM farm_enterprises WHERE farm_id = ? ORDER BY area_ha DESC, enterprise', [$farmId]),
                ),
                'overdraft' => $facility === null ? null : ['limit' => (float) $facility->overdraft_limit, 'rate_pct' => (float) $facility->rate_pct],
            ],
            'plan' => [
                'name' => $this->plan->name,
                'season' => $this->plan->season,
                'season_from' => $from,
                'season_to' => $to,
                'plan_as_at' => $this->plan->planHorizon,
                'actuals_to' => $this->plan->actualsTo,
            ],
            'seasons' => ['first' => self::FIRST_SEASON, 'current' => $this->plan->season, 'last' => self::LAST_SEASON],
            'snapshots' => $this->snapshots(),
            'milk_supplies' => array_map(
                static fn (object $t): array => ['tracker_id' => (int) $t->id, 'name' => (string) $t->name, 'company' => (string) $t->company],
                $mysql->select('SELECT id, name, company FROM milk_trackers WHERE farm_id = ? AND _valid_to IS NULL ORDER BY id', [$farmId]),
            ),
            'livestock_mobs' => array_map(
                static fn (object $m): array => [
                    'tracker_id' => (int) $m->id,
                    'name' => (string) $m->name,
                    'enterprise' => $m->enterprise,
                    'stock_type' => (string) $m->stock_type,
                    'classes' => $m->classes === null ? [] : explode(',', (string) $m->classes),
                ],
                $mobs,
            ),
        ];
    }

    /**
     * A report in several versions side by side over a season or a run of
     * months: every row for each month, the year to date and the total, each
     * version's amount, and each later version compared from the first.
     *
     * @param non-empty-list<string> $versions the first is the one the others are compared from
     * @param ?int $season the season shown (by the year it ends); the plan's when neither a season nor months are given
     * @param ?string $fromMonth a run of months from this one (YYYY-MM) instead of a season
     * @param ?string $enterprise only this enterprise's lines (profit and loss only), or ReportLinesSqlBuilder::UNALLOCATED
     * @return array{window: array<string, mixed>, versions: list<array{key: string, label: string}>, rows: list<array<string, mixed>>}
     */
    public function report(
        ReportBasis $basis,
        array $versions,
        ?int $season = null,
        ?string $fromMonth = null,
        ?int $months = null,
        ?string $period = null,
        bool $categories = true,
        ?string $enterprise = null,
        bool $perHa = false,
    ): array {
        $enterprise = $this->enterprise($enterprise, $basis);
        $scope = $this->scope($versions, $season, $fromMonth, $months);
        $builder = new ReportComparisonSqlBuilder($this->plan->farmId, $basis, $scope['sides'], $period, $categories, $enterprise, $scope['total'], $perHa);
        $bindings = $scope['bindings'] + ($perHa ? [ReportComparisonSqlBuilder::AREA => (string) $this->area($enterprise)] : []);

        return [
            'window' => $scope['window'] + ['enterprise' => $enterprise, 'per_ha' => $perHa ? $this->area($enterprise) : null],
            'versions' => $this->describe($scope['sides']),
            'rows' => array_map(
                fn (array $r): array => $this->compared($r, $scope['sides']),
                DuckDbStatement::rows($this->db(), $builder->build(), $bindings),
            ),
        ];
    }

    /**
     * The months ahead in cash: month-end bank balance, net cash and
     * overdraft interest in each version, against the overdraft limit, with
     * when each first goes overdrawn and past the limit, and its low point.
     *
     * @param list<string> $compare versions beside current (or beside the scenario, with current first among them)
     * @param mixed $changes a proposed scenario's changes, or null
     * @return array<string, mixed>
     */
    public function cashOutlook(int $months = 12, array $compare = ['budget'], ?string $fromMonth = null, mixed $changes = null, ?int $scenarioId = null): array
    {
        $versions = $this->withScenario($compare, $changes, $scenarioId);
        $fromMonth ??= $this->firstForecastMonth();
        $scope = $this->scope($versions['names'], null, $fromMonth, $months, $versions['proposal']);
        $rows = DuckDbStatement::rows($this->db(), (new CashOutlookSqlBuilder($this->plan->farmId, $scope['sides']))->build(), $scope['bindings']);

        $keys = array_map(static fn (ReportSide $s): string => $s->key, $scope['sides']);
        $first = $rows[0] ?? [];
        $number = static fn (?string $v): ?float => $v === null ? null : round((float) $v, 2);

        return [
            'window' => $scope['window'],
            'versions' => $this->describe($scope['sides']),
            'overdraft' => ($first['overdraft_limit'] ?? null) === null ? null
                : ['limit' => (float) $first['overdraft_limit'], 'rate_pct' => (float) $first['rate_pct']],
            'summary' => array_combine($keys, array_map(static fn (string $k): array => [
                'lowest_balance' => $number($first["{$k}_lowest"] ?? null),
                'lowest_month' => $first["{$k}_lowest_month"] ?? null,
                'first_month_overdrawn' => $first["{$k}_first_overdrawn"] ?? null,
                'first_month_over_limit' => $first["{$k}_first_over_limit"] ?? null,
                'overdraft_interest' => $number($first["{$k}_overdraft_interest_total"] ?? null),
            ], $keys)),
            'months' => array_map(static fn (array $r): array => [
                'month' => $r['month'],
                'status' => $r['status'],
                'closing_balance' => array_combine($keys, array_map(static fn (string $k): ?float => $number($r["{$k}_closing"]), $keys)),
                'net_cash' => array_combine($keys, array_map(static fn (string $k): ?float => $number($r["{$k}_net_cash"]), $keys)),
                'overdraft_interest' => array_combine($keys, array_map(static fn (string $k): ?float => $number($r["{$k}_overdraft_interest"]), $keys)),
                'headroom' => array_combine($keys, array_map(static fn (string $k): ?float => $number($r["{$k}_headroom"]), $keys)),
            ], $rows),
        ];
    }

    /**
     * What the farm owns and owes, and its net worth: at the end of last
     * season, now and at the end of this season as things stand, and at the
     * season's end in each version compared, with how each change in net
     * worth reconciles to net profit and drawings.
     *
     * @param list<string> $compare versions beside current: budget, snapshot:N, scenario:N
     * @return array<string, mixed>
     */
    public function position(array $compare = ['budget'], mixed $changes = null, ?int $scenarioId = null): array
    {
        $versions = $this->withScenario($compare, $changes, $scenarioId);
        foreach ($versions['names'] as $name) {
            if (in_array(explode(':', $name)[0], ['actual', 'last_year', 'season'], true)) {
                throw new InvalidArgumentException("A position compares versions at dates; {$name} is a period, and last season's end is shown already.");
            }
        }
        [, $seasonTo] = $this->plan->period();
        [, $lastSeasonTo] = $this->plan->seasonPeriod($this->plan->season - 1);
        $now = date('Y-m-t', (int) strtotime($this->plan->actualsTo));

        $sides = [];
        $bindings = ['period_from' => self::LEDGER_OPENS, 'period_to' => $seasonTo, 'opening_before' => self::LEDGER_OPENS];
        $points = [];
        foreach ($versions['names'] as $name) {
            [$side, $params] = $this->side($name, $this->plan->season, false, $versions['proposal']);
            $sides[] = $side;
            $bindings += $params;
            $points[$side->key] = $side->key === 'current' ? [$lastSeasonTo, $now, $seasonTo] : [$now, $seasonTo];
        }

        $rows = array_map(
            static fn (array $r): array => self::typed($r, ['side', 'as_at', 'since']),
            DuckDbStatement::rows($this->db(), (new PositionSqlBuilder($this->plan->farmId, $sides, $points))->build(), $bindings),
        );
        $opened = array_filter($rows, static fn (array $r): bool => $r['land_buildings'] > 0) !== [];

        return [
            'versions' => $this->describe($sides),
            'opening_balances_recorded' => $opened,
            'positions' => $rows,
        ];
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
        [$from, $to] = $this->plan->period();
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
     * Plan status: the headline bottom lines, current against the budget,
     * and the signals ranked by attention, with the farm's own notes for
     * whoever judges them.
     *
     * @return array{headline: list<array<string, mixed>>, peer_group: array{farm_type: ?string, farms: int}, signals: list<array<string, mixed>>, notes: list<array{author: string, note: string, written: string}>}
     */
    public function status(int $limit = 8): array
    {
        [$from] = $this->plan->period();
        $peers = $this->peers();

        $headline = DuckDbStatement::rows(
            $this->db(),
            (new PlanHeadlineSqlBuilder($this->plan->farmId, [PlanComparisonSqlBuilder::current(), PlanComparisonSqlBuilder::budget('plan')]))->build(),
            $this->plan->seasonBindings(),
        );
        $signals = DuckDbStatement::rows(
            $this->db(),
            (new PlanSignalsSqlBuilder($this->plan->farmId, $peers['farm_ids'], $limit))->build(),
            [
                'period_from' => date('Y-m-d', (int) strtotime($from.' -3 years')),
                PlanSignalsSqlBuilder::SEASON_FROM => $from,
                'opening_before' => $from,
            ] + array_intersect_key($this->plan->seasonBindings(), array_flip([
                PlanComparisonSqlBuilder::PLAN_HORIZON, PlanComparisonSqlBuilder::CURRENT_HORIZON, PlanComparisonSqlBuilder::BUDGET_KNOWN_AT, 'period_to',
            ])),
        );

        return [
            'headline' => array_map(static function (array $r): array {
                $r = self::typed($r, ['measure', 'period', 'status', 'vs_plan_effect', 'current_month', 'plan_month']);

                return [
                    'measure' => $r['measure'], 'period' => $r['period'], 'status' => $r['status'],
                    'plan' => $r['plan'], 'current' => $r['current'], 'variance' => $r['vs_plan'],
                    'variance_pct' => $r['vs_plan_pct'], 'effect' => $r['vs_plan_effect'],
                    'plan_month' => $r['plan_month'], 'current_month' => $r['current_month'],
                ];
            }, $headline),
            'peer_group' => ['farm_type' => $peers['farm_type'], 'farms' => count($peers['farm_ids']) - 1],
            'signals' => array_map(static fn (array $r): array => self::typed($r, ['level', 'section', 'row', 'effect']), $signals),
            'notes' => $this->notes(),
        ];
    }

    /**
     * What a scenario does, worked out by Figured's own reports: the bottom
     * lines and every changed row on scenario, current and budget, the
     * month-end bank balance through the season, and what each change books.
     * Nothing is saved.
     *
     * @param mixed $changes as ScenarioChanges takes them, or null with a saved scenario's id
     * @return array<string, mixed>
     */
    public function modelScenario(mixed $changes = null, ?int $scenarioId = null): array
    {
        $versions = $this->withScenario(['budget'], $changes, $scenarioId);
        if (count($versions['names']) < 3) {
            throw new InvalidArgumentException('Give the changes, or a saved scenario\'s id.');
        }
        $scope = $this->scope($versions['names'], null, null, null, $versions['proposal']);
        $sides = $scope['sides'];
        $bindings = $scope['bindings'];
        $text = ['measure', 'period', 'status', 'scenario_month', 'current_month', 'budget_month'];

        $headline = DuckDbStatement::rows($this->db(), (new PlanHeadlineSqlBuilder($this->plan->farmId, $sides))->build(), $bindings);
        $changed = [];
        $bank = [];
        foreach ([ReportBasis::Accrual, ReportBasis::Cash] as $basis) {
            $period = $basis === ReportBasis::Accrual ? 'Season' : null;
            $sql = (new ReportComparisonSqlBuilder($this->plan->farmId, $basis, $sides, $period))->build();
            foreach (DuckDbStatement::rows($this->db(), $sql, $bindings) as $row) {
                $shaped = ['report' => $basis === ReportBasis::Cash ? 'cash_flow' : 'profit_and_loss'] + $this->compared($row, $sides);
                if ($row['period_type'] === 'total' && (float) $row['vs_current'] != 0) {
                    $changed[] = $shaped;
                }
                if ($basis === ReportBasis::Cash && $row['kind'] === 'balance' && $row['field'] === 'closing' && $row['period_type'] === 'month') {
                    $bank[] = ['month' => $row['period'], 'status' => $shaped['status']] + $shaped['values'] + ['change' => (float) $row['vs_current']];
                }
            }
        }

        $proposal = $versions['proposal'];
        $booked = DuckDbStatement::rows(
            $this->db(),
            ReportLinesSqlBuilder::statement($this->proposalLines($proposal['parameter'])->editCtes(ReportLinesSqlBuilder::periodWindow()), ForecastEditsSqlBuilder::workingsSelect()),
            $this->proposalBindings($proposal),
        );

        return [
            'versions' => $this->describe($sides),
            'headline' => array_map(fn (array $r): array => $this->comparedHeadline(self::typed($r, $text), $sides), $headline),
            'changed_rows' => $changed,
            'bank_by_month' => $bank,
            'booked' => array_map(static fn (array $r): array => self::typed($r, ['kind', 'category', 'first_month', 'last_month']), $booked),
        ];
    }

    /**
     * Saves a scenario for the farmer or advisor to come back to, compare or adopt.
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
     * @return list<array{scenario_id: int, name: string, status: string, saved: string, adopted: ?string, withdrawn: ?string, changes: list<array<string, mixed>>}>
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
                'withdrawn' => $s->withdrawn_at,
                'changes' => json_decode((string) $s->changes, true, 512, JSON_THROW_ON_ERROR),
            ],
            InsightsDuckDb::mysql()->select(
                'SELECT id, name, status, created_at, adopted_at, withdrawn_at, changes FROM plan_scenarios WHERE farm_id = ? ORDER BY id',
                [$this->plan->farmId],
            ),
        );
    }

    /**
     * Writes a saved scenario into the farm's forecast: its journal lines
     * join the forecast every current-side report reads, each marked with
     * the change and event it books; the budget stays as agreed; and a plan
     * note records that the change was chosen.
     *
     * @return array{scenario_id: int, name: string, lines_written: int, note: string}
     */
    public function adoptScenario(int $scenarioId, string $author = 'farmer'): array
    {
        $mysql = InsightsDuckDb::mysql();
        $saved = $this->savedScenario($scenarioId);
        if ($saved->status !== 'draft') {
            throw new InvalidArgumentException("Scenario {$scenarioId} is already {$saved->status}.");
        }
        $scenario = $this->scenarioChanges(json_decode((string) $saved->changes, true, 512, JSON_THROW_ON_ERROR));
        $proposal = ['parameter' => 'scenario', 'changes' => $scenario];

        $lines = DuckDbStatement::rows(
            $this->db(),
            ReportLinesSqlBuilder::statement(
                $this->proposalLines('scenario')->editCtes(ReportLinesSqlBuilder::periodWindow()),
                "SELECT change_no, kind, enterprise, basis, date, account_id, net_amount, tax_amount FROM forecast_edits WHERE source = 'proposed'",
            ),
            $this->proposalBindings($proposal),
        );
        $note = sprintf('Adopted the "%s" scenario into the forecast: %s.', $saved->name, $scenario->describe());

        $mysql->transaction(function () use ($mysql, $lines, $scenarioId, $note, $author): void {
            foreach (array_chunk($lines, 500) as $chunk) {
                $mysql->table('forecast_edit_lines')->insert(array_map(fn (array $l): array => [
                    'scenario_id' => $scenarioId,
                    'farm_id' => $this->plan->farmId,
                    'change_no' => (int) $l['change_no'],
                    'kind' => $l['kind'],
                    'enterprise' => $l['enterprise'],
                    'basis' => $l['basis'],
                    'date' => $l['date'],
                    'account_id' => $l['account_id'],
                    'net_amount' => (int) $l['net_amount'],
                    'tax_amount' => (int) $l['tax_amount'],
                ], $chunk));
            }
            $mysql->update("UPDATE plan_scenarios SET status = 'adopted', adopted_at = NOW(6) WHERE id = ?", [$scenarioId]);
            $this->addNote($author, $note);
        });

        return ['scenario_id' => $scenarioId, 'name' => (string) $saved->name, 'lines_written' => count($lines), 'note' => $note];
    }

    /**
     * Takes an adopted scenario back out of the forecast from now on. Its
     * lines stay, so a snapshot taken while it was adopted still shows it;
     * the forecast as it stands no longer reads them.
     *
     * @return array{scenario_id: int, name: string, note: string}
     */
    public function withdrawScenario(int $scenarioId, string $author = 'farmer'): array
    {
        $mysql = InsightsDuckDb::mysql();
        $saved = $this->savedScenario($scenarioId);
        if ($saved->status !== 'adopted') {
            throw new InvalidArgumentException("Scenario {$scenarioId} is {$saved->status}; only an adopted one can be withdrawn.");
        }
        $note = sprintf('Withdrew the "%s" scenario from the forecast.', $saved->name);

        $mysql->transaction(function () use ($mysql, $scenarioId, $note, $author): void {
            $mysql->update("UPDATE plan_scenarios SET status = 'withdrawn', withdrawn_at = NOW(6) WHERE id = ?", [$scenarioId]);
            $this->addNote($author, $note);
        });

        return ['scenario_id' => $scenarioId, 'name' => (string) $saved->name, 'note' => $note];
    }

    /**
     * Saves the forecast as it stands now, actuals to date, under a name, to
     * compare against later as `snapshot:N`.
     *
     * @return array{snapshot_id: int, name: string, actuals_to: string, taken_at: string}
     */
    public function saveSnapshot(string $name): array
    {
        $mysql = InsightsDuckDb::mysql();
        $mysql->insert(
            "INSERT INTO forecast_snapshots (farm_id, name, kind, actuals_to, taken_at) VALUES (?, ?, 'snapshot', ?, NOW(6))",
            [$this->plan->farmId, $name, $this->plan->actualsTo],
        );
        $id = (int) $mysql->getPdo()->lastInsertId();
        $takenAt = (string) $mysql->selectOne('SELECT taken_at FROM forecast_snapshots WHERE id = ?', [$id])->taken_at;

        return ['snapshot_id' => $id, 'name' => $name, 'actuals_to' => $this->plan->actualsTo, 'taken_at' => $takenAt];
    }

    /**
     * @return list<array{snapshot_id: int, version: string, name: string, kind: string, actuals_to: string, taken_at: string}>
     */
    public function snapshots(): array
    {
        return array_map(
            static fn (object $s): array => [
                'snapshot_id' => (int) $s->id,
                'version' => $s->kind === 'budget' ? 'budget' : "snapshot:{$s->id}",
                'name' => (string) $s->name,
                'kind' => (string) $s->kind,
                'actuals_to' => (string) $s->actuals_to,
                'taken_at' => (string) $s->taken_at,
            ],
            InsightsDuckDb::mysql()->select(
                'SELECT id, name, kind, actuals_to, taken_at FROM forecast_snapshots WHERE farm_id = ? ORDER BY taken_at, id',
                [$this->plan->farmId],
            ),
        );
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
     * The sides, window and bindings for a set of versions over a season or
     * a run of months. The reports start early enough for the side looking
     * furthest back, and early enough for the earliest side's horizon
     * (ActivePlan::reportStart): what comes after a horizon is partly worked
     * out rather than stored, and needs the months before it.
     *
     * @param non-empty-list<string> $versions
     * @param ?array{parameter: string, changes: ScenarioChanges} $proposal
     * @return array{sides: non-empty-list<ReportSide>, bindings: array<string, string>, window: array<string, mixed>, total: string}
     */
    private function scope(array $versions, ?int $season, ?string $fromMonth, ?int $months, ?array $proposal = null): array
    {
        if ($versions === []) {
            throw new InvalidArgumentException('Name at least one version, e.g. current.');
        }
        $rolling = $fromMonth !== null || ($months !== null && $season === null);
        if ($rolling) {
            $fromMonth ??= $this->firstForecastMonth();
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $fromMonth) !== 1) {
                throw new InvalidArgumentException('from_month is a month as YYYY-MM.');
            }
            $months ??= 12;
            if ($months < 1 || $months > self::MAX_MONTHS) {
                throw new InvalidArgumentException('months is 1 to '.self::MAX_MONTHS.'.');
            }
            $mainFrom = "{$fromMonth}-01";
            $to = date('Y-m-t', (int) strtotime("{$mainFrom} +".($months - 1).' months'));
            $mainSeason = $this->plan->season;
            $window = ['from' => $mainFrom, 'to' => $to, 'months' => $months, 'total' => 'Total'];
        } else {
            $first = explode(':', $versions[0]);
            $mainSeason = $season ?? ($first[0] === 'season' ? (int) ($first[1] ?? 0) : $this->plan->season);
            if ($mainSeason < self::FIRST_SEASON || $mainSeason > self::LAST_SEASON) {
                throw new InvalidArgumentException(sprintf('Seasons run from %d to %d (by the year they end).', self::FIRST_SEASON, self::LAST_SEASON));
            }
            [$mainFrom, $to] = $this->plan->seasonPeriod($mainSeason);
            $window = ['season' => $mainSeason, 'from' => $mainFrom, 'to' => $to, 'total' => 'Season'];
        }
        if ($to > self::LEDGER_ENDS) {
            throw new InvalidArgumentException('The forecast runs to '.self::LEDGER_ENDS.'.');
        }

        $sides = [];
        $bindings = [];
        foreach ($versions as $name) {
            [$side, $params] = $this->side($name, $mainSeason, $rolling, $proposal);
            $sides[] = $side;
            $bindings += $params;
        }
        $keys = array_map(static fn (ReportSide $s): string => $s->key, $sides);
        if (count(array_unique($keys)) !== count($keys)) {
            throw new InvalidArgumentException('Each version can be named once.');
        }
        if ($sides[0]->monthOffset !== 0) {
            throw new InvalidArgumentException("The first version is the one the others are compared from, so it must be over the window itself, not {$versions[0]}.");
        }

        $back = -min(array_map(static fn (ReportSide $s): int => $s->monthOffset, $sides));
        $horizons = array_map(static fn (ReportSide $s): string => $bindings[$s->horizonParameter], $sides);
        $reportFrom = min(date('Y-m-d', (int) strtotime("{$mainFrom} -{$back} months")), ActivePlan::reportStart(min($horizons)));
        if ($reportFrom < date('Y-m-01', (int) strtotime(self::LEDGER_OPENS.' +1 day'))) {
            throw new InvalidArgumentException('The ledger starts in '.substr(InsightsDuckDbPracticeSeeder::FIRST_MONTH, 0, 7).'; that version would need months before it.');
        }

        return [
            'sides' => $sides,
            'bindings' => $bindings + [
                'period_from' => $reportFrom,
                'period_to' => $to,
                'opening_before' => $reportFrom,
                ReportComparisonSqlBuilder::MAIN_FROM => $mainFrom,
            ],
            'window' => $window,
            'total' => $window['total'],
        ];
    }

    /**
     * One named version as a report side, with the parameters it binds.
     *
     * @param ?array{parameter: string, changes: ScenarioChanges} $proposal
     * @return array{0: ReportSide, 1: array<string, string>}
     */
    private function side(string $name, int $mainSeason, bool $rolling, ?array $proposal): array
    {
        $current = [PlanComparisonSqlBuilder::CURRENT_HORIZON => $this->plan->actualsTo];
        [$kind, $arg] = array_pad(explode(':', $name, 2), 2, null);

        return match ($kind) {
            'current' => [PlanComparisonSqlBuilder::current(), $current],
            'budget', 'plan' => [PlanComparisonSqlBuilder::budget(), [
                PlanComparisonSqlBuilder::PLAN_HORIZON => $this->plan->planHorizon,
                PlanComparisonSqlBuilder::BUDGET_KNOWN_AT => $this->plan->budgetKnownAt,
            ]],
            'actual' => [new ReportSide('actual', 'Actual', PlanComparisonSqlBuilder::CURRENT_HORIZON, ForecastEdits::adopted(), actualsOnly: true), $current],
            'last_year' => [new ReportSide('last_year', 'Last year', PlanComparisonSqlBuilder::CURRENT_HORIZON, ForecastEdits::adopted(), -12), $current],
            'season' => $this->seasonSide((int) $arg, $mainSeason, $rolling, $current),
            'snapshot' => $this->snapshotSide((int) $arg),
            'scenario' => $this->scenarioSide($arg, $proposal, $current),
            default => throw new InvalidArgumentException("Unknown version {$name}: use current, budget, actual, last_year, season:YYYY, snapshot:N or scenario:N."),
        };
    }

    /**
     * @param array<string, string> $current
     * @return array{0: ReportSide, 1: array<string, string>}
     */
    private function seasonSide(int $season, int $mainSeason, bool $rolling, array $current): array
    {
        if ($rolling) {
            throw new InvalidArgumentException('Compare a run of months with last_year; seasons compare with seasons.');
        }
        if ($season < self::FIRST_SEASON || $season > $mainSeason) {
            throw new InvalidArgumentException(sprintf('A season compared is %d to %d, no later than the one shown.', self::FIRST_SEASON, $mainSeason));
        }

        return [new ReportSide("season_{$season}", sprintf('FY%02d', $season % 100), PlanComparisonSqlBuilder::CURRENT_HORIZON, ForecastEdits::adopted(), ($season - $mainSeason) * 12), $current];
    }

    /**
     * @return array{0: ReportSide, 1: array<string, string>}
     */
    private function snapshotSide(int $id): array
    {
        $snapshot = InsightsDuckDb::mysql()->selectOne(
            'SELECT id, name, kind, actuals_to, taken_at FROM forecast_snapshots WHERE id = ? AND farm_id = ?',
            [$id, $this->plan->farmId],
        ) ?? throw new InvalidArgumentException("This farm has no snapshot {$id}; get-farm-profile lists them.");

        return [
            new ReportSide("snapshot_{$id}", (string) $snapshot->name, "snapshot_{$id}_horizon", ForecastEdits::adopted("snapshot_{$id}_known_at")),
            ["snapshot_{$id}_horizon" => (string) $snapshot->actuals_to, "snapshot_{$id}_known_at" => (string) $snapshot->taken_at],
        ];
    }

    /**
     * A saved scenario by id, or the proposal being modelled (no id).
     *
     * @param ?array{parameter: string, changes: ScenarioChanges} $proposal
     * @param array<string, string> $current
     * @return array{0: ReportSide, 1: array<string, string>}
     */
    private function scenarioSide(?string $id, ?array $proposal, array $current): array
    {
        if ($id === null || $id === '') {
            if ($proposal === null) {
                throw new InvalidArgumentException('Name a saved scenario as scenario:N.');
            }

            return [
                new ReportSide('scenario', 'Scenario', PlanComparisonSqlBuilder::CURRENT_HORIZON, ForecastEdits::proposed($proposal['parameter'])),
                $current + [$proposal['parameter'] => $proposal['changes']->json()],
            ];
        }

        $saved = $this->savedScenario((int) $id);
        if ($saved->status !== 'draft') {
            throw new InvalidArgumentException("Scenario {$id} is {$saved->status}: an adopted scenario is already in current.");
        }
        $changes = $this->scenarioChanges(json_decode((string) $saved->changes, true, 512, JSON_THROW_ON_ERROR));

        return [
            new ReportSide("scenario_{$id}", (string) $saved->name, PlanComparisonSqlBuilder::CURRENT_HORIZON, ForecastEdits::proposed("scenario_{$id}")),
            $current + ["scenario_{$id}" => $changes->json()],
        ];
    }

    /**
     * The versions for a what-if: the scenario first, then current and the
     * others asked for; or, with no scenario, current and the others.
     *
     * @param list<string> $compare
     * @return array{names: non-empty-list<string>, proposal: ?array{parameter: string, changes: ScenarioChanges}}
     */
    private function withScenario(array $compare, mixed $changes, ?int $scenarioId): array
    {
        $others = array_values(array_filter($compare, static fn (string $v): bool => $v !== 'current'));
        if ($changes !== null) {
            return ['names' => ['scenario', 'current', ...$others], 'proposal' => ['parameter' => 'scenario', 'changes' => $this->scenarioChanges($changes)]];
        }
        if ($scenarioId !== null) {
            $saved = $this->savedScenario($scenarioId);
            if ($saved->status !== 'draft') {
                throw new InvalidArgumentException("Scenario {$scenarioId} is {$saved->status}: an adopted scenario is already in current.");
            }

            return [
                'names' => ["scenario:{$scenarioId}", 'current', ...$others],
                'proposal' => [
                    'parameter' => "scenario_{$scenarioId}",
                    'changes' => $this->scenarioChanges(json_decode((string) $saved->changes, true, 512, JSON_THROW_ON_ERROR)),
                ],
            ];
        }

        return ['names' => ['current', ...$others], 'proposal' => null];
    }

    /**
     * @param list<ReportSide> $sides
     * @return list<array{key: string, label: string}>
     */
    private function describe(array $sides): array
    {
        return array_map(static fn (ReportSide $s): array => ['key' => $s->key, 'label' => $s->label], $sides);
    }

    /**
     * A comparison row: its amount in each version, and each later version
     * compared from the first.
     *
     * @param array<string, ?string> $row
     * @param list<ReportSide> $sides
     * @return array<string, mixed>
     */
    private function compared(array $row, array $sides): array
    {
        $number = static fn (?string $v): ?float => $v === null ? null : (float) $v;
        $shaped = [
            'row' => $row['kind'] === 'category' ? $row['category'] : $row['label'],
            'kind' => $row['kind'],
            'section' => $row['field'],
            'period' => $row['period'],
            'status' => $row['status'],
            'values' => [],
            'vs' => [],
        ];
        foreach ($sides as $i => $side) {
            $shaped['values'][$side->key] = $number($row[$side->key]);
            if ($i > 0) {
                $shaped['vs'][$side->key] = [
                    'change' => $number($row["vs_{$side->key}"]),
                    'pct' => $number($row["vs_{$side->key}_pct"]),
                    'effect' => $row["vs_{$side->key}_effect"],
                ];
            }
        }

        return $shaped;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<ReportSide> $sides
     * @return array<string, mixed>
     */
    private function comparedHeadline(array $row, array $sides): array
    {
        $shaped = ['measure' => $row['measure'], 'period' => $row['period'], 'status' => $row['status'], 'values' => [], 'vs' => []];
        foreach ($sides as $i => $side) {
            $shaped['values'][$side->key] = $row[$side->key];
            if ($i > 0) {
                $shaped['vs'][$side->key] = ['change' => $row["vs_{$side->key}"], 'pct' => $row["vs_{$side->key}_pct"], 'effect' => $row["vs_{$side->key}_effect"]];
            }
        }
        if ($row['measure'] === 'Lowest month-end bank balance') {
            foreach ($sides as $side) {
                $shaped['month'][$side->key] = $row["{$side->key}_month"];
            }
        }

        return $shaped;
    }

    /** The enterprise to report, checked against the farm's own (it is written into the statement). */
    private function enterprise(?string $enterprise, ReportBasis $basis): ?string
    {
        if ($enterprise === null || $enterprise === '') {
            return null;
        }
        if ($basis !== ReportBasis::Accrual) {
            throw new InvalidArgumentException('Enterprises are reported on the profit and loss.');
        }
        $names = array_map(
            static fn (object $e): string => (string) $e->enterprise,
            InsightsDuckDb::mysql()->select('SELECT enterprise FROM farm_enterprises WHERE farm_id = ?', [$this->plan->farmId]),
        );
        foreach ([...$names, ReportLinesSqlBuilder::UNALLOCATED] as $name) {
            if (strcasecmp($name, $enterprise) === 0) {
                return $name;
            }
        }

        throw new InvalidArgumentException(sprintf('This farm\'s enterprises are %s; whole-farm costs are %s.', $names === [] ? 'not recorded' : implode(', ', $names), ReportLinesSqlBuilder::UNALLOCATED));
    }

    /** The hectares a per-hectare figure divides by: the enterprise's, or the farm's. */
    private function area(?string $enterprise): float
    {
        $mysql = InsightsDuckDb::mysql();
        $area = $enterprise === null || $enterprise === ReportLinesSqlBuilder::UNALLOCATED
            ? $mysql->selectOne('SELECT area_ha AS a FROM farms WHERE id = ? AND _valid_to IS NULL', [$this->plan->farmId])?->a
            : $mysql->selectOne('SELECT area_ha AS a FROM farm_enterprises WHERE farm_id = ? AND enterprise = ?', [$this->plan->farmId, $enterprise])?->a;
        if ($area === null || (float) $area <= 0) {
            throw new InvalidArgumentException('This farm has no area recorded, so nothing can be per hectare.');
        }

        return (float) $area;
    }

    private function firstForecastMonth(): string
    {
        return date('Y-m', (int) strtotime(substr($this->plan->actualsTo, 0, 7).'-01 +1 month'));
    }

    private function savedScenario(int $id): object
    {
        return InsightsDuckDb::mysql()->selectOne(
            'SELECT id, name, status, changes FROM plan_scenarios WHERE id = ? AND farm_id = ?',
            [$id, $this->plan->farmId],
        ) ?? throw new InvalidArgumentException("This farm has no scenario {$id}; list-scenarios lists them.");
    }

    /** Changes are to the forecast: from the month after actuals_to to the season's last. */
    private function scenarioChanges(mixed $changes): ScenarioChanges
    {
        [, $to] = $this->plan->period();

        return ScenarioChanges::fromInput($changes, $this->firstForecastMonth(), substr($to, 0, 7), $this->adjustableCategories(), $this->enterpriseNames());
    }

    /**
     * The categories a scenario can change: the forecast's ledger
     * categories. Milk income and the livestock valuation come from
     * production and the herd, overdraft interest from the bank balance, and
     * the opening balance is not a forecast.
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
                       AND system_category_name NOT IN ('Livestock Valuation Change', 'Opening Balances', '%s')
                     ORDER BY system_category_name",
                    implode(', ', array_fill(0, count($groups), '?')),
                    ReportLinesSqlBuilder::OVERDRAFT_INTEREST,
                ),
                [$this->plan->farmId, ...$groups],
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function enterpriseNames(): array
    {
        return array_map(
            static fn (object $e): string => (string) $e->enterprise,
            InsightsDuckDb::mysql()->select('SELECT enterprise FROM farm_enterprises WHERE farm_id = ? ORDER BY enterprise', [$this->plan->farmId]),
        );
    }

    private function proposalLines(string $parameter): ReportLinesSqlBuilder
    {
        return new ReportLinesSqlBuilder([$this->plan->farmId], ReportBasis::Accrual, PlanComparisonSqlBuilder::CURRENT_HORIZON, ForecastEdits::proposed($parameter));
    }

    /**
     * @param ?array{parameter: string, changes: ScenarioChanges} $proposal
     * @return array<string, string>
     */
    private function proposalBindings(?array $proposal): array
    {
        if ($proposal === null) {
            throw new InvalidArgumentException('Nothing proposed to book.');
        }
        [$from, $to] = $this->plan->period();

        return [
            PlanComparisonSqlBuilder::CURRENT_HORIZON => $this->plan->actualsTo,
            'period_from' => $from,
            'period_to' => $to,
            $proposal['parameter'] => $proposal['changes']->json(),
        ];
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
        [$from, $to] = $this->plan->period();
        if ($period < substr($from, 0, 7) || $period > substr($to, 0, 7)) {
            throw new InvalidArgumentException(sprintf('The plan season runs from %s to %s; %s is outside it.', substr($from, 0, 7), substr($to, 0, 7), $period));
        }
    }

    /**
     * What milk and the herd bind: both horizons and the season.
     *
     * @return array<string, string>
     */
    private function bindings(): array
    {
        [$from, $to] = $this->plan->period();

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
                $value === null, in_array($column, $text, true), str_ends_with($column, '_effect'), !is_numeric($value) => $value,
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
