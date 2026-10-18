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
    The farm's saved scenarios: drafts, those adopted into the forecast, and those withdrawn from it, with their
    changes and when. To see a draft's effect again, pass its scenario_id to model-scenario, or compare drafts side
    by side with get-report versions [current, scenario:N, scenario:M].
    TEXT)]
#[IsReadOnly]
class ListScenariosTool extends Tool
{
    public function handle(Request $request, FarmPlan $plan): Response
    {
        return Response::json(['scenarios' => $plan->scenarios()]);
    }
}
