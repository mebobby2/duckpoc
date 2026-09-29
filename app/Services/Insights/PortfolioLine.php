<?php

declare(strict_types=1);

namespace App\Services\Insights;

/**
 * The Portfolio Modelling output lines, in display order.
 *
 * Four are read straight from the ledger and can carry an assumption. The
 * rest are calculated from them, so an assumption on milk income flows into
 * total income and operating surplus, then on cash basis into net cash
 * movement and, season after season, closing cash (FIP's flow-on), and on
 * accrual basis into net profit.
 */
enum PortfolioLine: string
{
    case MilkIncome = 'milk_income';
    case OtherIncome = 'other_income';
    case TotalIncome = 'total_income';
    case Fertiliser = 'fertiliser';
    case OtherOperatingExpenses = 'other_operating_expenses';
    case TotalOperatingExpenses = 'total_operating_expenses';
    case OperatingSurplus = 'operating_surplus';
    case NetCashMovement = 'net_cash_movement';
    case ClosingCash = 'closing_cash';
    case NetProfit = 'net_profit';

    /**
     * @return list<self>
     */
    public static function forBasis(ReportBasis $basis): array
    {
        $shared = [
            self::MilkIncome, self::OtherIncome, self::TotalIncome,
            self::Fertiliser, self::OtherOperatingExpenses, self::TotalOperatingExpenses,
            self::OperatingSurplus,
        ];

        return match ($basis) {
            ReportBasis::Cash => [...$shared, self::NetCashMovement, self::ClosingCash],
            ReportBasis::Accrual => [...$shared, self::NetProfit],
        };
    }

    public function isAssumable(): bool
    {
        return match ($this) {
            self::MilkIncome, self::OtherIncome, self::Fertiliser, self::OtherOperatingExpenses => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MilkIncome => 'Milk Income',
            self::OtherIncome => 'Other Income',
            self::TotalIncome => 'Total Income',
            self::Fertiliser => 'Fertiliser',
            self::OtherOperatingExpenses => 'Other Operating Expenses',
            self::TotalOperatingExpenses => 'Total Operating Expenses',
            self::OperatingSurplus => 'Operating Surplus',
            self::NetCashMovement => 'Net Cash Movement',
            self::ClosingCash => 'Closing Cash',
            self::NetProfit => 'Net Profit',
        };
    }
}
