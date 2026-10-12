<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Insights\DuckDB\ActivePlan;
use App\Services\Insights\DuckDB\InsightsDuckDb;
use App\Services\Insights\DuckDB\PlanComparisonQuery;
use App\Services\Insights\ReportBasis;
use Illuminate\Console\Command;
use Throwable;

class InsightsDuckDbPlanCommand extends Command
{
    protected $signature = 'insights:duckdb:plan
        {farm : Farm id}
        {--basis=accrual : accrual (profit and loss) or cash (cash flow)}
        {--period=YTD : YTD, Season, or a month as YYYY-MM}
        {--runs=1 : Timed runs}';

    protected $description = 'Compare a farm\'s actuals with its active plan, live from the lake and MySQL';

    public function handle(): int
    {
        try {
            $basis = ReportBasis::tryFrom((string) $this->option('basis'))
                ?? throw new \InvalidArgumentException('--basis is cash or accrual.');
            $plan = ActivePlan::forFarm((int) $this->argument('farm'));
            $query = new PlanComparisonQuery(InsightsDuckDb::connect());
            $period = (string) $this->option('period');

            $timings = [];
            for ($i = 0; $i < max(1, (int) $this->option('runs')); $i++) {
                $started = microtime(true);
                $rows = $query->run($plan, $basis, $period);
                $timings[] = (microtime(true) - $started) * 1000;
            }
            sort($timings);

            $this->info(sprintf(
                '%s, farm %d, %s: plan as at %s, actuals to %s — %s (median %.1f ms over %d run(s))',
                $plan->name, $plan->farmId, $basis->label(), $plan->planHorizon, $plan->actualsTo,
                $period, $timings[intdiv(count($timings), 2)], count($timings),
            ));

            $this->table(
                ['row', 'plan', 'current', 'variance', '%', 'effect'],
                array_map(static fn (array $r): array => [
                    $r['kind'] === 'category' ? '  '.$r['category'] : $r['label'],
                    number_format($r['plan'], 2),
                    number_format($r['current'], 2),
                    number_format($r['variance'], 2),
                    $r['variance_pct'] === null ? '' : number_format($r['variance_pct'], 1),
                    $r['effect'] === 'none' ? '' : $r['effect'],
                ], $rows),
            );
        } catch (Throwable $e) {
            $this->error('Plan comparison failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
