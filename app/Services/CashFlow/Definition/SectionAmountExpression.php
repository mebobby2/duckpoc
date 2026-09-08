<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Definition;

/**
 * The single place a `SignRule` becomes SQL.
 *
 * Shared by every report builder deliberately. A wrong sign is the one error
 * here that does not announce itself — the report still balances against
 * itself, it is just wrong throughout (see the README's oracle section, where
 * exactly that happened). A second copy of this `match` would be free to
 * drift from the first, and nothing would fail until a number reached someone
 * who knew what it should have been.
 */
final class SectionAmountExpression
{
    /**
     * @param string $amountColumn the qualified amount column, e.g. `l.amount`
     * @param string $classColumn  the qualified account-class column
     */
    public static function for(
        SignRule $rule,
        string $amountColumn = 'l.amount',
        string $classColumn = 'l.account_class',
    ): string {
        return match ($rule) {
            SignRule::AsStored => $amountColumn,
            SignRule::InvertWholeSection => '-'.$amountColumn,
            SignRule::FlipRevenueAccounts =>
                "CASE WHEN {$classColumn} = 'REVENUE' THEN -{$amountColumn} ELSE {$amountColumn} END",
        };
    }
}
