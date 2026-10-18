<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\TakesScenarioChanges;
use App\Services\Insights\DuckDB\FarmPlan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-cash-outlook')]
#[Description(<<<'TEXT'
    The farm's cash over the months ahead (default the 12 months after actuals_to): each month's closing bank
    balance, net cash movement, overdraft interest and headroom (the overdraft limit plus the balance; below zero is
    over the limit), in the current forecast and each version compared (default the budget), with the overdraft
    limit and rate. summary gives, per version, the lowest balance and its month, the first month overdrawn, the
    first month over the limit, and the overdraft interest over the period. Overdraft interest is Figured's: charged
    monthly at the facility rate on the overdrawn balance, and it is already in the balances.

    For "can I afford X", pass the change (changes, as model-scenario takes them) or a saved scenario_id: the
    scenario comes first and current beside it.
    TEXT)]
#[IsReadOnly]
class GetCashOutlookTool extends Tool
{
    use TakesScenarioChanges;

    public function handle(Request $request, FarmPlan $plan): Response
    {
        try {
            $outlook = $plan->cashOutlook(
                (int) $request->get('months', 12),
                array_map('strval', (array) $request->get('compare', ['budget'])),
                $request->get('from_month'),
                $request->get('changes'),
                $request->get('scenario_id') === null ? null : (int) $request->get('scenario_id'),
            );
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json(['actuals_to' => $plan->plan->actualsTo] + $outlook);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'months' => $schema->integer()->default(12)->description('How many months ahead, 1 to 24.'),
            'from_month' => $schema->string()->pattern('^\d{4}-\d{2}$')->description('First month (YYYY-MM); default the month after actuals_to.'),
            'compare' => $schema->array()->items($schema->string())->default(['budget'])
                ->description('Versions beside current: budget, last_year, snapshot:N. Empty for current alone.'),
            'changes' => $this->changesSchema($schema, required: false),
            'scenario_id' => $schema->integer()->description('A saved scenario to apply instead of changes.'),
        ];
    }
}
