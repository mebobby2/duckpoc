<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Insights\InsightsCashFlowSqlBuilder;
use App\Services\Insights\PortfolioModellingSqlBuilder;
use App\Services\Insights\ReportLinesSqlBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The design constraint behind the Insights PoC: single-farm reporting and
 * portfolio reporting run the same report SQL. The database-level proof is
 * `insights:portfolio --check-cashflow`; this pins the structure, so a
 * consumer that grows its own copy of a report rule fails here first.
 */
final class InsightsSharedReportLogicTest extends TestCase
{
    public function testBothReportsEmbedTheSharedReportLinesUnchanged(): void
    {
        $cashFlow = (new InsightsCashFlowSqlBuilder())->build();
        $portfolio = (new PortfolioModellingSqlBuilder())->build();

        foreach ((new ReportLinesSqlBuilder())->ctes('') as $name => $body) {
            if ($name === 'report_window') {
                continue;
            }

            self::assertStringContainsString($body, $cashFlow, "the cash flow's {$name} is not the shared one");
            self::assertStringContainsString($body, $portfolio, "the portfolio's {$name} is not the shared one");
        }
    }

    public function testNeitherConsumerReadsTheJournalTableItself(): void
    {
        $shared = implode("\n", (new ReportLinesSqlBuilder())->ctes(''));
        $sharedReads = substr_count($shared, 'transaction_lines');

        self::assertSame($sharedReads, substr_count((new InsightsCashFlowSqlBuilder())->build(), 'transaction_lines'));
        self::assertSame($sharedReads, substr_count((new PortfolioModellingSqlBuilder())->build(), 'transaction_lines'));
    }

    public function testTheJournalFarmFilterStaysColumnarFriendly(): void
    {
        $shared = implode("\n", (new ReportLinesSqlBuilder())->ctes(''));

        self::assertStringNotContainsString('tl.farm_id = ANY (CAST(', $shared, 'a literal farm array drops AlloyDB off the column store');
        self::assertStringContainsString(ReportLinesSqlBuilder::journalFarmFilter(), $shared);
    }
}
