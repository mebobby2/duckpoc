<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mcp\Prompts\PlanStatusUpdatePrompt;
use App\Services\Insights\DuckDB\PlanComparisonSqlBuilder;
use App\Services\Insights\DuckDB\PlanHeadlineSqlBuilder;
use App\Services\Insights\DuckDB\PlanSignalsSqlBuilder;
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

        foreach ([PlanComparisonSqlBuilder::PLAN_HORIZON, PlanComparisonSqlBuilder::CURRENT_HORIZON] as $horizon) {
            $shared = (new ReportLinesSqlBuilder(self::PEERS, ReportBasis::Accrual, $horizon))->ctes('');
            foreach (['scan', 'milk_vj', 'stock_class_values', 'valuation_vj', 'report_lines'] as $name) {
                self::assertStringContainsString($shared[$name], $sql, "{$horizon}: the signals' {$name} is not the shared one");
            }
        }
        self::assertDoesNotMatchRegularExpression('/\$horizon\b/', $sql);
    }

    public function testOnlyThePlanSideReachesBackIntoTheFarmsHistory(): void
    {
        $sql = (new PlanSignalsSqlBuilder(self::FARM_ID, self::PEERS))->build();

        self::assertSame(1, substr_count($sql, 'CASE WHEN farm_id = '.self::FARM_ID.' THEN CAST($period_from AS DATE)'));
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
        $sql = (new PlanHeadlineSqlBuilder(self::FARM_ID))->build();

        self::assertStringContainsString((new PlanComparisonSqlBuilder(self::FARM_ID, ReportBasis::Accrual, withCategories: false))->build(), $sql);
        self::assertStringContainsString((new PlanComparisonSqlBuilder(self::FARM_ID, ReportBasis::Cash, withCategories: false))->build(), $sql);
    }

    public function testTheJudgementGuidanceIsTheSameForPromptsAndTools(): void
    {
        $prompt = (string) (new PlanStatusUpdatePrompt())->handle(new Request(['audience' => 'advisor']))->content();

        self::assertStringContainsString(PlanStatusUpdatePrompt::GUIDANCE, $prompt);
        self::assertStringContainsString('their advisor', $prompt);
    }
}
