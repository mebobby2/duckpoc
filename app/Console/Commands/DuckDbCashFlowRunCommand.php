<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashFlow\CashFlowOracleSeeder;
use App\Services\CashFlow\CashFlowQuery;
use Illuminate\Console\Command;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Phase 1, Steps 3-4: run the Cash Flow report as DuckDB SQL and diff it
 * against the parity oracle captured from Figured's real report engine.
 *
 * The oracle values below are not hand-derived — they were captured by
 * running the same scenario through Figured's V2 CashFlowStructureBuilder +
 * ReportRunnerService, and independently through the widget path that feeds
 * Reporting Studio's UI. See the README's oracle section.
 */
class DuckDbCashFlowRunCommand extends Command
{
    protected $signature = 'duckdb:cashflow:run {--no-check : print the report without the parity diff}';

    protected $description = 'Run the Cash Flow report in DuckDB and diff it against the Figured oracle';

    /** @var array<string, list<float>> Jan..Dec, in dollars */
    private const array ORACLE = [
        'income' => [1000, 0, 0, 0, 0, 0, 0, 500, 0, 0, 0, 0],
        'gross_profit' => [1000, 0, 0, 0, 0, 0, 0, 500, 0, 0, 0, 0],
        'operating_expenses' => [200, 0, 0, 0, 0, 0, 0, 100, 0, 0, 0, 0],
        'operating_surplus' => [800, 0, 0, 0, 0, 0, 0, 400, 0, 0, 0, 0],
        'net_cash_movement' => [800, 0, 0, 0, 0, 0, 0, 400, 0, 0, 0, 0],
        'opening' => [0, 800, 800, 800, 800, 800, 800, 800, 1200, 1200, 1200, 1200],
        'closing' => [800, 800, 800, 800, 800, 800, 800, 1200, 1200, 1200, 1200, 1200],
    ];

    public function handle(DuckDB $db): int
    {
        $alias = config('duckdb.attached_alias');
        $query = new CashFlowQuery($db, $alias);

        $this->info('Running Cash Flow as DuckDB SQL over DuckLake...');

        try {
            $rows = $query->run(
                farmId: CashFlowOracleSeeder::FARM_ID,
                farmType: CashFlowOracleSeeder::FARM_TYPE,
                region: CashFlowOracleSeeder::REGION,
                periodFrom: '2024-01-01',
                periodTo: '2024-12-31',
                horizon: '2024-02-28',
            );
        } catch (Throwable $e) {
            $this->error('Query failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (empty($rows)) {
            $this->error('Query returned no rows — has `duckdb:cashflow:seed` been run?');

            return self::FAILURE;
        }

        $this->printReport($rows);

        if ($this->option('no-check')) {
            return self::SUCCESS;
        }

        return $this->checkParity($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function printReport(array $rows): void
    {
        $this->line('');

        $header = sprintf('%-20s', 'Row');
        foreach ($rows as $row) {
            $header .= sprintf('%9s', substr((string) $row['month'], 5));
        }
        $this->line($header);
        $this->line(str_repeat('-', strlen($header)));

        foreach (array_keys(self::ORACLE) as $field) {
            $line = sprintf('%-20s', $field);
            foreach ($rows as $row) {
                $line .= sprintf('%9s', $this->fmt($row[$field]));
            }
            $this->line($line);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function checkParity(array $rows): int
    {
        $this->line('');
        $this->info('Diffing against the Figured oracle...');

        $failures = [];

        foreach (self::ORACLE as $field => $expectedByMonth) {
            if (count($rows) !== count($expectedByMonth)) {
                $failures[] = sprintf(
                    '%s: expected %d months, query returned %d',
                    $field,
                    count($expectedByMonth),
                    count($rows),
                );

                continue;
            }

            foreach ($expectedByMonth as $i => $expected) {
                $actual = (float) $rows[$i][$field];

                // Cents tolerance — the query divides fixed-point integers, so
                // exact float equality is the wrong assertion.
                if (abs($actual - $expected) > 0.005) {
                    $failures[] = sprintf(
                        '%s[%s]: expected %s, got %s',
                        $field,
                        $rows[$i]['month'],
                        $this->fmt($expected),
                        $this->fmt($actual),
                    );
                }
            }
        }

        $this->line('');

        if (!empty($failures)) {
            $this->error('✘ PARITY FAILED — '.count($failures).' mismatch(es):');
            foreach ($failures as $failure) {
                $this->line('    '.$failure);
            }

            return self::FAILURE;
        }

        $cells = count(self::ORACLE) * count($rows);
        $this->info("✔ PARITY PASSED — all {$cells} cells match Figured's report exactly.");

        return self::SUCCESS;
    }

    private function fmt(mixed $value): string
    {
        return number_format((float) $value, 0);
    }
}
