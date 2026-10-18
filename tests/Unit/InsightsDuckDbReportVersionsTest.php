<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Insights\DuckDB\CashOutlookSqlBuilder;
use App\Services\Insights\DuckDB\ForecastEdits;
use App\Services\Insights\DuckDB\InsightsCashFlowSqlBuilder;
use App\Services\Insights\DuckDB\InsightsProfitLossSqlBuilder;
use App\Services\Insights\DuckDB\PlanComparisonSqlBuilder;
use App\Services\Insights\DuckDB\PositionSqlBuilder;
use App\Services\Insights\DuckDB\ReportComparisonSqlBuilder;
use App\Services\Insights\DuckDB\ReportLinesSqlBuilder;
use App\Services\Insights\DuckDB\ReportSide;
use App\Services\Insights\DuckDB\ScenarioChanges;
use App\Services\Insights\ReportBasis;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Every version of a report (budget, current, actual, last year, a past
 * season, a snapshot, a scenario) is the same single-farm report from the
 * shared report SQL, read at its own horizon and forecast edits and lined up
 * month by month; the position and the cash outlook are read from the same
 * lines. The database-level checks are the parity commands and the worked
 * examples in the head of product's test plan.
 */
final class InsightsDuckDbReportVersionsTest extends TestCase
{
    private const int FARM_ID = 7;

    private static function lastYear(): ReportSide
    {
        return new ReportSide('last_year', 'Last year', PlanComparisonSqlBuilder::CURRENT_HORIZON, ForecastEdits::adopted(), -12);
    }

    public function testLastYearIsTheSameReportReadTwelveMonthsBack(): void
    {
        $builder = new ReportComparisonSqlBuilder(self::FARM_ID, ReportBasis::Accrual, [PlanComparisonSqlBuilder::current(), self::lastYear()]);
        $sql = $builder->build();

        self::assertSame(12, $builder->monthsBack());
        self::assertStringContainsString("SELECT 'last_year' AS side, -12 AS month_offset", $sql);
        self::assertSame(2, substr_count($sql, (new InsightsProfitLossSqlBuilder(self::FARM_ID, 'current_horizon', ForecastEdits::adopted(), true))->build(fixedPoint: true)),
            'current and last year are the same report, over the same months');
        self::assertStringContainsString('interval_index - datediff(\'month\', CAST($period_from AS DATE), CAST($main_from AS DATE)) - month_offset', $sql);
    }

    public function testAnActualsOnlySideHasNothingForMonthsNotYetActual(): void
    {
        $actual = new ReportSide('actual', 'Actual', PlanComparisonSqlBuilder::CURRENT_HORIZON, ForecastEdits::adopted(), actualsOnly: true);
        $sql = (new ReportComparisonSqlBuilder(self::FARM_ID, ReportBasis::Accrual, [PlanComparisonSqlBuilder::current(), $actual]))->build();

        self::assertStringContainsString("CASE WHEN any_value(sm_actual.column_type) = 'actuals'", $sql);
    }

    public function testAComparisonIsTheDifferenceOfTheAmountsShown(): void
    {
        $sql = (new ReportComparisonSqlBuilder(self::FARM_ID, ReportBasis::Cash, [PlanComparisonSqlBuilder::current(), PlanComparisonSqlBuilder::budget()], perHa: true))->build();

        self::assertStringContainsString('round(round(p.current / 10000 / CAST($area_ha AS DOUBLE), 2) - round(p.budget / 10000 / CAST($area_ha AS DOUBLE), 2), 2) AS vs_budget', $sql);
        self::assertStringNotContainsString('vs_current', $sql, 'the first side is what the others are compared from');
    }

    /**
     * @return array<string, array{0: callable(): mixed}>
     */
    public static function badComparisons(): array
    {
        return [
            'the first side looks back' => [static fn () => new ReportComparisonSqlBuilder(self::FARM_ID, ReportBasis::Cash, [self::lastYear()])],
            'a side named twice' => [static fn () => new ReportComparisonSqlBuilder(self::FARM_ID, ReportBasis::Cash, [PlanComparisonSqlBuilder::current(), PlanComparisonSqlBuilder::current()])],
            'an enterprise on the cash flow' => [static fn () => new ReportComparisonSqlBuilder(self::FARM_ID, ReportBasis::Cash, [PlanComparisonSqlBuilder::current()], enterprise: 'Dairy')],
            'a side looking forward' => [static fn () => new ReportSide('next_year', 'Next year', 'current_horizon', ForecastEdits::adopted(), 12)],
            'a parameter that is not a name' => [static fn () => ForecastEdits::adopted('known_at; DROP')],
            'a balance sheet on cash' => [static fn () => new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Cash, balanceSheetAccounts: true)],
        ];
    }

    public function testComparisonsThatCannotBeBuiltAreRefused(): void
    {
        foreach (self::badComparisons() as $case => [$build]) {
            try {
                $build();
                self::fail("{$case} was built");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAnEnterpriseIsTheLinesTrackedToIt(): void
    {
        $cropping = (new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Accrual, enterprise: 'Cropping'))->ctes('')['report_lines'];
        $whole = (new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Accrual, enterprise: ReportLinesSqlBuilder::UNALLOCATED))->ctes('')['report_lines'];
        $quoted = (new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Accrual, enterprise: "O'Brien block"))->ctes('')['report_lines'];
        $ctes = (new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Accrual))->ctes('');

        self::assertStringContainsString("AND l.enterprise = 'Cropping'", $cropping);
        self::assertStringContainsString('AND l.enterprise IS NULL', $whole);
        self::assertStringContainsString("'O''Brien block'", $quoted);
        self::assertStringContainsString('mt.enterprise', $ctes['milk_vj'], 'milk keeps its supply\'s enterprise');
        self::assertStringContainsString('PARTITION BY farm_id, enterprise ORDER BY ms', $ctes['valuation_vj'], 'the herd is valued by its mobs\' enterprises');
    }

    public function testOverdraftInterestIsChargedOnTheCashPositionAfterTheHorizon(): void
    {
        $accrual = (new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Accrual, 'current_horizon', overdraftInterest: true))->ctes('');
        $cash = (new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Cash, 'current_horizon', overdraftInterest: true))->ctes('');
        $without = (new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Accrual, 'current_horizon'))->ctes('');
        $cashLines = (new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Cash, 'current_horizon'))->ctes('');

        self::assertSame($accrual['overdraft_vj'], $cash['overdraft_vj'], 'both bases charge the same interest');
        self::assertStringContainsString('WITH RECURSIVE', $accrual['overdraft_vj']);
        self::assertStringContainsString($cashLines['report_lines'], $accrual['overdraft_vj'], 'the profit and loss reads the cash flow\'s own lines');
        self::assertStringContainsString('c.month_end > CAST($current_horizon AS DATE) AND c.closing - a.cum < 0', $accrual['overdraft_vj']);
        self::assertStringContainsString('FROM overdraft_vj', $accrual['report_lines']);
        self::assertArrayNotHasKey('overdraft_vj', $without);
    }

    public function testTheBalanceSheetReadsEveryAccountButTheBankAndPayables(): void
    {
        $sheet = (new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Accrual, balanceSheetAccounts: true))->ctes('');
        $profit = (new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Accrual))->ctes('');

        self::assertStringContainsString("account_type <> 'BANK' AND system_account IS DISTINCT FROM 'CREDITORS'", $sheet['scan']);
        self::assertStringContainsString("grp IN ('income', 'operating_expenses', 'non_operating_income', 'non_operating_expenses')", $profit['scan']);
    }

    public function testThePositionReconcilesNetWorthToProfitAndEquity(): void
    {
        $sql = (new PositionSqlBuilder(self::FARM_ID, [PlanComparisonSqlBuilder::current()], ['current' => ['2026-06-30', '2026-09-30']]))->build();

        self::assertStringContainsString('AS unexplained', $sql);
        self::assertStringContainsString('profit_since', $sql);
        self::assertStringContainsString("('current', DATE '2026-09-30')", $sql);

        $this->expectException(InvalidArgumentException::class);
        new PositionSqlBuilder(self::FARM_ID, [self::lastYear()], ['last_year' => ['2026-05-31']]);
    }

    public function testTheCashOutlookIsTheCashFlowComparison(): void
    {
        $sides = [PlanComparisonSqlBuilder::current(), PlanComparisonSqlBuilder::budget()];
        $sql = (new CashOutlookSqlBuilder(self::FARM_ID, $sides))->build();

        self::assertStringContainsString((new ReportComparisonSqlBuilder(self::FARM_ID, ReportBasis::Cash, $sides, totalLabel: 'Total'))->build(), $sql);
        foreach (['current', 'budget'] as $key) {
            self::assertStringContainsString("AS {$key}_first_overdrawn", $sql);
            self::assertStringContainsString("AS {$key}_first_over_limit", $sql);
        }
        self::assertStringContainsString(
            (new InsightsCashFlowSqlBuilder(self::FARM_ID, 'current_horizon', ForecastEdits::adopted(), true))->build(fixedPoint: true),
            $sql,
            'the balances carry overdraft interest',
        );
    }

    public function testAChangeCanBeOneEnterprisesShare(): void
    {
        $change = ['type' => 'account', 'category' => 'Fertiliser', 'start_month' => '2026-11', 'percentage' => 20];

        $kept = ScenarioChanges::fromInput([$change + ['enterprise' => 'Cropping']], '2026-10', '2027-06', ['Fertiliser'], ['Beef', 'Cropping', 'Sheep']);
        self::assertSame('Cropping', $kept->changes[0]['enterprise']);
        self::assertStringContainsString('Cropping Fertiliser +20%', $kept->describe());

        $this->expectExceptionMessage('must be one of this farm\'s: Beef, Cropping, Sheep');
        ScenarioChanges::fromInput([$change + ['enterprise' => 'Dairy']], '2026-10', '2027-06', ['Fertiliser'], ['Beef', 'Cropping', 'Sheep']);
    }
}
