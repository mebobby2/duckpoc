<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashFlow\QueryProfiler;
use App\Services\CashFlow\TrackerCashFlowQuery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;

/**
 * Profiles the tracker Cash Flow query in a fresh process, so the HTTP
 * counters describe a COLD read.
 *
 * The equivalent panel in the report viewer profiles after the report has
 * already run in that request, so httpfs has cached what the report read and
 * the GET count comes back near zero. That is misleading in exactly the
 * direction that matters, hence this command: nothing else has touched the
 * lake in this process, so what it reports is what a first read costs.
 */
class DuckDbTrackerProfileCommand extends Command
{
    protected $signature = 'duckdb:tracker:profile
        {--farm=hero-tracker-farm-50 : Farm to profile}
        {--from=2021-01-01 : Period start}
        {--to=2021-12-31 : Period end}
        {--horizon=2021-12-31 : Actuals/forecast horizon}';

    protected $description = 'Profile the tracker Cash Flow query cold (EXPLAIN ANALYZE in a fresh process)';

    public function handle(DuckDB $db): int
    {
        $farmId = (string) $this->option('farm');

        $farm = DB::table('farms')->where('farm_id', $farmId)->first();

        if ($farm === null) {
            $this->error("Unknown farm: {$farmId}");

            return self::FAILURE;
        }

        $query = new TrackerCashFlowQuery($db, config('duckdb.attached_alias'));

        $this->info(sprintf(
            'Profiling %s, %s..%s, horizon %s (cold — nothing else has read the lake in this process)',
            $farmId,
            $this->option('from'),
            $this->option('to'),
            $this->option('horizon'),
        ));
        $this->line('');

        $profile = (new QueryProfiler($db))->profile($query->sql(), [
            'farm_id' => $farmId,
            'farm_type' => (string) $farm->farm_type,
            'region' => (string) $farm->region,
            'basis' => 'cash',
            'period_from' => (string) $this->option('from'),
            'period_to' => (string) $this->option('to'),
            'horizon' => (string) $this->option('horizon'),
        ]);

        if (!$profile['ok']) {
            $this->error('Profiling failed: '.$profile['error']);

            return self::FAILURE;
        }

        $total = $profile['total_seconds'];
        $sql = $profile['sql_seconds'];
        $storage = ($total !== null && $sql !== null) ? max(0, $total - $sql) : null;

        $this->line(sprintf('    total            %s', $total !== null ? number_format($total, 2).'s' : '—'));
        $this->line(sprintf('    SQL engine       %s', $sql !== null ? number_format($sql, 2).'s' : '—'));
        $this->line(sprintf('    storage/waiting  %s', $storage !== null ? number_format($storage, 2).'s' : '—'));
        $this->line(sprintf('    HTTP GETs        %s', $profile['http_gets'] !== null ? number_format($profile['http_gets']) : '—'));
        $this->line(sprintf('    transferred      %s', $profile['bytes_in'] ?? '—'));

        if ($total > 0 && $storage !== null) {
            $this->line('');
            $this->line(sprintf(
                '    => %.0f%% of the time was spent waiting on storage, %.0f%% in the query engine.',
                $storage / $total * 100,
                $sql / $total * 100,
            ));

            if ($profile['http_gets'] > 0) {
                $this->line(sprintf(
                    '    => %s GETs for %s: %.1f ms per request.',
                    number_format($profile['http_gets']),
                    $profile['bytes_in'] ?? '?',
                    $storage * 1000 / $profile['http_gets'],
                ));
            }
        }

        if (!empty($profile['operators'])) {
            $this->line('');
            $this->line('    Slowest operators:');
            foreach (array_slice($profile['operators'], 0, 6) as $op) {
                $this->line(sprintf('        %-24s %6.2fs', $op['name'], $op['seconds']));
            }
        }

        return self::SUCCESS;
    }
}
