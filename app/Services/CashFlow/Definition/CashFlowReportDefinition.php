<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Definition;

/**
 * Figured's Cash Flow report, as data.
 *
 * This file is the readable one: everything about *what* the report is lives
 * here, and nothing about *how* it is queried. `ReportSqlBuilder` turns it
 * into DuckDB SQL. Adding a section or a derived row means adding a line
 * here, not editing SQL.
 *
 * Kept deliberately parallel to the real
 * `Figured\Packages\Core\Reporting\Builders\Reports\CashFlowStructureBuilder`
 * so the two can be diffed by eye — the section list and the calculation
 * formulas below are the same ones it declares.
 */
final class CashFlowReportDefinition
{
    /**
     * @return list<ReportSection>
     */
    public function sections(): array
    {
        return [
            // Figured calls this section `other_income`; the shorter name is
            // used here since this PoC has no tracker income sections to
            // distinguish it from.
            new ReportSection('income', 'other_income', SignRule::FlipRevenueAccounts, 'Income'),
            new ReportSection('direct_costs', 'direct_costs', SignRule::AsStored, 'Direct Costs'),
            new ReportSection('operating_expenses', 'operating_expenses', SignRule::AsStored, 'Operating Expenses'),
            new ReportSection('non_operating_income', 'non_operating_income', SignRule::FlipRevenueAccounts, 'Non Operating Income'),
            new ReportSection('non_operating_expenses', 'non_operating_expenses', SignRule::AsStored, 'Non Operating Expenses'),

            // The three the real structure declares setInverse(true).
            new ReportSection('non_operating_movements', 'non_operating_movements', SignRule::InvertWholeSection, 'Non Operating Movements'),
            new ReportSection('equity_movements', 'equity_movements', SignRule::InvertWholeSection, 'Equity Movements'),
            new ReportSection('gst', 'gst', SignRule::InvertWholeSection, 'GST'),
        ];
    }

    /**
     * Evaluated in order — each may refer to any section or to a row above it.
     *
     * @return list<CalculationRow>
     */
    public function calculationRows(): array
    {
        return [
            new CalculationRow(
                'gross_profit',
                'income - direct_costs',
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
                'total_surplus',
                'operating_surplus + non_operating_income - non_operating_expenses',
                'Total Surplus',
                isSubtotal: true,
            ),
            new CalculationRow(
                'net_cash_movement',
                'total_surplus + non_operating_movements + equity_movements + gst',
                'Net Cash Movement',
                isSubtotal: true,
            ),
        ];
    }

    /**
     * The row whose running total drives the opening/closing balance chain —
     * `OpeningClosingBalance`'s job on the real structure.
     */
    public function runningBalanceSource(): string
    {
        return 'net_cash_movement';
    }

    /**
     * Row order and labels for the report viewer, sections and calculation
     * rows interleaved the way the real report presents them.
     *
     * @return list<array{field: string, label: string, isSubtotal: bool}>
     */
    public function displayRows(): array
    {
        $byField = [];
        foreach ($this->sections() as $section) {
            $byField[$section->field] = ['field' => $section->field, 'label' => $section->label, 'isSubtotal' => false];
        }
        foreach ($this->calculationRows() as $row) {
            $byField[$row->field] = ['field' => $row->field, 'label' => $row->label, 'isSubtotal' => $row->isSubtotal];
        }

        $order = [
            'income',
            'direct_costs',
            'gross_profit',
            'operating_expenses',
            'operating_surplus',
            'total_surplus',
            'net_cash_movement',
        ];

        $rows = [];
        foreach ($order as $field) {
            if (isset($byField[$field])) {
                $rows[] = $byField[$field];
            }
        }

        $rows[] = ['field' => 'opening', 'label' => 'Opening Balance', 'isSubtotal' => false];
        $rows[] = ['field' => 'closing', 'label' => 'Closing Balance', 'isSubtotal' => true];

        return $rows;
    }
}
