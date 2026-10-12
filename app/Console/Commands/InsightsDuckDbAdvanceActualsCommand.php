<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Insights\DuckDB\InsightsDuckDb;
use App\Services\Insights\DuckDB\InsightsDuckDbActualsAdvancer;
use App\Services\Insights\DuckDB\PortfolioScope;
use Illuminate\Console\Command;
use Throwable;

class InsightsDuckDbAdvanceActualsCommand extends Command
{
    protected $signature = 'insights:duckdb:advance-actuals
        {--practice=3 : Practice to move forward}
        {--to=2026-09-30 : The new actuals horizon}
        {--batch=50 : Farms per lake insert}';

    protected $description = 'Turn a practice\'s forecast at the horizon into each farm\'s active plan, and fill the months since with drifting actuals';

    public function handle(): int
    {
        if (config('duckdb.storage') !== 's3') {
            $this->error('Run this from the app-minio container: the lake lives on MinIO.');

            return self::FAILURE;
        }

        try {
            $started = microtime(true);
            $advancer = new InsightsDuckDbActualsAdvancer(
                InsightsDuckDb::connect(writableMysql: true),
                (int) $this->option('practice'),
                PortfolioScope::HORIZON,
                (string) $this->option('to'),
                (int) $this->option('batch'),
            );
            $counts = $advancer->advance(fn (string $line) => $this->line(sprintf('  [%6.1f s] %s', microtime(true) - $started, $line)));

            $this->line(sprintf('  advanced in %.1f s', microtime(true) - $started));
            foreach ($counts as $what => $n) {
                $this->line(sprintf('    %-20s %12s', $what, number_format($n)));
            }
        } catch (Throwable $e) {
            $this->error('Advance failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
