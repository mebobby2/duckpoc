<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Insights\DuckDB\FarmPlan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('save-snapshot')]
#[Description(<<<'TEXT'
    Saves the forecast as it stands now, with actuals to date, under a name ("Before the tractor", "October
    review"), to compare later as version snapshot:N in get-report, get-cash-outlook or get-position. Later changes
    to the forecast do not change a snapshot. It changes no numbers.
    TEXT)]
class SaveSnapshotTool extends Tool
{
    public function handle(Request $request, FarmPlan $plan): Response
    {
        $name = trim((string) $request->get('name', ''));
        if ($name === '') {
            return Response::error('name is required.');
        }

        $snapshot = $plan->saveSnapshot(mb_substr($name, 0, 255));

        return Response::json($snapshot + ['version' => 'snapshot:'.$snapshot['snapshot_id']]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['name' => $schema->string()->required()->description('A name the farmer will recognise.')];
    }
}
