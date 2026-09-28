<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Illuminate\Support\Facades\DB;

/**
 * `ReportingGroupFarms::get()`: a parent farm's child entities, in farm order
 * as `ReportingGroupConsolidator` sorts them. Empty for a farm that is not a
 * reporting-group parent, which makes its report an ordinary single-farm one.
 */
final class ReportingGroupEntities
{
    /** @return list<string> */
    public static function for(string $farmId): array
    {
        return DB::table('reporting_group_farms')
            ->where('parent_farm_id', $farmId)
            ->orderBy('child_farm_id')
            ->pluck('child_farm_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }
}
