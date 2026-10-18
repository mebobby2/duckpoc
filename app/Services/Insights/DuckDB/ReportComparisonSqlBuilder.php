<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;
use InvalidArgumentException;

/**
 * One farm's report in several versions side by side, in one statement:
 * the single-farm report (cash flow on cash basis, profit and loss on
 * accrual) built once per side from the shared report SQL, lined up month by
 * month over the window shown, with the year to date and the window's total.
 *
 * The window shown runs from `$main_from` to `$period_to`. The reports
 * themselves are built from `$period_from`, as far back as the side looking
 * furthest back needs (last year is the same report twelve months earlier),
 * so every side is the same statement over the same months and its running
 * bank balance carries through unbroken; each side then reads the months its
 * offset lines up with the window.
 *
 * The first side is the one the others are compared from: each other side
 * carries `vs_{key}` (first minus it, in display sign), its percentage of
 * that side, and its effect, adverse when costs rise or anything else falls.
 * With `$perHa`, amounts are per hectare of the bound `$area_ha`.
 *
 * The reports hand over the ledger's fixed point unrounded; every amount is
 * rounded to the cent once, at the end, so a total is the rounded sum and
 * not the sum of rounded months.
 */
final class ReportComparisonSqlBuilder
{
    public const string MAIN_FROM = 'main_from';
    public const string AREA = 'area_ha';

    private const int FIXED_POINT = 10000;

    /** Report groups where spending more is adverse; for everything else, less is. */
    public const array COST_GROUPS = ['operating_expenses', 'non_operating_expenses'];

    /**
     * @param non-empty-list<ReportSide> $sides
     * @param ?string $period only this period: `YTD`, the total (`Season` or `Total`, as `$totalLabel` names it) or `YYYY-MM`
     */
    public function __construct(
        private readonly int $farmId,
        private readonly ReportBasis $basis,
        private readonly array $sides,
        private readonly ?string $period = null,
        private readonly bool $withCategories = true,
        private readonly ?string $enterprise = null,
        private readonly string $totalLabel = 'Season',
        private readonly bool $perHa = false,
        private readonly bool $overdraftInterest = true,
    ) {
        if ($sides === [] || $sides[0]->monthOffset !== 0) {
            throw new InvalidArgumentException('A comparison starts from a side over the window shown.');
        }
        $keys = array_map(static fn (ReportSide $s): string => $s->key, $sides);
        if (count(array_unique($keys)) !== count($keys)) {
            throw new InvalidArgumentException('Each side of a comparison needs its own key.');
        }
        if ($enterprise !== null && $basis !== ReportBasis::Accrual) {
            throw new InvalidArgumentException('Enterprises are reported on the profit and loss.');
        }
        if (!in_array($totalLabel, ['Season', 'Total'], true)) {
            throw new InvalidArgumentException('The total is the Season or the Total.');
        }
        PlanPeriod::condition($period, 'p.period');
    }

    /** How many months before the window shown the reports start: the furthest a side looks back. */
    public function monthsBack(): int
    {
        return -min(array_map(static fn (ReportSide $s): int => $s->monthOffset, $this->sides));
    }

    public function build(): string
    {
        $base = $this->sides[0]->key;
        $back = "datediff('month', CAST(\$period_from AS DATE), CAST(\$".self::MAIN_FROM.' AS DATE))';

        $reports = [];
        $versions = [];
        $byMonth = [];
        $joins = [];
        $toDate = [];
        $whole = [];
        foreach ($this->sides as $side) {
            $k = $side->key;
            $reports[] = "{$k}_report AS (\n{$this->report($side)->build(fixedPoint: true)}\n)";
            $versions[] = "SELECT '{$k}' AS side, {$side->monthOffset} AS month_offset, * FROM {$k}_report WHERE column_type <> 'total'";
            $amount = "COALESCE(SUM(a.amount) FILTER (WHERE a.side = '{$k}'), 0)";
            if ($side->actualsOnly) {
                $joins[] = "LEFT JOIN side_months sm_{$k} ON sm_{$k}.side = '{$k}' AND sm_{$k}.i = a.i";
                $amount = "CASE WHEN any_value(sm_{$k}.column_type) = 'actuals' THEN {$amount} END";
            }
            $byMonth[] = "{$amount} AS {$k}";
            $toDate[] = $this->rollUp($k);
            $whole[] = $this->rollUp($k);
        }
        $reports = implode(",\n", $reports);
        $versions = implode("\n    UNION ALL\n    ", $versions);
        $byMonth = implode(",\n           ", $byMonth);
        $joins = implode("\n", $joins);
        $sides = implode(', ', array_map(static fn (ReportSide $s): string => "m.{$s->key}", $this->sides));
        $toDate = implode(",\n           ", $toDate);
        $whole = implode(",\n           ", $whole);

        return <<<SQL
            WITH
            {$reports},
            versions AS (
                {$versions}
            ),
            main AS (
                SELECT DISTINCT interval_index - {$back} AS i, month, column_type
                FROM {$base}_report
                WHERE column_type <> 'total' AND interval_index > {$back}
            ),
            aligned AS (
                SELECT side, kind, field, label, category, interval_index - {$back} - month_offset AS i, column_type, amount
                FROM versions
            ),
            side_months AS (
                SELECT DISTINCT side, i, column_type FROM aligned
            ),
            months AS (
                SELECT a.kind, a.field, any_value(a.label) AS label, a.category, a.i, m.month, m.column_type,
                       {$byMonth}
                FROM aligned a
                JOIN main m ON m.i = a.i
                {$joins}
                GROUP BY a.kind, a.field, a.category, a.i, m.month, m.column_type
            ),
            periods AS (
                SELECT m.kind, m.field, m.label, m.category, 'month' AS period_type, m.month AS period, m.column_type, {$sides}
                FROM months m
                UNION ALL
                SELECT m.kind, m.field, m.label, m.category, 'ytd', 'YTD', 'actuals',
                       {$toDate}
                FROM months m
                WHERE m.column_type = 'actuals'
                GROUP BY m.kind, m.field, m.label, m.category
                UNION ALL
                SELECT m.kind, m.field, m.label, m.category, 'total', '{$this->totalLabel}', 'total',
                       {$whole}
                FROM months m
                GROUP BY m.kind, m.field, m.label, m.category
            ),
            row_order(kind, field, row_order) AS (
                VALUES {$this->rowOrder()}
            )
            SELECT p.kind, p.field, p.label, p.category, p.period_type, p.period,
                   CASE p.column_type WHEN 'actuals' THEN 'actual' WHEN 'forecast' THEN 'forecast'
                                      WHEN 'actualsForecast' THEN 'part_actual' ELSE 'projected' END AS status,
                   o.row_order,
                   {$this->sideColumns()}
            FROM periods p
            JOIN row_order o ON o.kind = (CASE WHEN p.kind = 'category' THEN 'section' ELSE p.kind END) AND o.field = p.field
            WHERE {$this->periodCondition()}
            ORDER BY CASE p.period_type WHEN 'month' THEN 0 WHEN 'ytd' THEN 1 ELSE 2 END, p.period,
                     o.row_order, p.kind = 'section', p.category
            SQL;
    }

    /** A side's amount over several months: flows summed, the opening balance from the first, the closing from the last it has. */
    private function rollUp(string $key): string
    {
        return <<<SQL
            CASE WHEN m.kind = 'balance' AND m.field = 'opening' THEN arg_min(m.{$key}, CASE WHEN m.{$key} IS NOT NULL THEN m.i END)
                            WHEN m.kind = 'balance' AND m.field = 'closing' THEN arg_max(m.{$key}, CASE WHEN m.{$key} IS NOT NULL THEN m.i END)
                            ELSE SUM(m.{$key}) END
            SQL;
    }

    /**
     * Each side's amount rounded to the cent, and each comparison worked
     * from the rounded amounts, so a change is always the difference of
     * the two figures shown.
     */
    private function sideColumns(): string
    {
        $area = ' / '.self::FIXED_POINT.($this->perHa ? ' / CAST($'.self::AREA.' AS DOUBLE)' : '');
        $rounded = static fn (string $key): string => "round(p.{$key}{$area}, 2)";
        $base = $rounded($this->sides[0]->key);
        $columns = [];
        foreach ($this->sides as $i => $side) {
            $k = $side->key;
            $columns[] = "{$rounded($k)} AS {$k}";
            if ($i === 0) {
                continue;
            }
            $columns[] = "round({$base} - {$rounded($k)}, 2) AS vs_{$k}";
            $columns[] = "CASE WHEN {$rounded($k)} <> 0 THEN round(100 * ({$base} - {$rounded($k)}) / abs({$rounded($k)}), 1) END AS vs_{$k}_pct";
            $columns[] = "{$this->effect($rounded($k), $base)} AS vs_{$k}_effect";
        }

        return implode(",\n                   ", $columns);
    }

    /** Whether moving from one amount to another helps the farm: adverse when costs rise or anything else falls. */
    private function effect(string $from, string $to): string
    {
        $costs = implode(', ', array_map(static fn (string $g): string => "'{$g}'", self::COST_GROUPS));

        return <<<SQL
            CASE WHEN {$to} IS NULL OR {$from} IS NULL THEN NULL
                        WHEN round({$to} - {$from}, 2) = 0 THEN 'none'
                        WHEN ({$to} > {$from}) <> (p.kind IN ('category', 'section') AND p.field IN ({$costs})) THEN 'favourable'
                        ELSE 'adverse'
                   END
            SQL;
    }

    private function periodCondition(): string
    {
        $period = PlanPeriod::condition($this->period, 'p.period');

        return $this->withCategories ? $period : "{$period} AND p.kind <> 'category'";
    }

    private function report(ReportSide $side): InsightsMonthlyReportSqlBuilder
    {
        return match ($this->basis) {
            ReportBasis::Cash => new InsightsCashFlowSqlBuilder($this->farmId, $side->horizonParameter, $side->edits, $this->overdraftInterest),
            ReportBasis::Accrual => new InsightsProfitLossSqlBuilder($this->farmId, $side->horizonParameter, $side->edits, $this->overdraftInterest, $this->enterprise),
        };
    }

    /**
     * The report's own order: opening balance, each section (its categories,
     * then its total), the calculated rows, closing balance.
     */
    private function rowOrder(): string
    {
        $report = $this->report($this->sides[0]);
        $rows = ["('balance', 'opening', 0)"];
        foreach ($report->sections() as $i => [$group]) {
            $rows[] = sprintf("('section', '%s', %d)", $group, 10 + $i);
        }
        foreach ($report->calculations() as $i => [$field]) {
            $rows[] = sprintf("('calculation', '%s', %d)", $field, 100 + $i);
        }
        $rows[] = "('balance', 'closing', 1000)";

        return implode(', ', $rows);
    }
}
