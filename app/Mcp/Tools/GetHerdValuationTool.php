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

#[Name('get-herd-valuation')]
#[Description(<<<'TEXT'
    Livestock on hand and its value at a month end, plan against current, by class, for each mob (livestock
    tracker) and the farm (level: class, tracker or farm). Head comes from the livestock movements, actual to
    actuals_to and forecast after; value is head times the season's value per head, the same valuation the
    profit and loss uses.

    The valuation is at the end of the month containing as_at. It defaults to actuals_to, the latest month with
    actual movements; a later date gives the forecast herd.
    TEXT)]
#[IsReadOnly]
class GetHerdValuationTool extends Tool
{
    public function handle(Request $request, FarmPlan $plan): Response
    {
        $asAt = (string) $request->get('as_at', $plan->plan->actualsTo);
        $trackerId = $request->get('tracker_id');

        try {
            $rows = $plan->herd($asAt, $trackerId === null ? null : (int) $trackerId);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'as_at_month_end' => date('Y-m-t', (int) strtotime($asAt)),
            'plan_as_at' => $plan->plan->planHorizon,
            'actuals_to' => $plan->plan->actualsTo,
            'rows' => $rows,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'as_at' => $schema->string()->format('date')
                ->description('A date (YYYY-MM-DD) in the plan season; the herd is valued at the end of its month. Defaults to actuals_to.'),
            'tracker_id' => $schema->integer()
                ->description('Only this mob, by its tracker id from get-farm-profile.'),
        ];
    }
}
