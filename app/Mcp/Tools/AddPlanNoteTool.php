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

#[Name('add-plan-note')]
#[Description(<<<'TEXT'
    Records context or a priority about this farm's plan, in the speaker's own words, for every later plan status
    to weigh: what to watch ("keep an eye on feed costs this winter"), what to set aside ("the repairs are the
    tractor clutch, already sorted"), or why something moved. Use it when the farmer or advisor tells you
    something like that. It changes no numbers.
    TEXT)]
class AddPlanNoteTool extends Tool
{
    public function handle(Request $request, FarmPlan $plan): Response
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
            'author' => ['nullable', 'in:farmer,advisor'],
        ]);
        $plan->addNote($validated['author'] ?? 'farmer', trim($validated['note']));

        return Response::text('Noted. Plan status will weigh it from now on.');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'note' => $schema->string()->required()->description('The context or priority, in the speaker\'s words.'),
            'author' => $schema->string()->enum(['farmer', 'advisor'])->default('farmer')->description('Who said it.'),
        ];
    }
}
