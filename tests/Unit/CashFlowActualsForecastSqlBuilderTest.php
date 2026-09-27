<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CashFlow\CashFlowActualsForecastOptions;
use App\Services\CashFlow\CashFlowActualsForecastSqlBuilder;
use App\Services\CashFlow\Definition\CashFlowActualsForecastReportDefinition;
use PHPUnit\Framework\TestCase;

/**
 * The statement's shape, without an engine: the option gates change the
 * text and nothing else does, and the two halves of the long result agree
 * on their columns.
 */
final class CashFlowActualsForecastSqlBuilderTest extends TestCase
{
    private function build(CashFlowActualsForecastOptions $options): string
    {
        return (new CashFlowActualsForecastSqlBuilder(
            new CashFlowActualsForecastReportDefinition(),
            'lake',
            'appdb',
            $options,
        ))->build();
    }

    public function testOnlyTheFiveNamedParametersReachTheText(): void
    {
        $sql = $this->build(new CashFlowActualsForecastOptions());

        preg_match_all('/\$([a-z_]+)/', $sql, $matches);
        $params = array_values(array_unique($matches[1]));
        sort($params);

        self::assertSame(['basis', 'farm_id', 'horizon', 'period_from', 'period_to'], $params);
        self::assertStringNotContainsString('cfaf', $sql, 'no farm reaches the text');
    }

    /**
     * Materialising the raw lines took the whole-farm request on the 500M
     * benchmark farm from 1.9 s to 72 s, so the scan has to stay summed and
     * its filter has to stay on bound parameters rather than a join.
     */
    public function testTheScanIsSummedAndFilteredOnBoundDates(): void
    {
        $sql = $this->build(new CashFlowActualsForecastOptions());

        self::assertSame(1, preg_match('/scan AS MATERIALIZED \((.*?)\n\),/s', $sql, $match));
        $scan = $match[1];

        self::assertStringContainsString('GROUP BY tl.date, tl.account_id, tl.tracker_id, tl.tag', $scan);
        self::assertStringContainsString('SUM(tl.amount)', $scan);
        self::assertStringContainsString('CAST($period_from AS DATE)', $scan);
        self::assertStringNotContainsString('period p', $scan);
        self::assertStringContainsString("tl.type = CASE WHEN tl.date <= CAST(\$horizon AS DATE) THEN 'actuals' ELSE 'forecast' END", $scan);
        self::assertStringNotContainsString("AND tl.type = 'actuals')", $scan);
    }

    public function testEveryFiguredStageIsACte(): void
    {
        $sql = $this->build(new CashFlowActualsForecastOptions());

        foreach ([
            'period AS', 'months AS', 'scan AS MATERIALIZED', 'opening AS',
            'milk_vj AS', 'gst_vj AS', 'od_accrual AS', 'overdraft_vj AS',
            'report_lines AS MATERIALIZED', 'tracker_agg AS', 'farm_agg AS', 'tracker_rollup AS',
            'calc_gross_profit AS', 'calc_operating_surplus AS', 'calc_total_surplus AS', 'calc_net_cash_movement AS',
            'with_balances AS', 'farm_rows AS', 'tracker_rows AS',
        ] as $cte) {
            self::assertStringContainsString($cte, $sql, "missing CTE {$cte}");
        }

        self::assertStringStartsWith('WITH RECURSIVE', $sql);
    }

    public function testExcludeEoyJournalsGatesTheTagPredicate(): void
    {
        $on = $this->build(new CashFlowActualsForecastOptions(excludeEoyJournals: true));
        $off = $this->build(new CashFlowActualsForecastOptions(excludeEoyJournals: false));

        self::assertStringContainsString("tl.tag NOT IN ('eoy_adjust_manual', 'eoy_adjust_system')", $on);
        self::assertStringNotContainsString('eoy_adjust', $off);
    }

    public function testWithTotalGatesTheTotalColumn(): void
    {
        $on = $this->build(new CashFlowActualsForecastOptions(withTotal: true));
        $off = $this->build(new CashFlowActualsForecastOptions(withTotal: false));

        self::assertSame(2, substr_count($on, "'Total'"), 'a Total column for the farm and for each tracker block');
        self::assertStringNotContainsString("'Total'", $off);
    }

    public function testGroupByTrackerGatesTheTrackerBlocks(): void
    {
        $on = $this->build(new CashFlowActualsForecastOptions(groupByTracker: true));
        $off = $this->build(new CashFlowActualsForecastOptions(groupByTracker: false));

        self::assertStringContainsString('SELECT * FROM tracker_rows', $on);
        self::assertStringNotContainsString('tracker_rows', $off);
        // The rollup into gross profit does not depend on the blocks being shown.
        self::assertStringContainsString('tracker_rollup AS', $off);
    }

    public function testWithOverdraftGatesTheLimitRowsButNotTheInterest(): void
    {
        $on = $this->build(new CashFlowActualsForecastOptions(withOverdraft: true));
        $off = $this->build(new CashFlowActualsForecastOptions(withOverdraft: false));

        self::assertStringContainsString('AS overdraft_headroom', $on);
        self::assertStringContainsString('LEFT JOIN od ON TRUE', $on);
        self::assertStringContainsString('CAST(NULL AS DOUBLE) AS overdraft_headroom', $off);
        self::assertStringContainsString('od_accrual AS', $off, 'interest is charged whenever an overdraft exists');
    }

    public function testFarmAndTrackerRowsProjectTheSameColumnCount(): void
    {
        $sql = $this->build(new CashFlowActualsForecastOptions());

        $count = static function (string $cte) use ($sql): int {
            $start = strpos($sql, "\n{$cte} AS (");
            $body = substr($sql, $start, strpos($sql, 'UNION ALL', $start) - $start);
            $select = substr($body, strpos($body, 'SELECT') + 6, strpos($body, 'FROM') - strpos($body, 'SELECT') - 6);

            return count(array_filter(array_map('trim', explode(",\n", $select))));
        };

        self::assertSame($count('farm_rows'), $count('tracker_rows'));
    }

    public function testDisplayRowsFollowTheStructureBuilderOrder(): void
    {
        $fields = array_column((new CashFlowActualsForecastReportDefinition())->displayRows(withOverdraft: true), 'field');

        self::assertSame([
            'trackers_gross_profit', 'other_income', 'direct_costs', 'gross_profit',
            'operating_expenses', 'operating_surplus',
            'non_operating_income', 'non_operating_expenses', 'total_surplus',
            'non_operating_movements', 'equity_movements', 'gst', 'net_cash_movement',
            'opening', 'closing', 'overdraft_limit', 'overdraft_headroom',
        ], $fields);
    }
}
