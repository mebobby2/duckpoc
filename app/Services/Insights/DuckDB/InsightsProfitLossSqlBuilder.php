<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;

/**
 * The single-farm actuals-plus-forecast profit and loss: accrual-basis
 * report lines, including milk accrued by production month and the
 * livestock valuation change, ending in net profit.
 */
final class InsightsProfitLossSqlBuilder extends InsightsMonthlyReportSqlBuilder
{
    protected function basis(): ReportBasis
    {
        return ReportBasis::Accrual;
    }

    public function sections(): array
    {
        return [
            ['income', 'Income', -1],
            ['operating_expenses', 'Operating Expenses', 1],
            ['non_operating_income', 'Non Operating Income', -1],
            ['non_operating_expenses', 'Non Operating Expenses', 1],
        ];
    }

    public function calculations(): array
    {
        return [
            ['operating_surplus', 'Operating Surplus', 'income - operating_expenses'],
            ['net_profit', 'Net Profit', 'operating_surplus + non_operating_income - non_operating_expenses'],
        ];
    }

    protected function withBalances(): bool
    {
        return false;
    }
}
