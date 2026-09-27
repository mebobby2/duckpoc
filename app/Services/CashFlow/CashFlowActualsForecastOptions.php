<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

/**
 * The query-string options of Figured's Cash Flow report that change what the
 * statement computes, named as the URL names them.
 *
 * `display=monthly` and `category_set=USER` are fixed here; comparisons,
 * scenarios (`snapshot_id`, `comparison_*`) and the dashboard widgets are
 * out of scope. `with_overdraft` adds the overdraft limit rows; the overdraft
 * INTEREST is charged whenever the farm has an overdraft configured, as
 * Figured's virtual journal handler does regardless of the option.
 */
final readonly class CashFlowActualsForecastOptions
{
    public function __construct(
        public bool $groupByTracker = true,
        public bool $excludeEoyJournals = true,
        public bool $withTotal = true,
        public bool $withOverdraft = false,
        public string $basis = 'cash',
    ) {
    }
}
