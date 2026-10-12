<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Insights\DuckDB\FarmPlan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-milk-production')]
#[Description(<<<'TEXT'
    Milk this season, plan against current, for each milk supply and the farm total ("Farm total", tracker_id
    null): kgMS supplied, milk income, price per kgMS, and the income variance split exactly into volume_effect
    (the kgMS difference at the plan's price) and price_effect (the price difference on the kgMS supplied).

    Rows run by month, then "YTD" (season to actuals_to) and "Season" (projected: actuals plus the remaining
    forecast). status says whether a month is actual or forecast. Income is the profit and loss's milk income,
    in the month the milk was produced.
    TEXT)]
#[IsReadOnly]
class GetMilkProductionTool extends Tool
{
    public function handle(Request $request, FarmPlan $plan): Response
    {
        try {
            $rows = $plan->milk($request->get('period'));
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'plan_as_at' => $plan->plan->planHorizon,
            'actuals_to' => $plan->plan->actualsTo,
            'rows' => $rows,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()->pattern('^(YTD|Season|\d{4}-\d{2})$')
                ->description('Only this period: YTD, Season, or a month as YYYY-MM. Leave out for every month plus YTD and Season.'),
        ];
    }
}
