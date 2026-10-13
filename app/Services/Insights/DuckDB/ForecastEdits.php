<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

/**
 * Which changes to the forecast a report includes, beyond the forecast the
 * plan was agreed on: none (the plan as agreed), the scenarios the farm has
 * adopted since (where it is now heading), or those and one proposed
 * scenario (what if).
 */
enum ForecastEdits
{
    case None;
    case Adopted;
    case AdoptedAndProposed;
}
