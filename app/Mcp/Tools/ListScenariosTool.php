<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Insights\DuckDB\FarmPlan;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-scenarios')]
#[Description(<<<'TEXT'
    The farm's saved scenarios: drafts, and those adopted into the forecast, with their changes. To see a
    draft's effect again, pass its changes to model-scenario.
    TEXT)]
#[IsReadOnly]
class ListScenariosTool extends Tool
{
    public function handle(Request $request, FarmPlan $plan): Response
    {
        return Response::json(['scenarios' => $plan->scenarios()]);
    }
}
