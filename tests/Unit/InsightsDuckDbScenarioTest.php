<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Insights\DuckDB\ForecastEdits;
use App\Services\Insights\DuckDB\ForecastEditsSqlBuilder;
use App\Services\Insights\DuckDB\InsightsCashFlowSqlBuilder;
use App\Services\Insights\DuckDB\InsightsProfitLossSqlBuilder;
use App\Services\Insights\DuckDB\PlanComparisonSqlBuilder;
use App\Services\Insights\DuckDB\PlanHeadlineSqlBuilder;
use App\Services\Insights\DuckDB\ReportComparisonSqlBuilder;
use App\Services\Insights\DuckDB\ReportLinesSqlBuilder;
use App\Services\Insights\DuckDB\ReportSide;
use App\Services\Insights\DuckDB\ScenarioChanges;
use App\Services\Insights\ReportBasis;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * A scenario is forecast lines the shared report SQL reads like any other:
 * the budget sees those adopted before it was agreed (none), current the
 * adopted ones not withdrawn, and a scenario side the proposed ones too. The database-level check of the booking (GST,
 * timing, loan, depreciation) is the worked tractor example in the QA steps.
 */
final class InsightsDuckDbScenarioTest extends TestCase
{
    private const int FARM_ID = 7;

    private const array CATEGORIES = ['Capital Purchases', 'Fertiliser', 'Repairs', 'Wages'];

    public function testEachSideIsTheSingleFarmReportWithItsOwnForecastEdits(): void
    {
        $sides = [
            new ReportSide('scenario', 'Scenario', PlanComparisonSqlBuilder::CURRENT_HORIZON, ForecastEdits::proposed('scenario')),
            PlanComparisonSqlBuilder::current(),
            PlanComparisonSqlBuilder::budget(),
        ];
        $sql = (new ReportComparisonSqlBuilder(self::FARM_ID, ReportBasis::Accrual, $sides))->build();

        foreach ($sides as $side) {
            $report = (new InsightsProfitLossSqlBuilder(self::FARM_ID, $side->horizonParameter, $side->edits, true))->build(fixedPoint: true);
            self::assertStringContainsString($report, $sql, $side->key);
        }
    }

    public function testTheBudgetReadsOnlyTheEditsAdoptedBeforeItWasAgreed(): void
    {
        $budget = PlanComparisonSqlBuilder::budget();
        $adopted = (new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Accrual, $budget->horizonParameter, $budget->edits))->ctes('')['adopted_edits'];
        $now = (new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Accrual, 'current_horizon', ForecastEdits::adopted()))->ctes('')['adopted_edits'];

        self::assertStringContainsString('s.adopted_at <= CAST($budget_known_at AS TIMESTAMP)', $adopted);
        self::assertStringContainsString('s.withdrawn_at > CAST($budget_known_at AS TIMESTAMP)', $adopted, 'withdrawn after it was agreed still counts');
        self::assertStringContainsString('s.withdrawn_at IS NULL', $now, 'as things stand, a withdrawn scenario is out');
        self::assertArrayNotHasKey('adopted_edits', (new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Accrual))->ctes(''));
    }

    public function testEditsJoinTheLedgerAsForecastAfterTheHorizonOnBothBases(): void
    {
        foreach (ReportBasis::cases() as $basis) {
            $ctes = (new ReportLinesSqlBuilder([self::FARM_ID], $basis, 'current_horizon', ForecastEdits::adopted()))->ctes('');

            self::assertSame(['farm', 'report_window', 'accounts', 'adopted_edits', 'forecast_edits'], array_slice(array_keys($ctes), 0, 5), $basis->value);
            self::assertStringContainsString("FROM forecast_edits fe\n", $ctes['scan'], $basis->value);
            self::assertStringContainsString("fe.basis = '{$basis->value}'", $ctes['scan'], $basis->value);
            self::assertStringContainsString('AND fe.date > CAST($current_horizon AS DATE)', $ctes['scan'], $basis->value);
            self::assertStringNotContainsString('edit_legs', $ctes['forecast_edits'], 'only a proposal generates lines');
        }
    }

    public function testAProposedScenarioIsOneFarms(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReportLinesSqlBuilder([self::FARM_ID, 8], ReportBasis::Cash, 'current_horizon', ForecastEdits::proposed('scenario'));
    }

    public function testProposedChangesAreBookedTheWayTheLedgerBooksAForecast(): void
    {
        $ctes = (new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Cash, 'current_horizon', ForecastEdits::proposed('scenario')))->ctes('');

        self::assertStringContainsString('CAST(CAST($scenario AS JSON) AS JSON[])', $ctes['edit_changes']);
        self::assertStringContainsString("WHEN 'payables' THEN [", $ctes['edit_legs'], 'purchases settle through payables, like the seeded forecast');
        self::assertStringContainsString("WHEN 'direct' THEN [", $ctes['edit_legs'], 'loan flows go straight through the bank');
        self::assertStringContainsString("'depreciation'", $ctes['edit_events']);
        self::assertStringContainsString("'journal'", $ctes['edit_events'], 'depreciation has no cash');
        self::assertStringContainsString('round(e.net * 0.15)', $ctes['edit_events'], 'GST at the ledger\'s rate');
        self::assertStringContainsString("UNION ALL\n", $ctes['forecast_edits']);
    }

    public function testTheCashFlowAndProfitAndLossShareTheEdits(): void
    {
        $cash = (new InsightsCashFlowSqlBuilder(self::FARM_ID, 'current_horizon', ForecastEdits::proposed('scenario')))->build();
        $profit = (new InsightsProfitLossSqlBuilder(self::FARM_ID, 'current_horizon', ForecastEdits::proposed('scenario')))->build();
        $edits = (new ReportLinesSqlBuilder([self::FARM_ID], ReportBasis::Accrual, 'current_horizon', ForecastEdits::proposed('scenario')))->editCtes('');

        foreach (['edit_events', 'edit_legs', 'forecast_edits'] as $name) {
            self::assertStringContainsString($edits[$name], $cash, $name);
            self::assertStringContainsString($edits[$name], $profit, $name);
        }
    }

    public function testTheHeadlineCarriesEachSidesColumnsAndLowPoint(): void
    {
        $plain = (new PlanHeadlineSqlBuilder(self::FARM_ID, [PlanComparisonSqlBuilder::current(), PlanComparisonSqlBuilder::budget()]))->build();
        $scenario = (new PlanHeadlineSqlBuilder(self::FARM_ID, [
            new ReportSide('scenario', 'Scenario', PlanComparisonSqlBuilder::CURRENT_HORIZON, ForecastEdits::proposed('scenario')),
            PlanComparisonSqlBuilder::current(),
        ]))->build();

        self::assertStringNotContainsString('scenario_month', $plain);
        self::assertStringContainsString('arg_min(period, budget) AS budget_month', $plain);
        self::assertStringContainsString('arg_min(period, scenario) AS scenario_month', $scenario);
        self::assertStringContainsString('vs_current', $scenario, 'the scenario is compared from');
    }

    public function testChangesAreNormalisedWithTheirDefaults(): void
    {
        $changes = ScenarioChanges::fromInput([
            ['type' => 'account', 'category' => 'Repairs', 'start_month' => '2026-11', 'values' => [-800]],
            ['type' => 'asset_purchase', 'month' => '2026-11', 'cost' => 185000],
        ], '2026-10', '2027-05', self::CATEGORIES)->changes;

        self::assertSame(['type' => 'account', 'category' => 'Repairs', 'enterprise' => null, 'start_month' => '2026-11', 'end_month' => '2026-11',
            'values' => [-800.0], 'percentage' => null, 'application_mode' => 'add'], $changes[0]);
        self::assertSame(['type' => 'asset_purchase', 'description' => 'Asset purchase', 'month' => '2026-11', 'cost' => 185000.0,
            'trade_in' => 0.0, 'depreciation_rate_pct' => ScenarioChanges::DEFAULT_DEPRECIATION_RATE_PCT, 'loan' => null], $changes[1]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidChanges(): array
    {
        return [
            'an actual month' => [['type' => 'account', 'category' => 'Repairs', 'start_month' => '2026-09', 'values' => [1]], 'already actuals'],
            'after the season' => [['type' => 'asset_purchase', 'month' => '2027-06', 'cost' => 1], "this plan's season"],
            'milk income' => [['type' => 'account', 'category' => 'Milk Income', 'start_month' => '2026-11', 'values' => [1]], 'category must be one of'],
            'values that fit neither shape' => [['type' => 'account', 'category' => 'Wages', 'start_month' => '2026-11', 'end_month' => '2027-01', 'values' => [1, 2]], 'one per month (3)'],
            'values and a percentage' => [['type' => 'account', 'category' => 'Wages', 'start_month' => '2026-11', 'values' => [1], 'percentage' => 5], 'not both'],
            'a replacing percentage' => [['type' => 'account', 'category' => 'Wages', 'start_month' => '2026-11', 'percentage' => 5, 'application_mode' => 'replace'], 'cannot replace'],
            'a loan beyond the price' => [['type' => 'asset_purchase', 'month' => '2026-11', 'cost' => 1000, 'loan' => ['amount' => 5000, 'annual_rate_pct' => 5, 'years' => 3]], 'loan.amount'],
            'an unknown kind' => [['type' => 'milk_price', 'month' => '2026-11'], 'account or asset_purchase'],
        ];
    }

    public function testInvalidChangesAreRejectedWithAReasonTheCallerCanAct(): void
    {
        foreach (self::invalidChanges() as $case => [$change, $reason]) {
            try {
                ScenarioChanges::fromInput([$change], '2026-10', '2027-05', self::CATEGORIES);
                self::fail("{$case} was accepted");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($reason, $e->getMessage(), $case);
            }
        }
    }

    public function testTheWorkingsReadTheProposedEvents(): void
    {
        self::assertStringContainsString('FROM edit_events e', ForecastEditsSqlBuilder::workingsSelect());
    }
}
