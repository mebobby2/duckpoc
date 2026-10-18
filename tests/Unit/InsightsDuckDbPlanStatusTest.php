<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mcp\Prompts\PlanStatusUpdatePrompt;
use App\Services\Insights\DuckDB\PlanComparisonSqlBuilder;
use App\Services\Insights\DuckDB\PlanHeadlineSqlBuilder;
use App\Services\Insights\DuckDB\PlanSignalsSqlBuilder;
use App\Services\Insights\DuckDB\ReportComparisonSqlBuilder;
use App\Services\Insights\DuckDB\ReportLinesSqlBuilder;
use App\Services\Insights\ReportBasis;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * Plan status in three layers: facts and signals in SQL from the shared
 * report lines, judgement left to the reader. The database-level proof that
 * the signals find what matters is `insights:duckdb:plan-status --practice=5`
 * against the planted drifts.
 */
final class InsightsDuckDbPlanStatusTest extends TestCase
{
    private const int FARM_ID = 7;

    /** @var list<int> */
    private const array PEERS = [5, 7, 9];

    public function testSignalsReadTheSharedProfitAndLossLinesAtBothHorizons(): void
    {
        $sql = (new PlanSignalsSqlBuilder(self::FARM_ID, self::PEERS))->build();

        foreach ([PlanComparisonSqlBuilder::budget(), PlanComparisonSqlBuilder::current()] as $side) {
            $horizon = $side->horizonParameter;
            $shared = (new ReportLinesSqlBuilder(self::PEERS, ReportBasis::Accrual, $horizon, $side->edits, overdraftInterest: true))->ctes('');
            foreach (['scan', 'milk_vj', 'stock_class_values', 'valuation_vj', 'report_lines'] as $name) {
                self::assertStringContainsString($shared[$name], $sql, "{$horizon}: the signals' {$name} is not the shared one");
            }
        }
        self::assertDoesNotMatchRegularExpression('/\$horizon\b/', $sql);
    }

    public function testOnlyThePlanSideReachesBackIntoTheFarmsHistory(): void
    {
        $sql = (new PlanSignalsSqlBuilder(self::FARM_ID, self::PEERS))->build();

        [$plan, $current] = explode('current_side AS (', $sql, 2);
        $history = 'CASE WHEN farm_id = '.self::FARM_ID.' THEN CAST($period_from AS DATE)';

        self::assertStringContainsString($history, $plan);
        self::assertStringNotContainsString($history, $current);
    }

    public function testSignalsRankWithoutCuttingAnythingOff(): void
    {
        $sql = (new PlanSignalsSqlBuilder(self::FARM_ID, self::PEERS))->build();

        self::assertStringContainsString('ORDER BY attention DESC', $sql);
        self::assertStringNotContainsString('LIMIT', $sql);
        self::assertStringContainsString('LIMIT 8', (new PlanSignalsSqlBuilder(self::FARM_ID, self::PEERS, 8))->build());
    }

    public function testThePeerGroupIncludesTheFarm(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PlanSignalsSqlBuilder(self::FARM_ID, [5, 9]);
    }

    public function testTheHeadlineIsBothPlanComparisons(): void
    {
        $sides = [PlanComparisonSqlBuilder::current(), PlanComparisonSqlBuilder::budget('plan')];
        $sql = (new PlanHeadlineSqlBuilder(self::FARM_ID, $sides))->build();

        foreach (ReportBasis::cases() as $basis) {
            self::assertStringContainsString((new ReportComparisonSqlBuilder(self::FARM_ID, $basis, $sides, withCategories: false))->build(), $sql, $basis->value);
        }
        self::assertStringContainsString('arg_min(period, plan) AS plan_month', $sql);
    }

    public function testTheJudgementGuidanceIsTheSameForPromptsAndTools(): void
    {
        $prompt = (string) (new PlanStatusUpdatePrompt())->handle(new Request(['audience' => 'advisor']))->content();

        self::assertStringContainsString(PlanStatusUpdatePrompt::GUIDANCE, $prompt);
        self::assertStringContainsString('their advisor', $prompt);
    }
}
