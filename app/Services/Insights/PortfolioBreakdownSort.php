<?php

declare(strict_types=1);

namespace App\Services\Insights;

/**
 * How the per-farm breakdown is ordered, as the statement's ORDER BY: the
 * page shows rows in the order the SQL returns them.
 */
enum PortfolioBreakdownSort: string
{
    case Variance = 'variance';
    case Farm = 'farm';
    case Original = 'original';
    case Modelled = 'modelled';

    /** The ORDER BY terms over the statement's output columns, farm id breaking ties. */
    public function orderBy(): string
    {
        return match ($this) {
            self::Variance => 'variance ASC, farm_id ASC',
            self::Farm => 'farm_id ASC',
            self::Original => 'original DESC, farm_id ASC',
            self::Modelled => 'modelled DESC, farm_id ASC',
        };
    }
}
