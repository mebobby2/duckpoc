<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Insights\DuckDB\InsightsCashFlowSqlBuilder;
use App\Services\Insights\DuckDB\InsightsDuckDb;
use App\Services\Insights\DuckDB\InsightsMonthlyReportSqlBuilder;
use App\Services\Insights\DuckDB\InsightsProfitLossSqlBuilder;
use App\Services\Insights\DuckDB\PortfolioModellingSqlBuilder;
use App\Services\Insights\DuckDB\ReportLinesSqlBuilder;
use App\Services\Insights\ReportBasis;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Insights design constraint on the DuckDB engine: the single-farm report
 * and Portfolio Modelling run the same report SQL, on either basis. The
 * database-level proof is `insights:duckdb:portfolio --check-single`.
 *
 * Both consumers are built for the same one farm, so the farm filter inside
 * each shared CTE is identical text and the comparison can be exact.
 */
final class InsightsDuckDbSharedReportLogicTest extends TestCase
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
    public function testBothReportsEmbedTheSharedReportLinesUnchanged(ReportBasis $basis, string $singleFarm): void
    {
        $single = (new $singleFarm(self::FARM_ID))->build();
        $portfolio = (new PortfolioModellingSqlBuilder([self::FARM_ID], [], true, $basis))->build();

        foreach ((new ReportLinesSqlBuilder([self::FARM_ID], $basis))->ctes('') as $name => $body) {
            if ($name === 'report_window') {
                continue;
            }

            self::assertStringContainsString($body, $single, "the single-farm report's {$name} is not the shared one");
            self::assertStringContainsString($body, $portfolio, "the portfolio's {$name} is not the shared one");
        }
    }

    /**
     * @param class-string<InsightsMonthlyReportSqlBuilder> $singleFarm
     */
    #[DataProvider('bases')]
    public function testNeitherConsumerReadsTheLakeOrStockTablesItself(ReportBasis $basis, string $singleFarm): void
    {
        $shared = implode("\n", (new ReportLinesSqlBuilder([self::FARM_ID], $basis))->ctes(''));
        $single = (new $singleFarm(self::FARM_ID))->build();
        $portfolio = (new PortfolioModellingSqlBuilder([self::FARM_ID], [], true, $basis))->build();

        foreach ([InsightsDuckDb::lines(), InsightsDuckDb::table('stock_transactions'), InsightsDuckDb::table('milk_productions')] as $table) {
            $sharedReads = substr_count($shared, $table);
            self::assertSame($sharedReads, substr_count($single, $table), "the single-farm report reads {$table} itself");
            self::assertSame($sharedReads, substr_count($portfolio, $table), "the portfolio reads {$table} itself");
        }
    }

    public function testOnlyJournalLinesComeFromTheLake(): void
    {
        foreach (ReportBasis::cases() as $basis) {
            $sql = (new PortfolioModellingSqlBuilder([self::FARM_ID, 8], [], true, $basis))->build();
            $lakeReads = preg_match_all('/\b'.preg_quote(config('duckdb.attached_alias'), '/').'\.\w+\.(\w+)/', $sql, $m);

            self::assertGreaterThan(0, $lakeReads);
            self::assertSame(['transaction_lines'], array_values(array_unique($m[1])), "{$basis->value}: only journal lines live in the lake");
        }
    }

    public function testTheFarmFilterGivesTheLakeARangeToPruneFilesBy(): void
    {
        $filter = (new ReportLinesSqlBuilder([12, 3, 7], ReportBasis::Cash))->farmFilter('tl.farm_id');

        self::assertSame('tl.farm_id BETWEEN 3 AND 12 AND tl.farm_id IN (12, 3, 7)', $filter);
    }
}
