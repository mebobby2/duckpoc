<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Insights\InsightsCashFlowSqlBuilder;
use App\Services\Insights\InsightsMonthlyReportSqlBuilder;
use App\Services\Insights\InsightsProfitLossSqlBuilder;
use App\Services\Insights\PortfolioLine;
use App\Services\Insights\PortfolioModellingSqlBuilder;
use App\Services\Insights\ReportBasis;
use App\Services\Insights\ReportLinesSqlBuilder;
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
        $portfolio = (new PortfolioModellingSqlBuilder(true, $basis))->build();

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
        $portfolio = (new PortfolioModellingSqlBuilder(true, $basis))->build();

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
}
