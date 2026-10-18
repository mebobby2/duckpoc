<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;

/**
 * The bottom lines of a comparison, from the comparisons themselves:
 * operating surplus and net profit from the profit and loss, the bank
 * balance from the cash flow, year to date and for the whole window, and
 * the window's lowest month-end bank balance on each side, with the month it
 * falls in. Plan status compares current with the budget; a what-if, a
 * scenario with current and the budget.
 *
 * Each side's figures are in a column named by its key; each side after the
 * first carries `vs_{key}` (first minus it), its percentage and effect, as
 * ReportComparisonSqlBuilder does.
 */
final class PlanHeadlineSqlBuilder
{
    /**
     * @param non-empty-list<ReportSide> $sides
     */
    public function __construct(
        private readonly int $farmId,
        private readonly array $sides,
        private readonly string $totalLabel = 'Season',
    ) {
    }

    public function build(): string
    {
        $profit = (new ReportComparisonSqlBuilder($this->farmId, ReportBasis::Accrual, $this->sides, withCategories: false, totalLabel: $this->totalLabel))->build();
        $cash = (new ReportComparisonSqlBuilder($this->farmId, ReportBasis::Cash, $this->sides, withCategories: false, totalLabel: $this->totalLabel))->build();

        $base = $this->sides[0]->key;
        $low = [];
        $compared = [];
        $months = [];
        $lowCompared = [];
        $nullMonths = [];
        $lowMonths = [];
        foreach ($this->sides as $i => $side) {
            $k = $side->key;
            $low[] = "min({$k}) AS {$k}, arg_min(period, {$k}) AS {$k}_month";
            $months[] = "{$k}_month";
            $nullMonths[] = "CAST(NULL AS VARCHAR) AS {$k}_month";
            $lowMonths[] = "{$k}_month";
            $compared[] = $k;
            $lowCompared[] = $k;
            if ($i === 0) {
                continue;
            }
            array_push($compared, "vs_{$k}", "vs_{$k}_pct", "vs_{$k}_effect");
            array_push(
                $lowCompared,
                "round({$base} - {$k}, 2)",
                "CASE WHEN {$k} <> 0 THEN round(100 * ({$base} - {$k}) / abs({$k}), 1) END",
                "CASE WHEN {$base} IS NULL OR {$k} IS NULL THEN NULL WHEN round({$base} - {$k}, 2) = 0 THEN 'none' WHEN {$base} > {$k} THEN 'favourable' ELSE 'adverse' END",
            );
        }
        $low = implode(', ', $low);
        $columns = implode(', ', [...$compared, ...$months]);
        $fromComparison = implode(', ', [...$compared, ...$nullMonths]);
        $fromLow = implode(', ', [...$lowCompared, ...$lowMonths]);

        return <<<SQL
            WITH
            profit AS (
            {$profit}
            ),
            cash AS (
            {$cash}
            ),
            low_point AS (
                SELECT {$low}
                FROM cash
                WHERE kind = 'balance' AND field = 'closing' AND period_type = 'month'
            )
            SELECT measure, period, status, {$columns} FROM (
                SELECT 1 AS measure_order, label AS measure, period, status, row_order, {$fromComparison}
                FROM profit
                WHERE kind = 'calculation' AND period_type IN ('ytd', 'total')
                UNION ALL
                SELECT 2, 'Bank balance', period, status, row_order, {$fromComparison}
                FROM cash
                WHERE kind = 'balance' AND field = 'closing' AND period_type IN ('ytd', 'total')
                UNION ALL
                SELECT 3, 'Lowest month-end bank balance', '{$this->totalLabel}', 'projected', 0, {$fromLow}
                FROM low_point
            )
            ORDER BY measure_order, row_order, period = '{$this->totalLabel}'
            SQL;
    }
}
