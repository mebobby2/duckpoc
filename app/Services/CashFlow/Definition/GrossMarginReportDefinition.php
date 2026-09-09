<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Definition;

/**
 * Figured's Gross Margin report, per operating entity, as data.
 *
 * "Operating entity" is a tracker — a distinct production unit within a farm
 * (a livestock mob, a crop season, a dairy herd). Figured builds this family as
 * `BaseGrossMargin` with `LivestockGrossMargin` / `CropGrossMargin` /
 * `DairyGrossMargin` on top; only the livestock shape is reproduced here.
 *
 * The distinction that makes this a different report from tracker Cash Flow:
 * **Gross Margin is a ratio, not a difference.** `BaseGrossMargin` emits a
 * financial row *and* a quantity row per tracker, and per-unit figures come
 * from dividing one by the other. Income minus costs alone is a gross profit;
 * it becomes a margin only once expressed per head.
 *
 * Why it is the slow report in Figured, and the thing this exists to test:
 * `Types::getTrackerQtys()` loops `foreach ($trackerGroups ...)` and calls
 * `TrackerQuantityService` once per tracker — and `BaseGrossMargin` invokes it
 * at both line 76 and line 205, so a 50-tracker farm performs 100 quantity
 * computations, each with its own `Tracker::findOrFail()` plus stock-movement
 * work. The claim under test is that the whole thing is one grouped scan plus
 * one window function partitioned by tracker.
 */
final class GrossMarginReportDefinition
{
    /**
     * Financial rows, aggregated per (month, tracker) from journal lines.
     *
     * @return list<ReportSection>
     */
    public function financialSections(): array
    {
        return [
            new ReportSection('tracker_income', 'tracker_income', SignRule::FlipRevenueAccounts, 'Income'),
            new ReportSection('tracker_direct_costs', 'tracker_direct_costs', SignRule::AsStored, 'Direct Costs'),
        ];
    }

    /**
     * Movement rows, summed per (month, tracker) from MySQL stock movements.
     *
     * Kept as separate rows rather than a single net figure because that is
     * how the real report presents them — a reader wants to see whether a herd
     * shrank through sales or through deaths.
     *
     * @return list<array{field: string, label: string}>
     */
    public function movementRows(): array
    {
        return [
            ['field' => 'purchases', 'label' => 'Purchases (head)'],
            ['field' => 'births', 'label' => 'Births (head)'],
            ['field' => 'sales', 'label' => 'Sales (head)'],
            ['field' => 'deaths', 'label' => 'Deaths (head)'],
        ];
    }

    /**
     * Derived rows, in evaluation order. Each may use any field above it.
     *
     * `average_head` is the denominator: farm accounting expresses a margin
     * against the average herd carried over the period, not the closing count,
     * because stock moves through the month.
     *
     * @return list<CalculationRow>
     */
    public function calculationRows(): array
    {
        return [
            new CalculationRow(
                'net_movement',
                'purchases + births - sales - deaths',
                'Net Movement (head)',
            ),
            new CalculationRow(
                'gross_margin',
                'tracker_income - tracker_direct_costs',
                'Gross Margin',
                isSubtotal: true,
            ),
            new CalculationRow(
                'average_head',
                '(opening_head + closing_head) / 2.0',
                'Average Head',
            ),
            // NULLIF guards a tracker with no stock in the period — dividing
            // by zero head would otherwise poison the whole column.
            new CalculationRow(
                'gross_margin_per_head',
                'gross_margin / NULLIF((opening_head + closing_head) / 2.0, 0)',
                'GM per Head',
                isSubtotal: true,
            ),
        ];
    }

    /**
     * The stock rows the running chain produces, for display.
     *
     * @return list<array{field: string, label: string}>
     */
    public function stockRows(): array
    {
        return [
            ['field' => 'opening_head', 'label' => 'Opening (head)'],
            ['field' => 'closing_head', 'label' => 'Closing (head)'],
        ];
    }

    /**
     * Row order and labels, financial and quantity interleaved the way the
     * real report presents a tracker's block.
     *
     * @return list<array{field: string, label: string, isSubtotal: bool, kind: string}>
     */
    public function displayRows(): array
    {
        $rows = [];

        foreach ($this->financialSections() as $section) {
            $rows[] = ['field' => $section->field, 'label' => $section->label, 'isSubtotal' => false, 'kind' => 'money'];
        }

        $rows[] = ['field' => 'gross_margin', 'label' => 'Gross Margin', 'isSubtotal' => true, 'kind' => 'money'];

        $rows[] = ['field' => 'opening_head', 'label' => 'Opening (head)', 'isSubtotal' => false, 'kind' => 'head'];

        foreach ($this->movementRows() as $row) {
            $rows[] = ['field' => $row['field'], 'label' => $row['label'], 'isSubtotal' => false, 'kind' => 'head'];
        }

        $rows[] = ['field' => 'net_movement', 'label' => 'Net Movement (head)', 'isSubtotal' => false, 'kind' => 'head'];
        $rows[] = ['field' => 'closing_head', 'label' => 'Closing (head)', 'isSubtotal' => true, 'kind' => 'head'];
        $rows[] = ['field' => 'average_head', 'label' => 'Average Head', 'isSubtotal' => false, 'kind' => 'head'];
        $rows[] = ['field' => 'gross_margin_per_head', 'label' => 'GM per Head', 'isSubtotal' => true, 'kind' => 'money'];

        return $rows;
    }
}
