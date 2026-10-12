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

#[Name('compare-plan')]
#[Description(<<<'TEXT'
    Plan against actuals for one period of the farm's season, from Figured's profit and loss or cash flow.
    Each row of the report (income and cost categories, section totals, operating surplus, net profit or net
    cash movement, and on the cash flow the bank balance) comes with plan, current, variance (current - plan),
    variance_pct and effect (favourable, adverse or none).

    Periods: "YTD" is the season so far, to actuals_to. "Season" is the whole season: the plan against where
    the farm is now heading (actuals plus the forecast still to come). "YYYY-MM" is one month: a month up to
    actuals_to compares what happened with the plan; a later month shows what the plan expects then.

    Use profit_and_loss for income, costs and profit (it includes the livestock valuation change); use
    cash_flow for money in and out, GST, and the bank balance.
    TEXT)]
#[IsReadOnly]
class ComparePlanTool extends Tool
{
    private const array BASES = ['profit_and_loss' => ReportBasis::Accrual, 'cash_flow' => ReportBasis::Cash];

    public function handle(Request $request, FarmPlan $plan): Response
    {
        $report = (string) $request->get('report', 'profit_and_loss');
        $period = (string) $request->get('period', 'YTD');
        $basis = self::BASES[$report] ?? null;
        if ($basis === null) {
            return Response::error('report is profit_and_loss or cash_flow.');
        }

        try {
            $rows = $plan->comparison($basis, $period, (bool) $request->get('categories', true));
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'report' => $report,
            'period' => $period,
            'status' => $rows[0]['status'] ?? null,
            'plan_as_at' => $plan->plan->planHorizon,
            'actuals_to' => $plan->plan->actualsTo,
            'rows' => array_map(static fn (array $r): array => [
                'row' => $r['kind'] === 'category' ? $r['category'] : $r['label'],
                'kind' => $r['kind'],
                'section' => $r['field'],
                'plan' => $r['plan'],
                'current' => $r['current'],
                'variance' => $r['variance'],
                'variance_pct' => $r['variance_pct'],
                'effect' => $r['effect'],
            ], $rows),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'report' => $schema->string()->enum(array_keys(self::BASES))->default('profit_and_loss')
                ->description('profit_and_loss (accrual: income, costs, profit) or cash_flow (cash: money in and out, bank balance).'),
            'period' => $schema->string()->pattern('^(YTD|Season|\d{4}-\d{2})$')->default('YTD')
                ->description('YTD, Season, or a month as YYYY-MM, e.g. 2026-08 for August 2026.'),
            'categories' => $schema->boolean()->default(true)
                ->description('Include each income and cost category; false for section totals and the bottom lines only.'),
        ];
    }
}
