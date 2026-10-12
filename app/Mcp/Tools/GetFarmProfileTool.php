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

#[Name('get-farm-profile')]
#[Description(<<<'TEXT'
    The farm this server answers for: name, farm type, region and balance date; its active plan (the season it
    covers, the date the plan was agreed at, and how far actuals have come in); and its milk supplies and
    livestock mobs with the tracker ids the other tools take. Call this first.
    TEXT)]
#[IsReadOnly]
class GetFarmProfileTool extends Tool
{
    public function handle(Request $request, FarmPlan $plan): Response
    {
        return Response::json($plan->profile());
    }
}
