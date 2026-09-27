<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Definition;

/**
 * Figured's Cash Flow report, as it is run from `/reports/data/cash_flow`
 * with `type=actualsForecast`, as data.
 *
 * Every section and calculation row `CashFlowStructureBuilder` declares is
 * here, in its order and with its formula, plus the per-tracker sections the
 * milk, livestock and crop structure builders contribute under Income and
 * the opening/closing rows `OpeningClosingBalance` appends. The two hidden
 * sections (`figured_internal`, `bank_movements`) exist only to keep bank
 * and internal lines out of the totals; the SQL builder applies that as a
 * predicate rather than carrying two rows nobody sees.
 *
 * `overdraft_limit` and `overdraft_headroom` are the `OverdraftLimits`
 * dynamic rows, added when the report is run `with_overdraft=1`.
 */
final class CashFlowActualsForecastReportDefinition
{
    /**
     * @return list<TrackerSection>
     */
    public function trackerSections(): array
    {
        return [
            new TrackerSection('tracker_income', 'REVENUE', SignRule::FlipRevenueAccounts, 'Income'),
            new TrackerSection('tracker_costs', 'EXPENSE', SignRule::AsStored, 'Costs'),
        ];
    }

    /**
     * @return list<CalculationRow>
     */
    public function trackerCalculationRows(): array
    {
        return [
            new CalculationRow('tracker_gross_profit', 'tracker_income - tracker_costs', 'Cash Profit', isSubtotal: true),
        ];
    }

    public function trackerRollupField(): string
    {
        return 'tracker_gross_profit';
    }

    public function trackerRollupTotalField(): string
    {
        return 'trackers_gross_profit';
    }

    /**
     * @return list<ReportSection>
     */
    public function farmSections(): array
    {
        return [
            new ReportSection('other_income', 'other_income', SignRule::FlipRevenueAccounts, 'Other Income'),
            new ReportSection('direct_costs', 'direct_costs', SignRule::AsStored, 'Direct Costs'),
            new ReportSection('operating_expenses', 'operating_expenses', SignRule::AsStored, 'Operating Expenses'),
            new ReportSection('non_operating_income', 'non_operating_income', SignRule::FlipRevenueAccounts, 'Non Operating Income'),
            new ReportSection('non_operating_expenses', 'non_operating_expenses', SignRule::AsStored, 'Non Operating Expenses'),
            new ReportSection('non_operating_movements', 'non_operating_movements', SignRule::InvertWholeSection, 'Non Operating Movements'),
            new ReportSection('equity_movements', 'equity_movements', SignRule::InvertWholeSection, 'Equity Movements'),
            new ReportSection('gst', 'gst', SignRule::InvertWholeSection, 'GST'),
        ];
    }

    /**
     * @return list<CalculationRow>
     */
    public function calculationRows(): array
    {
        return [
            new CalculationRow('gross_profit', 'trackers_gross_profit + other_income - direct_costs', 'Gross Profit', isSubtotal: true),
            new CalculationRow('operating_surplus', 'gross_profit - operating_expenses', 'Operating Surplus', isSubtotal: true),
            new CalculationRow('total_surplus', 'operating_surplus + non_operating_income - non_operating_expenses', 'Total Surplus', isSubtotal: true),
            new CalculationRow('net_cash_movement', 'total_surplus + non_operating_movements + equity_movements + gst', 'Net Cash Movement', isSubtotal: true),
        ];
    }

    public function runningBalanceSource(): string
    {
        return 'net_cash_movement';
    }

    /**
     * The per-month columns that add up in the Total column. Balances and
     * the overdraft rows do not: opening is the first month's, closing the
     * last month's, and `OverdraftLimits` sets `hide_total` on its rows.
     *
     * @return list<string>
     */
    public function totalledFields(): array
    {
        $fields = [$this->trackerRollupTotalField()];

        foreach ($this->farmSections() as $section) {
            $fields[] = $section->field;
        }

        foreach ($this->calculationRows() as $row) {
            $fields[] = $row->field;
        }

        return $fields;
    }

    /**
     * Rows of one tracker's block, in display order.
     *
     * @return list<array{field: string, label: string, isSubtotal: bool}>
     */
    public function trackerDisplayRows(): array
    {
        $rows = [];

        foreach ($this->trackerSections() as $section) {
            $rows[] = ['field' => $section->field, 'label' => $section->label, 'isSubtotal' => false];
        }

        foreach ($this->trackerCalculationRows() as $row) {
            $rows[] = ['field' => $row->field, 'label' => $row->label, 'isSubtotal' => $row->isSubtotal];
        }

        return $rows;
    }

    /**
     * Rows of the consolidated report, in the order the real report shows
     * them: each section followed by the calculation row that closes it.
     *
     * @return list<array{field: string, label: string, isSubtotal: bool, kind: string}>
     */
    public function displayRows(bool $withOverdraft = false): array
    {
        $byField = [];

        $byField[$this->trackerRollupTotalField()] = [
            'field' => $this->trackerRollupTotalField(),
            'label' => 'Trackers Cash Profit',
            'isSubtotal' => true,
            'kind' => 'rollup',
        ];

        foreach ($this->farmSections() as $section) {
            $byField[$section->field] = ['field' => $section->field, 'label' => $section->label, 'isSubtotal' => false, 'kind' => 'section'];
        }

        foreach ($this->calculationRows() as $row) {
            $byField[$row->field] = ['field' => $row->field, 'label' => $row->label, 'isSubtotal' => $row->isSubtotal, 'kind' => 'calculation'];
        }

        $order = [
            'trackers_gross_profit',
            'other_income',
            'direct_costs',
            'gross_profit',
            'operating_expenses',
            'operating_surplus',
            'non_operating_income',
            'non_operating_expenses',
            'total_surplus',
            'non_operating_movements',
            'equity_movements',
            'gst',
            'net_cash_movement',
        ];

        $rows = [];
        foreach ($order as $field) {
            $rows[] = $byField[$field];
        }

        $rows[] = ['field' => 'opening', 'label' => 'Opening Balance', 'isSubtotal' => false, 'kind' => 'balance'];
        $rows[] = ['field' => 'closing', 'label' => 'Closing Balance', 'isSubtotal' => true, 'kind' => 'balance'];

        if ($withOverdraft) {
            $rows[] = ['field' => 'overdraft_limit', 'label' => 'Overdraft Limit', 'isSubtotal' => false, 'kind' => 'limit'];
            $rows[] = ['field' => 'overdraft_headroom', 'label' => 'Overdraft Headroom', 'isSubtotal' => false, 'kind' => 'limit'];
        }

        return $rows;
    }
}
