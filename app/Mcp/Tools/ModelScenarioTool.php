<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\TakesScenarioChanges;
use App\Services\Insights\DuckDB\FarmPlan;
use App\Services\Insights\DuckDB\ForecastEditsSqlBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('model-scenario')]
#[Description(<<<'TEXT'
    What if: how a change the farmer is considering would move the plan. Describe the change (buying an asset,
    with any trade-in and loan; or more or less income or cost in a category, or one enterprise's share of it,
    for some months), or name a saved scenario_id; Figured books it into the forecast the way it books any
    forecast, runs its own profit and loss and cash flow, and returns the result beside where the farm is heading
    now and the budget. Do not work out GST, payment timing, loan repayments, depreciation or overdraft interest
    yourself: they are in the result. Nothing is saved.

    headline: operating surplus, net profit, bank balance and the season's lowest month-end bank balance, with
    values for scenario, current and budget, and vs (scenario minus each) with its effect; for the lowest balance,
    the month it falls in on each side.
    changed_rows: every profit and loss and cash flow row the scenario moves, for the season (same shape).
    bank_by_month: month-end bank balance through the season on all three, and the change from current.
    booked: what each change books, by kind (purchase, trade_in, depreciation, loan_drawdown, loan_interest,
    loan_principal, adjustment) and category: the months it runs, the amount this season and in all, and its GST
    (positive: GST the farm claims back or no longer owes; negative: GST it owes). change_no is the change's
    position in the list, from 1.
    assumptions: how Figured booked it; mention what matters to the answer.
    TEXT)]
#[IsReadOnly]
class ModelScenarioTool extends Tool
{
    use TakesScenarioChanges;

    public function handle(Request $request, FarmPlan $plan): Response
    {
        try {
            $result = $plan->modelScenario(
                $request->get('changes'),
                $request->get('scenario_id') === null ? null : (int) $request->get('scenario_id'),
            );
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'plan_as_at' => $plan->plan->planHorizon,
            'actuals_to' => $plan->plan->actualsTo,
        ] + $result + ['assumptions' => ForecastEditsSqlBuilder::ASSUMPTIONS]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'changes' => $this->changesSchema($schema, required: false),
            'scenario_id' => $schema->integer()->description('A saved draft scenario to model instead of changes.'),
        ];
    }
}
