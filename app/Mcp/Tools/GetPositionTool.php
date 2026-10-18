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

#[Name('get-position')]
#[Description(<<<'TEXT'
    The farm's position: what it owns, what it owes and its net worth (equity). For the current forecast at the end
    of last season, now (actuals_to) and at the end of this season; and for each version compared (default the
    budget) now and at the season's end.

    Owns: bank (when in credit), livestock (the herd at its valuation), land and buildings, plant and machinery,
    and money owed to the farm (income earned, not yet received). Owes: overdraft, term loans, money the farm owes
    (costs incurred, not yet paid) and GST owed (negative: a refund due). net_worth = total_assets -
    total_liabilities.

    Each point after the first reconciles: net_worth_change_since = net_profit_since + equity_movements_since
    (drawings out, capital in), with unexplained the remainder (zero when the books agree). Pass changes or a
    saved scenario_id to see a what-if's effect on debt and net worth.
    TEXT)]
#[IsReadOnly]
class GetPositionTool extends Tool
{
    use TakesScenarioChanges;

    public function handle(Request $request, FarmPlan $plan): Response
    {
        try {
            $position = $plan->position(
                array_map('strval', (array) $request->get('compare', ['budget'])),
                $request->get('changes'),
                $request->get('scenario_id') === null ? null : (int) $request->get('scenario_id'),
            );
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json(['actuals_to' => $plan->plan->actualsTo] + $position);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'compare' => $schema->array()->items($schema->string())->default(['budget'])
                ->description('Versions beside current: budget, snapshot:N. Empty for current alone.'),
            'changes' => $this->changesSchema($schema, required: false),
            'scenario_id' => $schema->integer()->description('A saved scenario to apply instead of changes.'),
        ];
    }
}
