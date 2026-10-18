<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use InvalidArgumentException;

/**
 * One version of a report in a comparison: the forecast as known at some
 * point (the horizon it treats as actuals to, and which forecast changes it
 * reads), shifted by whole months to line up with the window shown, and
 * optionally cut to its actual months.
 *
 * Last year is the current version twelve months back; a past season, the
 * same further back; the budget, the forecast as agreed; a snapshot, the
 * forecast as it stood when taken; a scenario, the current forecast with a
 * proposed change.
 */
final readonly class ReportSide
{
    public function __construct(
        public string $key,
        public string $label,
        public string $horizonParameter,
        public ForecastEdits $edits,
        public int $monthOffset = 0,
        public bool $actualsOnly = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw new InvalidArgumentException("A side's key names its columns: {$key}");
        }
        if ($monthOffset > 0) {
            throw new InvalidArgumentException('A side can only look back.');
        }
    }
}
