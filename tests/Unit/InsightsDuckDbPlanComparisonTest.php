<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Insights\DuckDB\HerdValuationSqlBuilder;
use App\Services\Insights\DuckDB\InsightsCashFlowSqlBuilder;
use App\Services\Insights\DuckDB\InsightsDuckDb;
use App\Services\Insights\DuckDB\InsightsMonthlyReportSqlBuilder;
use App\Services\Insights\DuckDB\InsightsProfitLossSqlBuilder;
use App\Services\Insights\DuckDB\MilkProductionSqlBuilder;
use App\Services\Insights\DuckDB\PlanComparisonSqlBuilder;
use App\Services\Insights\DuckDB\ReportLinesSqlBuilder;
use App\Services\Insights\ReportBasis;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Plan against actuals is the single-farm report twice, from the shared
 * report SQL, at two horizons; the database-level proof that each side is
 * right is `insights:duckdb:portfolio --horizon=… --check --check-single`.
 */
final class InsightsDuckDbPlanComparisonTest extends TestCase
{
    private const int FARM_ID = 7;

    /**
     * @return array<string, array{0: ReportBasis, 1: class-string<InsightsMonthlyReportSqlBuilder>}>
     */
    public static function bases(): array
    {
        return [
            'cash: cash flow' => [ReportBasis::Cash, InsightsCashFlowSqlBuilder::class],
            'accrual: profit and loss' => [ReportBasis::Accrual, InsightsProfitLossSqlBuilder::class],
        ];
    }

    /**
     * @param class-string<InsightsMonthlyReportSqlBuilder> $singleFarm
     */
    #[DataProvider('bases')]
    public function testBothSidesAreTheSingleFarmReportUnchanged(ReportBasis $basis, string $singleFarm): void
    {
        $sql = (new PlanComparisonSqlBuilder(self::FARM_ID, $basis))->build();

        foreach ([PlanComparisonSqlBuilder::budget(), PlanComparisonSqlBuilder::current()] as $side) {
            $report = (new $singleFarm(self::FARM_ID, $side->horizonParameter, $side->edits, true))->build(fixedPoint: true);
            self::assertStringContainsString($report, $sql, "the {$side->key} side is not the single-farm report");
        }
        self::assertDoesNotMatchRegularExpression('/\$horizon\b/', $sql, 'neither side falls back to the default horizon');
    }

    #[DataProvider('bases')]
    public function testANamedHorizonReplacesEveryUseOfTheDefault(ReportBasis $basis, string $singleFarm): void
    {
        $shared = implode("\n", (new ReportLinesSqlBuilder([self::FARM_ID], $basis, 'plan_horizon'))->ctes(''));
        $report = (new $singleFarm(self::FARM_ID, 'plan_horizon'))->build();

        foreach (['shared report lines' => $shared, 'single-farm report' => $report] as $what => $sql) {
            self::assertStringContainsString('$plan_horizon', $sql, $what);
            self::assertDoesNotMatchRegularExpression('/\$horizon\b/', $sql, $what);
        }
    }

    public function testMilkProductionIsActualToTheHorizonAndForecastAfter(): void
    {
        foreach (ReportBasis::cases() as $basis) {
            $milk = (new ReportLinesSqlBuilder([self::FARM_ID], $basis))->ctes('')['milk_vj'];

            self::assertStringContainsString("AND type = ''actual'') OR (transaction_date > '''", $milk, $basis->value);
            self::assertStringContainsString("AND type = ''forecast''))", $milk, $basis->value);
        }
    }

    public function testThePeriodFilterIsInTheStatement(): void
    {
        $sql = (new PlanComparisonSqlBuilder(self::FARM_ID, ReportBasis::Accrual, '2026-08'))->build();

        self::assertStringContainsString("WHERE p.period = '2026-08'", $sql);
    }

    public function testAPeriodIsYearToDateTheSeasonOrAMonth(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PlanComparisonSqlBuilder(self::FARM_ID, ReportBasis::Cash, "2026-08' OR true --");
    }

    public function testMilkIncomeComesFromTheProfitAndLossAtBothHorizons(): void
    {
        $sql = (new MilkProductionSqlBuilder(self::FARM_ID))->build();

        foreach ([PlanComparisonSqlBuilder::PLAN_HORIZON, PlanComparisonSqlBuilder::CURRENT_HORIZON] as $horizon) {
            $shared = (new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Accrual, $horizon))->ctes(ReportLinesSqlBuilder::periodWindow());
            foreach (['scan', 'milk_vj', 'report_lines'] as $name) {
                self::assertStringContainsString($shared[$name], $sql, "{$horizon}: milk income's {$name} is not the shared one");
            }
            self::assertStringContainsString("(transaction_date <= CAST(\${$horizon} AS DATE) AND type = 'actual')", $sql, "{$horizon}: kgMS by the horizon rule");
        }
        self::assertDoesNotMatchRegularExpression('/\$horizon\b/', $sql);
    }

    public function testHerdValuationUsesTheProfitAndLossLivestockRuleWithoutTheLake(): void
    {
        $sql = (new HerdValuationSqlBuilder(self::FARM_ID))->build();

        foreach ([PlanComparisonSqlBuilder::PLAN_HORIZON, PlanComparisonSqlBuilder::CURRENT_HORIZON] as $horizon) {
            $shared = (new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Accrual, $horizon))->ctes(ReportLinesSqlBuilder::periodWindow());
            foreach (['stock_net', 'stock_heads', 'stock_class_values'] as $name) {
                self::assertStringContainsString($shared[$name], $sql, "{$horizon}: the valuation's {$name} is not the shared one");
            }
        }
        self::assertStringNotContainsString(InsightsDuckDb::lines(), $sql, 'a herd valuation reads no journal lines');
    }

    public function testTheProfitAndLossValuesLivestockFromTheClassValues(): void
    {
        $ctes = (new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Accrual))->ctes('');

        self::assertStringContainsString('FROM stock_class_values', $ctes['stock_values']);
    }
}
