<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;
use InvalidArgumentException;

/**
 * The signals layer of plan status: for each profit and loss category and
 * section of one farm, its variance since the plan was agreed and the
 * evidence for judging it. No thresholds and no verdicts; deciding what
 * matters is left to whoever reads the signals.
 *
 * The evidence, all from the shared report SQL:
 *
 *   - unusual: the variance against the farm's own normal variation. Each
 *     line's monthly amounts over the three seasons before this one give its
 *     typical swing around its seasonal pattern; scaled to the months since
 *     the plan, that is the variance's normal spread, and z is how many
 *     spreads out it sits;
 *   - peers: the same line's variance on farms of the same type on the same
 *     plan dates, so a shared cause (weather, prices) shows as a shared move;
 *   - materiality: the variance as a share of the season's planned income;
 *   - timing: how much of the variance landed in the latest month, and how
 *     it compares with what the plan still has coming in the next month and
 *     the next two;
 *   - outlook: the season if the line returns to plan, and if its trend
 *     since the plan continues.
 *
 * Signals are ranked by attention: the variance in dollars, weighted by how
 * far outside normal variation it is (1 - e^(-z²/2): a third at one spread,
 * nearly all at three). It is an ordering, not a cut-off.
 *
 * Current includes every peer's adopted scenarios, as the comparison's does.
 *
 * Binds the plan and current horizons, `$season_from`, `$period_to` (the
 * season's end) and `$period_from`, the start of the history: the farm's
 * window reaches back that far on the plan side, its peers' do not.
 */
final class PlanSignalsSqlBuilder
{
    public const string SEASON_FROM = 'season_from';

    private const int FIXED_POINT = 10000;

    /**
     * @param list<int> $peerFarmIds farms of the same type on the same plan dates, this farm among them
     */
    public function __construct(
        private readonly int $farmId,
        private readonly array $peerFarmIds,
        private readonly ?int $limit = null,
    ) {
        if (!in_array($farmId, $peerFarmIds, true)) {
            throw new InvalidArgumentException('The peer group must include the farm itself.');
        }
    }

    public function build(): string
    {
        $profitAndLoss = new InsightsProfitLossSqlBuilder($this->farmId);
        $plan = $this->side(PlanComparisonSqlBuilder::PLAN_HORIZON, ForecastEdits::None, $profitAndLoss, withHistory: true);
        $current = $this->side(PlanComparisonSqlBuilder::CURRENT_HORIZON, ForecastEdits::Adopted, $profitAndLoss, withHistory: false);
        $planHorizon = 'CAST($'.PlanComparisonSqlBuilder::PLAN_HORIZON.' AS DATE)';
        $currentHorizon = 'CAST($'.PlanComparisonSqlBuilder::CURRENT_HORIZON.' AS DATE)';
        $seasonFrom = 'CAST($'.self::SEASON_FROM.' AS DATE)';
        $farm = $this->farmId;
        $costs = implode(', ', array_map(static fn (string $g): string => "'{$g}'", PlanComparisonSqlBuilder::COST_GROUPS));
        $labels = implode(', ', array_map(static fn (array $s): string => "('{$s[0]}', '{$s[1]}')", $profitAndLoss->sections()));
        $limit = $this->limit === null ? '' : 'LIMIT '.max(1, $this->limit);

        return <<<SQL
            WITH
            plan_side AS (
            {$plan}
            ),
            current_side AS (
            {$current}
            ),
            months AS (
                SELECT CAST(g.m AS DATE) AS month,
                       CAST(g.m + INTERVAL '1 month' - INTERVAL '1 day' AS DATE) <= {$planHorizon} AS actual_at_plan,
                       CAST(g.m + INTERVAL '1 month' - INTERVAL '1 day' AS DATE) <= {$currentHorizon} AS actual_now,
                       CAST(g.m AS DATE) > {$currentHorizon} AND CAST(g.m AS DATE) <= {$currentHorizon} + INTERVAL '1 month' AS next_one,
                       CAST(g.m AS DATE) > {$currentHorizon} AND CAST(g.m AS DATE) <= {$currentHorizon} + INTERVAL '2 months' AS next_two
                FROM generate_series(CAST({$seasonFrom} AS TIMESTAMP), CAST(CAST(\$period_to AS DATE) AS TIMESTAMP), INTERVAL '1 month') AS g(m)
            ),
            season AS (
                SELECT COALESCE(c.farm_id, p.farm_id) AS farm_id, COALESCE(c.grp, p.grp) AS grp,
                       COALESCE(c.category, p.category) AS category, COALESCE(c.month, p.month) AS month,
                       COALESCE(p.amount, 0) AS plan, COALESCE(c.amount, 0) AS current
                FROM (SELECT * FROM plan_side WHERE month >= {$seasonFrom}) p
                FULL JOIN current_side c
                  ON c.farm_id = p.farm_id AND c.grp = p.grp AND c.category = p.category AND c.month = p.month
            ),
            lines AS (
                SELECT farm_id, 'category' AS level, grp, category, month, plan, current FROM season
                UNION ALL
                SELECT farm_id, 'section', grp, NULL, month, SUM(plan), SUM(current) FROM season GROUP BY farm_id, grp, month
            ),
            by_line AS (
                SELECT l.farm_id, l.level, l.grp, l.category,
                       SUM(l.plan) FILTER (WHERE m.actual_now) AS plan_ytd,
                       SUM(l.current) FILTER (WHERE m.actual_now) AS current_ytd,
                       COALESCE(SUM(l.plan) FILTER (WHERE m.actual_now AND NOT m.actual_at_plan), 0) AS plan_since,
                       COALESCE(SUM(l.current) FILTER (WHERE m.actual_now AND NOT m.actual_at_plan), 0) AS current_since,
                       sqrt(SUM(l.plan * l.plan) FILTER (WHERE m.actual_now AND NOT m.actual_at_plan)) AS plan_since_rms,
                       arg_max(l.current - l.plan, l.month) FILTER (WHERE m.actual_now AND NOT m.actual_at_plan) AS latest_month_variance,
                       COALESCE(SUM(l.plan) FILTER (WHERE NOT m.actual_now), 0) AS plan_remaining,
                       SUM(l.plan) FILTER (WHERE m.next_one) AS plan_next_one,
                       SUM(l.plan) FILTER (WHERE m.next_two) AS plan_next_two,
                       SUM(l.plan) AS plan_season, SUM(l.current) AS current_season
                FROM lines l
                JOIN months m ON m.month = l.month
                GROUP BY l.farm_id, l.level, l.grp, l.category
            ),
            history AS (
                SELECT 'category' AS level, grp, category, month, amount
                FROM plan_side WHERE farm_id = {$farm} AND month < {$seasonFrom}
                UNION ALL
                SELECT 'section', grp, NULL, month, SUM(amount)
                FROM plan_side WHERE farm_id = {$farm} AND month < {$seasonFrom}
                GROUP BY grp, month
            ),
            noise AS (
                SELECT level, grp, category, count(*) AS history_months,
                       sqrt(SUM(deviation * deviation * seasons / (seasons - 1)) / NULLIF(SUM(typical * typical), 0)) AS relative_noise
                FROM (
                    SELECT level, grp, category,
                           amount - avg(amount) OVER same_month AS deviation,
                           avg(amount) OVER same_month AS typical,
                           count(*) OVER same_month AS seasons
                    FROM history
                    WINDOW same_month AS (PARTITION BY level, grp, category, month(month))
                )
                WHERE seasons > 1
                GROUP BY level, grp, category
            ),
            since_pct AS (
                SELECT farm_id, level, grp, category,
                       CASE WHEN plan_since <> 0 THEN (current_since - plan_since) / abs(plan_since) END AS pct
                FROM by_line
            ),
            peers AS (
                SELECT t.level, t.grp, t.category, count(p.pct) AS peer_farms, median(p.pct) AS peer_median_pct,
                       CASE WHEN t.pct IS NOT NULL
                            THEN avg(CASE WHEN p.pct < t.pct THEN 1.0 WHEN p.pct = t.pct THEN 0.5 ELSE 0.0 END) END AS percentile
                FROM since_pct t
                JOIN since_pct p
                  ON p.level = t.level AND p.grp = t.grp AND p.category IS NOT DISTINCT FROM t.category
                 AND p.farm_id <> t.farm_id AND p.pct IS NOT NULL
                WHERE t.farm_id = {$farm}
                GROUP BY t.level, t.grp, t.category, t.pct
            ),
            section_labels(grp, label) AS (
                VALUES {$labels}
            ),
            scored AS (
                SELECT b.*, n.history_months, sp.pct AS since_pct,
                       b.current_since - b.plan_since AS variance_since,
                       n.relative_noise * b.plan_since_rms AS normal_spread,
                       (b.current_since - b.plan_since) / NULLIF(n.relative_noise * b.plan_since_rms, 0) AS z,
                       (SELECT plan_season FROM by_line WHERE farm_id = {$farm} AND level = 'section' AND grp = 'income') AS planned_income
                FROM by_line b
                LEFT JOIN noise n ON n.level = b.level AND n.grp = b.grp AND n.category IS NOT DISTINCT FROM b.category
                JOIN since_pct sp ON sp.farm_id = b.farm_id AND sp.level = b.level AND sp.grp = b.grp
                                 AND sp.category IS NOT DISTINCT FROM b.category
                WHERE b.farm_id = {$farm}
            )
            SELECT s.level, s.grp AS section, COALESCE(s.category, sl.label) AS row,
                   round(s.plan_ytd, 2) AS plan_ytd, round(s.current_ytd, 2) AS current_ytd,
                   round(s.current_ytd - s.plan_ytd, 2) AS variance_ytd,
                   round(s.variance_since, 2) AS variance_since_plan,
                   round(100 * s.since_pct, 1) AS variance_since_plan_pct,
                   CASE WHEN round(s.variance_since, 2) = 0 THEN 'none'
                        WHEN (s.variance_since > 0) <> (s.grp IN ({$costs})) THEN 'favourable'
                        ELSE 'adverse'
                   END AS effect,
                   round(s.z, 1) AS unusual_z, round(s.normal_spread, 2) AS normal_spread, s.history_months,
                   p.peer_farms, round(100 * p.peer_median_pct, 1) AS peer_median_pct, round(100 * p.percentile) AS peer_percentile,
                   round(100 * s.variance_since / NULLIF(s.planned_income, 0), 2) AS share_of_season_income_pct,
                   round(s.latest_month_variance / NULLIF(s.variance_since, 0), 2) AS latest_month_share,
                   round(s.plan_next_one, 2) AS plan_next_month,
                   round(s.variance_since / NULLIF(s.plan_next_one, 0), 2) AS variance_vs_next_month,
                   round(s.plan_next_two, 2) AS plan_next_two_months,
                   round(s.variance_since / NULLIF(s.plan_next_two, 0), 2) AS variance_vs_next_two_months,
                   round(s.plan_season, 2) AS plan_season,
                   round(s.current_season, 2) AS season_if_back_on_plan,
                   round(s.current_ytd + s.plan_remaining * s.current_since / NULLIF(s.plan_since, 0), 2) AS season_if_trend_continues,
                   round(abs(s.variance_since) * (1 - exp(-s.z * s.z / 2)), 2) AS attention
            FROM scored s
            JOIN section_labels sl ON sl.grp = s.grp
            LEFT JOIN peers p ON p.level = s.level AND p.grp = s.grp AND p.category IS NOT DISTINCT FROM s.category
            ORDER BY attention DESC NULLS LAST, abs(s.variance_since) DESC, s.level, row
            {$limit}
            SQL;
    }

    /**
     * One horizon's profit and loss lines for every farm in the group, by
     * month and category, in display sign and dollars. The plan side also
     * carries this farm's history.
     */
    private function side(string $horizonParameter, ForecastEdits $edits, InsightsProfitLossSqlBuilder $profitAndLoss, bool $withHistory): string
    {
        $start = $withHistory
            ? "CASE WHEN farm_id = {$this->farmId} THEN CAST(\$period_from AS DATE) ELSE CAST(\$".self::SEASON_FROM.' AS DATE) END'
            : 'CAST($'.self::SEASON_FROM.' AS DATE)';
        $window = <<<SQL
            SELECT farm_id, {$start} AS window_start, CAST(\$period_to AS DATE) AS window_end
            FROM farm
            SQL;

        $signs = implode(' ', array_map(static fn (array $s): string => "WHEN '{$s[0]}' THEN {$s[2]}", $profitAndLoss->sections()));
        $fp = self::FIXED_POINT;

        return ReportLinesSqlBuilder::statement(
            (new ReportLinesSqlBuilder($this->peerFarmIds, ReportBasis::Accrual, $horizonParameter, $edits))->ctes($window),
            <<<SQL
                SELECT farm_id, CAST(date_trunc('month', date) AS DATE) AS month, grp, category,
                       CAST(SUM(amount) AS DOUBLE) / {$fp} * (CASE grp {$signs} END) AS amount
                FROM report_lines
                GROUP BY 1, 2, 3, 4
                SQL,
        );
    }
}
