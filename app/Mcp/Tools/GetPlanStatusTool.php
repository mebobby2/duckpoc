<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Prompts\PlanStatusUpdatePrompt;
use App\Services\Insights\DuckDB\FarmPlan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-plan-status')]
#[Description(<<<'TEXT'
    How the farm is tracking against its active plan: the evidence to judge it, not a verdict. There are no
    thresholds here; deciding what matters is yours (the plan-status-update prompt describes how).

    headline: operating surplus and net profit (YTD and Season), the bank balance, and the season's lowest
    month-end bank balance, plan against current.

    signals: every profit and loss category and section, ranked by attention. For each:
    - variance_since_plan (and _pct): actual minus plan over the months since the plan was agreed;
    - unusual_z and normal_spread: how far that sits outside the farm's own normal variation (from its last
      three seasons) - |z| near 1 is ordinary, larger is less and less ordinary;
    - peer_median_pct and peer_percentile: the same line on farms of the same type in the same practice - a
      variance the peers share points to a shared cause (weather, prices), one at an extreme percentile to this
      farm;
    - share_of_season_income_pct: how much it matters against the season's planned income;
    - latest_month_share, variance_vs_next_month, variance_vs_next_two_months: timing evidence - most of it in
      the latest month and about equal to what the plan still has coming next suggests spending brought
      forward rather than an overrun;
    - season_if_back_on_plan and season_if_trend_continues: the season's outlook either way;
    - attention: the dollars, weighted by how unusual they are; an ordering, not a cut-off.

    notes: what the farmer or advisor has said matters or explains; weigh them.
    how_to_judge: the guidance for turning this evidence into an update.
    TEXT)]
#[IsReadOnly]
class GetPlanStatusTool extends Tool
{
    public function handle(Request $request, FarmPlan $plan): Response
    {
        $limit = min(30, max(1, (int) $request->get('limit', 8)));
        $status = $plan->status($limit);

        return Response::json([
            'plan' => $plan->plan->name,
            'plan_as_at' => $plan->plan->planHorizon,
            'actuals_to' => $plan->plan->actualsTo,
        ] + $status + ['how_to_judge' => PlanStatusUpdatePrompt::GUIDANCE]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->default(8)
                ->description('How many signals to return, most attention first (1 to 30).'),
        ];
    }
}
