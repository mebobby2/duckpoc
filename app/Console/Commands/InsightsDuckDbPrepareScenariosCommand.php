<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Insights\DuckDB\InsightsDuckDbSchema;
use Illuminate\Console\Command;
use Throwable;

class InsightsDuckDbPrepareScenariosCommand extends Command
{
    protected $signature = 'insights:duckdb:prepare-scenarios';

    protected $description = 'Create the scenario tables and give already-seeded charts the accounts scenarios book to (MySQL only; safe to re-run)';

    public function handle(): int
    {
        try {
            $schema = new InsightsDuckDbSchema();
            $schema->createPlanTables();
            $added = $schema->addMissingSystemAccounts();
            $this->info("Scenario tables ready; {$added} missing system account(s) added. Restart the MCP server so it sees the new tables.");
        } catch (Throwable $e) {
            $this->error('Preparing scenarios failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
