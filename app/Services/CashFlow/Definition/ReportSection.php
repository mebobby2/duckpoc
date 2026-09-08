<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Definition;

/**
 * One aggregated line of the report: sum the accounts in `$category`, apply
 * `$signRule`, expose the result as `$field`.
 *
 * The DuckDB equivalent of a `ReportSection` on Figured's own
 * `CashFlowStructureBuilder` — minus the condition/priority waterfall, since
 * this PoC resolves an account to exactly one category up front rather than
 * assigning groupings to sections at run time.
 */
final readonly class ReportSection
{
    public function __construct(
        /** Output column name, and the name calculation formulas refer to. */
        public string $field,
        /** The `accounts.account_category` value that feeds this section. */
        public string $category,
        public SignRule $signRule,
        /** Row label for the report viewer. */
        public string $label,
    ) {
    }
}
