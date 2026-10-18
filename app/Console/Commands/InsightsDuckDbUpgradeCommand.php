<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Insights\DuckDB\InsightsDuckDb;
use App\Services\Insights\DuckDB\InsightsDuckDbSchema;
use Illuminate\Console\Command;
use Throwable;

class InsightsDuckDbUpgradeCommand extends Command
{
    protected $signature = 'insights:duckdb:upgrade
        {--lake : Also add the lake\'s new columns; the lake must not be open elsewhere (stop the MCP server first)}';

    /** @var list<string> */
    protected $aliases = ['insights:duckdb:prepare-scenarios'];

    protected $description = 'Bring an existing Insights database up to the current shape: new tables and columns, budget snapshots, missing system accounts. Adds, never deletes; safe to re-run';

    public function handle(): int
    {
        try {
            $schema = new InsightsDuckDbSchema();
            $schema->upgradeMySql();
            $added = $schema->addMissingSystemAccounts();
            $this->info("MySQL up to date; {$added} missing system account(s) added.");

            if ($this->option('lake')) {
                $schema->upgradeLake(InsightsDuckDb::connect(writable: true));
                $this->info('Lake up to date.');
            }
            $this->line('Restart the MCP server so it sees the new tables and columns.');
        } catch (Throwable $e) {
            $this->error('Upgrade failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
