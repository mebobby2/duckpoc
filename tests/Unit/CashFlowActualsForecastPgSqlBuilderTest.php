<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AlloyDb\CashFlowActualsForecastPgSqlBuilder;
use App\Services\CashFlow\CashFlowActualsForecastOptions;
use App\Services\CashFlow\Definition\CashFlowActualsForecastReportDefinition;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CashFlowActualsForecastPgSqlBuilderTest extends TestCase
{
    /**
     * @return array<string, array{0: CashFlowActualsForecastOptions}>
     */
    public static function everyOptionGate(): array
    {
        return [
            'default' => [new CashFlowActualsForecastOptions()],
            'overdraft' => [new CashFlowActualsForecastOptions(withOverdraft: true)],
            'consolidated, EOY included, no total' => [new CashFlowActualsForecastOptions(groupByTracker: false, excludeEoyJournals: false, withTotal: false)],
        ];
    }

    #[DataProvider('everyOptionGate')]
    public function testEveryStatementTranslatesToPostgresPlaceholdersAndSyntax(CashFlowActualsForecastOptions $options): void
    {
        $builder = new CashFlowActualsForecastPgSqlBuilder(new CashFlowActualsForecastReportDefinition(), $options);

        foreach ([$builder->build(), $builder->buildVirtualJournalsSql(), $builder->buildVirtualJournalSummarySql(), $builder->buildSourceRowsSql(), $builder->buildSourceRowCountSql()] as $sql) {
            self::assertDoesNotMatchRegularExpression('/\$[a-z_]+/', $sql, 'a DuckDB placeholder survived');
            self::assertDoesNotMatchRegularExpression('/INTERVAL \d+ [A-Z]/', $sql);
            self::assertDoesNotMatchRegularExpression('/\bAS DOUBLE\)/', $sql);
            self::assertStringNotContainsString('lake.', $sql);
        }

        self::assertStringContainsString("INTERVAL '1 MONTH'", $builder->build());
        self::assertStringContainsString(':period_from', $builder->build());
    }

    public function testTheLakesDialectMapsOntoPostgres(): void
    {
        self::assertSame(
            "SELECT to_char(g.m, 'YYYY-MM'), EXTRACT(MONTH FROM m.d)::int, (array_agg(b.x ORDER BY b.i DESC))[1] WHERE d > :horizon - INTERVAL '2 MONTH'",
            CashFlowActualsForecastPgSqlBuilder::translate("SELECT strftime(g.m, '%Y-%m'), month(m.d), arg_max(b.x, b.i) WHERE d > \$horizon - INTERVAL 2 MONTH"),
        );
    }

    public function testAConstructWithNoRuleIsRefusedRatherThanSent(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('GROUP BY ALL');

        CashFlowActualsForecastPgSqlBuilder::translate('SELECT a, sum(b) FROM t GROUP BY ALL');
    }

    /**
     * Each of these, undone, sent the scan back to PostgreSQL's executor on
     * the 500M-line farm: tag as a grouping key or the horizon as a CASE
     * (32 s against 1 s), the opening balance joined above the scan
     * (1.03 s against 44 ms).
     */
    public function testTheScanIsShapedForTheColumnStore(): void
    {
        $sql = (new CashFlowActualsForecastPgSqlBuilder(new CashFlowActualsForecastReportDefinition(), new CashFlowActualsForecastOptions()))->build();

        self::assertSame(1, preg_match('/scan AS MATERIALIZED \((.*?)\n\),/s', $sql, $scan));
        self::assertSame(2, substr_count($scan[1], 'GROUP BY tl.date, tl.account_id, tl.tracker_id'));
        self::assertStringNotContainsString('tl.tracker_id, tl.tag', $scan[1]);
        self::assertStringNotContainsString('CASE WHEN tl.date', $scan[1]);
        self::assertStringContainsString("tl.tag = 'gst_payment'", $scan[1]);

        self::assertSame(1, preg_match('/opening AS \((.*?)\n\),/s', $sql, $opening));
        self::assertStringContainsString('tl.account_id = ANY (ARRAY(SELECT account_id', $opening[1]);
        self::assertStringNotContainsString('JOIN', $opening[1]);
    }
}
