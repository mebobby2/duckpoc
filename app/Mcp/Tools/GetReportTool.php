<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Insights\DuckDB\FarmPlan;
use App\Services\Insights\ReportBasis;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-report')]
#[Description(<<<'TEXT'
    Figured's profit and loss or cash flow for this farm, in one or more versions side by side, over a season or a
    run of months. Each row (income and cost categories, section totals, operating surplus, net profit or net cash
    movement, and on the cash flow the opening and closing bank balance) has its amount in each version, and each
    version after the first compared from the first: change (first minus it), pct and effect (favourable, adverse
    or none). Amounts are NZ dollars excluding GST, income and costs both positive.

    versions (the first is the one the others are compared from):
    - current: actuals to date, then the forecast as it stands now;
    - budget: the forecast as agreed for the season (the plan);
    - actual: actuals only; months not yet actual are null;
    - last_year: the same months a year earlier;
    - season:YYYY: the same months of season YYYY (by the year it ends), e.g. season:2025; actuals for a past one;
    - snapshot:N: a saved snapshot, the forecast as it stood then (get-farm-profile lists them);
    - scenario:N: a saved scenario applied to the current forecast (list-scenarios lists them).

    Window: a season (season, default the plan's; with season:YYYY first, that season), or a run of months
    (from_month and months, e.g. the next 12 months from the month after actuals_to). Periods: each month, "YTD"
    (actual months so far) and the total ("Season", or "Total" for a run of months). Ask for one with period.

    Enterprises (profit and loss only): an enterprise's lines are its own income and direct costs, so its operating
    surplus is its gross margin; whole-farm overheads (wages, rates, insurance, interest...) are "Unallocated".
    The enterprises plus Unallocated add up to the whole farm.

    Examples: how am I tracking: versions [current, budget, last_year], period YTD or Season. Last three years:
    versions [season:2026, season:2025, season:2024], period Season. Per hectare: per_ha. One enterprise:
    enterprise "Cropping".
    TEXT)]
#[IsReadOnly]
class GetReportTool extends Tool
{
    private const array BASES = ['profit_and_loss' => ReportBasis::Accrual, 'cash_flow' => ReportBasis::Cash];

    public function handle(Request $request, FarmPlan $plan): Response
    {
        $basis = self::BASES[(string) $request->get('report', 'profit_and_loss')] ?? null;
        if ($basis === null) {
            return Response::error('report is profit_and_loss or cash_flow.');
        }
        $versions = $request->get('versions', ['current', 'budget']);
        if (!is_array($versions) || !array_is_list($versions) || $versions === []) {
            return Response::error('versions is a list such as ["current", "budget"].');
        }

        try {
            $report = $plan->report(
                $basis,
                array_map('strval', $versions),
                $request->get('season') === null ? null : (int) $request->get('season'),
                $request->get('from_month'),
                $request->get('months') === null ? null : (int) $request->get('months'),
                $request->get('period'),
                (bool) $request->get('categories', true),
                $request->get('enterprise'),
                (bool) $request->get('per_ha', false),
            );
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'report' => $request->get('report', 'profit_and_loss'),
            'plan_as_at' => $plan->plan->planHorizon,
            'actuals_to' => $plan->plan->actualsTo,
        ] + $report);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'report' => $schema->string()->enum(array_keys(self::BASES))->default('profit_and_loss')
                ->description('profit_and_loss (accrual: income, costs, profit) or cash_flow (cash: money in and out, GST, bank balance).'),
            'versions' => $schema->array()->items($schema->string())->default(['current', 'budget'])
                ->description('Versions side by side, the first compared with the rest: current, budget, actual, last_year, season:YYYY, snapshot:N, scenario:N.'),
            'season' => $schema->integer()->description('The season shown, by the year it ends, e.g. 2027 for FY27. Default: the plan\'s season.'),
            'from_month' => $schema->string()->pattern('^\d{4}-\d{2}$')->description('A run of months from this one (YYYY-MM) instead of a season.'),
            'months' => $schema->integer()->description('How many months from from_month (1 to 24; default 12, from the month after actuals_to).'),
            'period' => $schema->string()->pattern('^(YTD|Season|Total|\d{4}-\d{2})$')
                ->description('Only one period: YTD, Season (a season\'s total), Total (a run of months\' total) or a month YYYY-MM. Default: every period.'),
            'categories' => $schema->boolean()->default(true)->description('Include each income and cost category; false for totals and bottom lines only.'),
            'enterprise' => $schema->string()->description('Only this enterprise\'s income and costs (profit and loss), e.g. Cropping; Unallocated for whole-farm costs.'),
            'per_ha' => $schema->boolean()->default(false)->description('Per hectare: of the enterprise\'s area with an enterprise, else the farm\'s.'),
        ];
    }
}
