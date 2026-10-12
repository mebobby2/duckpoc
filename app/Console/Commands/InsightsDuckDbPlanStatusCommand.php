<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Insights\DuckDB\FarmPlan;
use App\Services\Insights\DuckDB\InsightsDuckDb;
use Illuminate\Console\Command;
use Throwable;

/**
 * Plan status from the command line: one farm's headline and signals, or a
 * whole advanced practice scored against the seeder's answer key
 * (`plan_drifts`), to check the signals find what was planted without any
 * rule that knows what to look for.
 */
class InsightsDuckDbPlanStatusCommand extends Command
{
    /**
     * Where each planted drift should show: the categories it moved. The
     * timing farm's fertiliser should also carry timing evidence.
     */
    private const array EXPECTED = [
        'fertiliser_price' => ['Fertiliser'],
        'drought' => ['Milk Income', 'Supplements'],
        'milk_price' => ['Milk Income'],
        'repairs' => ['Repairs'],
        'timing' => ['Fertiliser'],
        'on_plan' => [],
    ];

    protected $signature = 'insights:duckdb:plan-status
        {farm? : One farm\'s plan status}
        {--practice= : Score every farm in an advanced practice against its answer key instead}
        {--limit=10 : Signals to show for one farm}';

    protected $description = 'Plan status (headline and signals) for a farm, or a practice scored against the planted drifts';

    public function handle(): int
    {
        try {
            if ($this->option('practice') !== null) {
                return $this->score((int) $this->option('practice'));
            }

            return $this->show((int) $this->argument('farm'));
        } catch (Throwable $e) {
            $this->error('Plan status failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function show(int $farmId): int
    {
        $plan = FarmPlan::forFarm($farmId);
        $started = microtime(true);
        $status = $plan->status((int) $this->option('limit'));
        $this->info(sprintf('%s, farm %d: plan as at %s, actuals to %s (%.0f ms; peers: %d %s farms)',
            $plan->plan->name, $farmId, $plan->plan->planHorizon, $plan->plan->actualsTo, (microtime(true) - $started) * 1000,
            $status['peer_group']['farms'], $status['peer_group']['farm_type']));

        $this->table(['measure', 'period', 'plan', 'current', 'variance', '%', 'effect'], array_map(static fn (array $r): array => [
            $r['measure'].($r['plan_month'] === null ? '' : " (plan {$r['plan_month']}, now {$r['current_month']})"),
            $r['period'], number_format($r['plan'], 2), number_format($r['current'], 2), number_format($r['variance'], 2),
            $r['variance_pct'] ?? '', $r['effect'],
        ], $status['headline']));

        $this->table(
            ['signal', 'since plan', '%', 'z', 'normal spread', 'peer median %', 'pctile', '% of income', 'latest mo', 'vs next mo', 'trend season', 'attention'],
            array_map(static fn (array $s): array => [
                ($s['level'] === 'section' ? strtoupper($s['row']) : $s['row']), number_format($s['variance_since_plan'], 0),
                $s['variance_since_plan_pct'] ?? '', $s['unusual_z'] ?? '', number_format((float) $s['normal_spread'], 0),
                $s['peer_median_pct'] ?? '', $s['peer_percentile'] ?? '', $s['share_of_season_income_pct'] ?? '',
                $s['latest_month_share'] ?? '', $s['variance_vs_next_month'] ?? '',
                number_format((float) $s['season_if_trend_continues'], 0), number_format((float) $s['attention'], 0),
            ], $status['signals']),
        );

        foreach ($status['notes'] as $n) {
            $this->line("  note ({$n['author']}, {$n['written']}): {$n['note']}");
        }

        return self::SUCCESS;
    }

    private function score(int $practiceId): int
    {
        $drifts = InsightsDuckDb::mysql()->select(
            'SELECT d.farm_id, d.kind FROM plan_drifts d JOIN farm_practice fp ON fp.farm_id = d.farm_id AND fp.practice_id = ? ORDER BY d.kind, d.farm_id',
            [$practiceId],
        );

        $rows = [];
        $found = 0;
        $planted = 0;
        foreach ($drifts as $d) {
            $signals = array_values(array_filter(
                FarmPlan::forFarm((int) $d->farm_id)->status(100)['signals'],
                static fn (array $s): bool => $s['level'] === 'category',
            ));
            $expected = self::EXPECTED[$d->kind];
            $ranks = [];
            foreach ($expected as $category) {
                $rank = array_search($category, array_column($signals, 'row'), true);
                $ranks[] = $rank === false ? '-' : $rank + 1;
            }
            $top = $signals[0];
            if ($expected !== []) {
                $planted++;
                $found += in_array(1, $ranks, true) ? 1 : 0;
            }
            $rows[] = [
                $d->farm_id, $d->kind, implode(', ', $expected) ?: '(none)', implode(', ', $ranks) ?: '',
                "{$top['row']} (z {$top['unusual_z']}, {$top['variance_since_plan_pct']}%)",
                number_format((float) $top['attention'], 0), $top['share_of_season_income_pct'],
                $top['latest_month_share'] ?? '', $top['variance_vs_next_month'] ?? '',
            ];
        }

        $this->table(['farm', 'planted', 'should surface', 'rank', 'top category signal', 'attention', '% of income', 'latest mo', 'vs next mo'], $rows);
        $this->info(sprintf('%d of %d planted drifts are the top category signal on their farm.', $found, $planted));

        return self::SUCCESS;
    }
}
