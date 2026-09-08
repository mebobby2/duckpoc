<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Definition;

/**
 * How a section's stored amounts become the figure a reader sees.
 *
 * Amounts are stored in Xero's journal convention — positive for a debit,
 * negative for a credit — so some sections need flipping on the way out and
 * some do not. Getting this wrong is silent: the report still balances
 * against itself, it is just wrong (see the README's oracle section).
 */
enum SignRule
{
    /** Sum as stored. Expense-family sections: a debit is already positive. */
    case AsStored;

    /**
     * Flip REVENUE-class accounts only, mirroring
     * `XeroAccount::isAccountInversedForUser()`, which returns true for
     * REVENUE and nothing else. Revenue is stored as a credit (negative) and
     * shown positive.
     */
    case FlipRevenueAccounts;

    /**
     * Flip the whole section regardless of account class — the SQL equivalent
     * of `ReportSection::setInverse(true)` on the real structure.
     */
    case InvertWholeSection;
}
