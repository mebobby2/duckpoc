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

#[Name('adopt-scenario')]
#[Description(<<<'TEXT'
    Writes a saved scenario into the farm's forecast in Figured: from then on the current forecast in every report
    (get-report, get-plan-status, get-cash-outlook, get-position) includes it. The budget does not change, so the
    scenario will show as a variance against it, and a plan note records that it was chosen. withdraw-scenario
    takes it back out; snapshots taken while it was in keep it.

    Only call this when the farmer has explicitly said to update their forecast with this scenario, after seeing
    its effect; set confirmed to true to say they have.
    TEXT)]
class AdoptScenarioTool extends Tool
{
    public function handle(Request $request, FarmPlan $plan): Response
    {
        if ($request->get('confirmed') !== true) {
            return Response::error('Ask the farmer first: adopting changes their forecast. Then call again with confirmed: true.');
        }

        try {
            $adopted = $plan->adoptScenario((int) $request->get('scenario_id'), $this->author($request));
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json($adopted + ['status' => 'adopted']);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'scenario_id' => $schema->integer()->required()->description('From save-scenario or list-scenarios.'),
            'confirmed' => $schema->boolean()->required()->description('true only when the farmer has said to update the forecast.'),
            'author' => $schema->string()->enum(['farmer', 'advisor'])->default('farmer')->description('Who asked for it.'),
        ];
    }

    private function author(Request $request): string
    {
        return $request->get('author') === 'advisor' ? 'advisor' : 'farmer';
    }
}
