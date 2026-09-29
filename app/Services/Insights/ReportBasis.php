<?php

declare(strict_types=1);

namespace App\Services\Insights;

/**
 * Cash counts money when it moves and ends in the bank balance; accrual
 * counts income and costs when they are earned or incurred, adds the
 * non-cash livestock valuation change, and ends in net profit. Portfolio
 * Modelling offers both, as FIP does.
 */
enum ReportBasis: string
{
    case Cash = 'cash';
    case Accrual = 'accrual';

    /** The category groups a profit and loss report is built from. */
    public const array PROFIT_AND_LOSS_GROUPS = ['income', 'operating_expenses', 'non_operating_income', 'non_operating_expenses'];

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash basis',
            self::Accrual => 'Accrual basis',
        };
    }
}
