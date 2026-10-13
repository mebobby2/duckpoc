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

#[Name('save-scenario')]
#[Description(<<<'TEXT'
    Saves a scenario under a name, as a draft the farmer or advisor can come back to, compare or adopt. It does
    not change the forecast. Returns its scenario_id and the same result as model-scenario. Use it when the
    farmer wants to keep a what-if, or before adopting one.
    TEXT)]
class SaveScenarioTool extends Tool
{
    use TakesScenarioChanges;

    public function handle(Request $request, FarmPlan $plan): Response
    {
        $name = trim((string) $request->get('name', ''));
        if ($name === '') {
            return Response::error('name is required, e.g. "New tractor, financed".');
        }

        try {
            $result = $plan->modelScenario($request->get('changes'));
            $saved = $plan->saveScenario(mb_substr($name, 0, 255), $request->get('changes'));
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json($saved + ['name' => $name, 'status' => 'draft'] + $result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('A short name the farmer will recognise.'),
            'changes' => $this->changesSchema($schema),
        ];
    }
}
