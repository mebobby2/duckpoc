<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use InvalidArgumentException;

/**
 * A period of a plan comparison: `YTD`, `Season` or one month as `YYYY-MM`.
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
        if (preg_match('/^(YTD|Season|\d{4}-\d{2})$/', $period) !== 1) {
            throw new InvalidArgumentException("A period is YTD, Season or YYYY-MM, not '{$period}'.");
        }

        return "{$column} = '{$period}'";
    }
}
