<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Definition;

/**
 * A per-tracker line: sum the tracker-tagged lines whose account is in
 * `$accountClass`, apply `$signRule`, expose the result as `$field`.
 *
 * Tracker sections select by account CLASS where farm sections select by
 * category, because that is how Figured's structure builders declare them —
 * `MilkStructureBuilder::createIncomeSection()` takes every REVENUE line
 * tracked to the tracker, whatever account it sits on, and the costs section
 * takes every EXPENSE line the same way.
 */
final readonly class TrackerSection
{
    public function __construct(
        public string $field,
        public string $accountClass,
        public SignRule $signRule,
        public string $label,
    ) {
    }
}
