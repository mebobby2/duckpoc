<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use InvalidArgumentException;

/**
 * A period of a comparison: `YTD`, the whole window (`Season`, or `Total`
 * for a window that is not a season) or one month as `YYYY-MM`.
 * Checked here because it is written into the statement as a literal.
 */
final class PlanPeriod
{
    /** The statement's condition selecting the period, or every period when null. */
    public static function condition(?string $period, string $column): string
    {
        if ($period === null) {
            return 'true';
        }
        if (preg_match('/^(YTD|Season|Total|\d{4}-\d{2})$/', $period) !== 1) {
            throw new InvalidArgumentException("A period is YTD, Season, Total or YYYY-MM, not '{$period}'.");
        }

        return "{$column} = '{$period}'";
    }
}
