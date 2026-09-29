<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Insights\AlloyDB\InsightsCashFlowSqlBuilder;
use App\Services\Insights\AlloyDB\InsightsMonthlyReportSqlBuilder;
use App\Services\Insights\AlloyDB\InsightsProfitLossSqlBuilder;
use App\Services\Insights\PortfolioBreakdown;
use App\Services\Insights\PortfolioBreakdownSort;
use App\Services\Insights\PortfolioLine;
use App\Services\Insights\AlloyDB\PortfolioModellingSqlBuilder;
use App\Services\Insights\ReportBasis;
use App\Services\Insights\AlloyDB\ReportLinesSqlBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The design constraint behind the Insights PoC: single-farm reporting and
 * portfolio reporting run the same report SQL, on either basis. The
 * database-level proof is `insights:portfolio --check-single`; this pins the
 * structure, so a consumer that grows its own copy of a report rule fails
 * here first.
 */
final class InsightsSharedReportLogicTest extends TestCase
{
    /**
     * @return array<string, array{0: ReportBasis, 1: InsightsMonthlyReportSqlBuilder}>
     */
    public static function bases(): array
    {
        return [
            'cash: cash flow' => [ReportBasis::Cash, new InsightsCashFlowSqlBuilder()],
            'accrual: profit and loss' => [ReportBasis::Accrual, new InsightsProfitLossSqlBuilder()],
        ];
    }

    #[DataProvider('bases')]
    public function testBothReportsEmbedTheSharedReportLinesUnchanged(ReportBasis $basis, InsightsMonthlyReportSqlBuilder $singleFarm): void
    {
        $single = $singleFarm->build();
        $portfolio = (new PortfolioModellingSqlBuilder(PortfolioBreakdown::everyLine(), $basis))->build();

        foreach ((new ReportLinesSqlBuilder($basis))->ctes('') as $name => $body) {
            if ($name === 'report_window') {
                continue;
            }

            self::assertStringContainsString($body, $single, "the single-farm report's {$name} is not the shared one");
            self::assertStringContainsString($body, $portfolio, "the portfolio's {$name} is not the shared one");
        }
    }

    #[DataProvider('bases')]
    public function testNeitherConsumerReadsTheJournalOrStockTablesItself(ReportBasis $basis, InsightsMonthlyReportSqlBuilder $singleFarm): void
    {
        $shared = implode("\n", (new ReportLinesSqlBuilder($basis))->ctes(''));
        $portfolio = (new PortfolioModellingSqlBuilder(PortfolioBreakdown::everyLine(), $basis))->build();

        foreach (['transaction_lines', 'stock_transactions', 'milk_productions'] as $table) {
            $sharedReads = substr_count($shared, $table);
            self::assertSame($sharedReads, substr_count($singleFarm->build(), $table), "the single-farm report reads {$table} itself");
            self::assertSame($sharedReads, substr_count($portfolio, $table), "the portfolio reads {$table} itself");
        }
    }

    #[DataProvider('bases')]
    public function testTheJournalFarmFilterStaysColumnarFriendly(ReportBasis $basis, InsightsMonthlyReportSqlBuilder $singleFarm): void
    {
        $shared = implode("\n", (new ReportLinesSqlBuilder($basis))->ctes(''));

        self::assertStringNotContainsString('tl.farm_id = ANY (CAST(', $shared, 'a literal farm array drops AlloyDB off the column store');
        self::assertStringContainsString(ReportLinesSqlBuilder::journalFarmFilter(), $shared);
        self::assertStringNotContainsString("tl.farm_id = ANY (CAST(", $singleFarm->build());
    }

    public function testEachBasisEndsInItsOwnBottomLine(): void
    {
        self::assertContains(PortfolioLine::ClosingCash, PortfolioLine::forBasis(ReportBasis::Cash));
        self::assertNotContains(PortfolioLine::NetProfit, PortfolioLine::forBasis(ReportBasis::Cash));
        self::assertContains(PortfolioLine::NetProfit, PortfolioLine::forBasis(ReportBasis::Accrual));
        self::assertNotContains(PortfolioLine::ClosingCash, PortfolioLine::forBasis(ReportBasis::Accrual));
    }

    public function testThePageGetsItsNumbersFromTheStatement(): void
    {
        $breakdown = PortfolioBreakdown::of(PortfolioLine::Fertiliser, 2028, PortfolioBreakdownSort::Original);
        $sql = (new PortfolioModellingSqlBuilder($breakdown))->build();

        foreach (['original_per_farm', 'modelled_per_farm', 'variance_per_farm', 'farm_name', 'region', 'farm_type'] as $column) {
            self::assertStringContainsString(" AS {$column}", $sql, "the statement returns {$column}");
        }
        self::assertStringContainsString('fl.line_order, fl.is_cost', $sql, 'the per-farm rows carry is_cost');
        self::assertStringContainsString("WHERE line = 'fertiliser' AND season = 2028", $sql);
        self::assertStringContainsString('ORDER BY farm_id IS NOT NULL, season, line_order, original DESC, farm_id ASC', $sql);
        self::assertStringContainsString("('fertiliser', 3, true,", $sql, 'fertiliser is a cost');
        self::assertStringContainsString("('milk_income', 0, false,", $sql, 'milk income is not');
    }
}
