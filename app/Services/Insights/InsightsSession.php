<?php

declare(strict_types=1);

namespace App\Services\Insights;

use Illuminate\Database\ConnectionInterface;
use WeakMap;

/**
 * Session settings every Insights statement runs under. Session-scoped, so
 * nothing else on the server changes.
 */
final class InsightsSession
{
    /**
     * PostgreSQL's defaults give a query 2 parallel workers; the cash flow
     * phase measured 4.27 s at 2 against 1.36 s at 10 on the same scan.
     */
    private const int PARALLEL_WORKERS = 16;

    /**
     * At the 4 MB default the planner sorted all 21M scanned lines of the
     * 250-farm practice to group them, spilling ~175 MB per worker, instead
     * of each worker hashing its share into a few hundred thousand groups.
     */
    private const string WORK_MEM = '256MB';

    /** @var WeakMap<ConnectionInterface, true>|null */
    private static ?WeakMap $configured = null;

    public static function configure(ConnectionInterface $db): void
    {
        self::$configured ??= new WeakMap();
        if (isset(self::$configured[$db])) {
            return;
        }

        $db->statement(sprintf('SET max_parallel_workers = %d', self::PARALLEL_WORKERS));
        $db->statement(sprintf('SET max_parallel_workers_per_gather = %d', self::PARALLEL_WORKERS - 1));
        $db->statement(sprintf("SET work_mem = '%s'", self::WORK_MEM));
        self::$configured[$db] = true;
    }
}
