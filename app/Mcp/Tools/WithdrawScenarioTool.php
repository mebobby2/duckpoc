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

#[Name('withdraw-scenario')]
#[Description(<<<'TEXT'
    Takes an adopted scenario back out of the farm's forecast from now on: the current forecast in every report no
    longer includes it, and a plan note records the change of mind. Snapshots taken while it was adopted still show
    it. Only when the farmer has explicitly said to; set confirmed to true to say they have.
    TEXT)]
class WithdrawScenarioTool extends Tool
{
    public function handle(Request $request, FarmPlan $plan): Response
    {
        if ($request->get('confirmed') !== true) {
            return Response::error('Ask the farmer first: withdrawing changes their forecast. Then call again with confirmed: true.');
        }

        try {
            $withdrawn = $plan->withdrawScenario((int) $request->get('scenario_id'), $request->get('author') === 'advisor' ? 'advisor' : 'farmer');
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json($withdrawn + ['status' => 'withdrawn']);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'scenario_id' => $schema->integer()->required()->description('An adopted scenario, from list-scenarios.'),
            'confirmed' => $schema->boolean()->required()->description('true only when the farmer has said to take it out of the forecast.'),
            'author' => $schema->string()->enum(['farmer', 'advisor'])->default('farmer')->description('Who asked for it.'),
        ];
    }
}
