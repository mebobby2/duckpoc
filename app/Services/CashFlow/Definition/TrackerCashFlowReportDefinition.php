<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Definition;

/**
 * Cash Flow with per-tracker income sections, as data.
 *
 * This is the shape Figured's own `CashFlowStructureBuilder` produces once a
 * farm has trackers: `LivestockStructureBuilder::getSections()` contributes a
 * section set per tracker and accumulates a gross-profit id per tracker into
 * `$trackerGrossProfitIds`, which are then summed into the consolidated
 * formula by string concatenation:
 *
 *     implode(' + ', $trackerGrossProfitIds) . ' + other_income - direct_costs'
 *
 * At 50 trackers that is a 50-term formula string assembled at runtime and
 * evaluated per interval. Here the same thing is one `SUM(...)` over a
 * grouped result — the tracker count changes a cardinality, not the SQL.
 * That difference is the point of this report, so it is stated here rather
 * than buried in the builder.
 *
 * Tracker sections and farm-level sections are kept as separate lists because
 * they aggregate differently: tracker sections group by `tracker_id` as well
 * as month, farm-level sections group by month alone over untagged lines. The
 * real report makes the same distinction — per-tracker gross profits are
 * summed *alongside* farm-level `other_income` and `direct_costs`, not
 * merged into them.
 */
final class TrackerCashFlowReportDefinition
{
    /**
     * Aggregated per (month, tracker) — one row per tracker per month.
     *
     * @return list<ReportSection>
     */
    public function trackerSections(): array
    {
        return [
            new ReportSection('tracker_income', 'tracker_income', SignRule::FlipRevenueAccounts, 'Income'),
            new ReportSection('tracker_direct_costs', 'tracker_direct_costs', SignRule::AsStored, 'Direct Costs'),
        ];
    }

    /**
     * Evaluated within each (month, tracker) group.
     *
     * @return list<CalculationRow>
     */
    public function trackerCalculationRows(): array
    {
        return [
            new CalculationRow(
                'tracker_gross_profit',
                'tracker_income - tracker_direct_costs',
                'Gross Profit',
                isSubtotal: true,
            ),
        ];
    }

    /**
     * The tracker-level row that rolls up into the consolidated report. Named
     * here so the builder does not have to guess which of the tracker rows is
     * the one Figured sums.
     */
    public function trackerRollupField(): string
    {
        return 'tracker_gross_profit';
    }

    /** Output column holding the sum of every tracker's rollup field. */
    public function trackerRollupTotalField(): string
    {
        return 'trackers_gross_profit';
    }

    /**
     * Aggregated per month over lines belonging to no tracker.
     *
     * @return list<ReportSection>
     */
    public function farmSections(): array
    {
        return [
            new ReportSection('other_income', 'other_income', SignRule::FlipRevenueAccounts, 'Other Income'),
            new ReportSection('direct_costs', 'direct_costs', SignRule::AsStored, 'Direct Costs'),
            new ReportSection('operating_expenses', 'operating_expenses', SignRule::AsStored, 'Operating Expenses'),
            new ReportSection('gst', 'gst', SignRule::InvertWholeSection, 'GST'),
        ];
    }

    /**
     * The consolidated chain. `trackers_gross_profit` is the tracker rollup —
     * Figured's concatenated per-tracker sum, expressed once.
     *
     * @return list<CalculationRow>
     */
    public function calculationRows(): array
    {
        return [
            new CalculationRow(
                'gross_profit',
                'trackers_gross_profit + other_income - direct_costs',
                'Gross Profit',
                isSubtotal: true,
            ),
            new CalculationRow(
                'operating_surplus',
                'gross_profit - operating_expenses',
                'Operating Surplus',
                isSubtotal: true,
            ),
            new CalculationRow(
                'net_cash_movement',
                'operating_surplus + gst',
                'Net Cash Movement',
                isSubtotal: true,
            ),
        ];
    }

    public function runningBalanceSource(): string
    {
        return 'net_cash_movement';
    }

    /**
     * Row order and labels for the consolidated half of the viewer.
     *
     * @return list<array{field: string, label: string, isSubtotal: bool}>
     */
    public function displayRows(): array
    {
        $rows = [
            ['field' => 'trackers_gross_profit', 'label' => 'Trackers Gross Profit', 'isSubtotal' => true],
        ];

        foreach ($this->farmSections() as $section) {
            $rows[] = ['field' => $section->field, 'label' => $section->label, 'isSubtotal' => false];
        }

        foreach ($this->calculationRows() as $row) {
            $rows[] = ['field' => $row->field, 'label' => $row->label, 'isSubtotal' => $row->isSubtotal];
        }

        $rows[] = ['field' => 'opening', 'label' => 'Opening Balance', 'isSubtotal' => false];
        $rows[] = ['field' => 'closing', 'label' => 'Closing Balance', 'isSubtotal' => true];

        return $this->inReportOrder($rows);
    }

    /**
     * @param list<array{field: string, label: string, isSubtotal: bool}> $rows
     *
     * @return list<array{field: string, label: string, isSubtotal: bool}>
     */
    private function inReportOrder(array $rows): array
    {
        $order = [
            'trackers_gross_profit',
            'other_income',
            'direct_costs',
            'gross_profit',
            'operating_expenses',
            'operating_surplus',
            'gst',
            'net_cash_movement',
            'opening',
            'closing',
        ];

        $byField = [];
        foreach ($rows as $row) {
            $byField[$row['field']] = $row;
        }

        $ordered = [];
        foreach ($order as $field) {
            if (isset($byField[$field])) {
                $ordered[] = $byField[$field];
            }
        }

        return $ordered;
    }
}
