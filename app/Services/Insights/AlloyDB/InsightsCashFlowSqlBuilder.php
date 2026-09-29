<?php

declare(strict_types=1);

namespace App\Services\Insights\AlloyDB;

use App\Services\Insights\ReportBasis;

/**
 * The single-farm actuals-plus-forecast cash flow: cash-basis report lines
 * in the cash flow's sections, ending in net cash movement and the bank
 * balance.
 */
final class InsightsCashFlowSqlBuilder extends InsightsMonthlyReportSqlBuilder
{
    protected function basis(): ReportBasis
    {
        return ReportBasis::Cash;
    }

    public function sections(): array
    {
        return [
            ['income', 'Income', -1],
            ['operating_expenses', 'Operating Expenses', 1],
            ['non_operating_income', 'Non Operating Income', -1],
            ['non_operating_expenses', 'Non Operating Expenses', 1],
            ['non_operating_movements', 'Non Operating Movements', -1],
            ['equity_movements', 'Equity Movements', -1],
            ['gst', 'GST', -1],
        ];
    }

    public function calculations(): array
    {
        return [
            ['operating_surplus', 'Operating Surplus', 'income - operating_expenses'],
            ['total_surplus', 'Total Surplus', 'operating_surplus + non_operating_income - non_operating_expenses'],
            ['net_cash_movement', 'Net Cash Movement', 'total_surplus + non_operating_movements + equity_movements + gst'],
        ];
    }

    protected function withBalances(): bool
    {
        return true;
    }
}
