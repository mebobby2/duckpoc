<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Prompts\PlanStatusUpdatePrompt;
use App\Mcp\Tools\AddPlanNoteTool;
use App\Mcp\Tools\AdoptScenarioTool;
use App\Mcp\Tools\ComparePlanTool;
use App\Mcp\Tools\GetFarmProfileTool;
use App\Mcp\Tools\GetHerdValuationTool;
use App\Mcp\Tools\GetMilkProductionTool;
use App\Mcp\Tools\GetPlanStatusTool;
use App\Mcp\Tools\ListScenariosTool;
use App\Mcp\Tools\ModelScenarioTool;
use App\Mcp\Tools\SaveScenarioTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Figured farm plan')]
#[Version('0.2.0')]
#[Instructions(<<<'MARKDOWN'
    Figured's model of one farm's active plan. Every number comes from Figured's own reports (profit and loss,
    cash flow, milk, livestock), computed live from the farm's ledger and production records: quote them rather
    than recomputing them, and say which period and basis they are for.

    - The plan is the farm's forecast as it was agreed (`plan_as_at`). Current is actuals to `actuals_to`, then
      the same forecast after it. Variance is current minus plan; `effect` says whether it helps or hurts the farm.
    - Months after `actuals_to` are forecast, not results. "Season" is where the whole season is now heading.
    - Amounts are NZ dollars excluding GST, with income and costs both positive. Milk is in kgMS.
    - Start with get-farm-profile: it names the farm, its plan dates, and the ids of its milk supplies and
      livestock mobs that the other tools take.
    - For "how am I tracking?" or "give me an update", use get-plan-status and judge its evidence as the
      plan-status-update prompt describes; there are no built-in thresholds. When the farmer or advisor says what
      matters to them, or explains a variance, record it with add-plan-note.
    - For "what if" questions (buying a tractor, feed costing more, selling stock early), describe the change to
      model-scenario and let Figured work out the GST, timing, depreciation and loan; it returns plan, current and
      scenario side by side. save-scenario keeps one as a draft; adopt-scenario writes it into the forecast, only
      when the farmer explicitly says so. Milk price and production changes are not supported yet.
    MARKDOWN)]
class FarmPlanServer extends Server
{
    protected array $tools = [
        GetFarmProfileTool::class,
        ComparePlanTool::class,
        GetMilkProductionTool::class,
        GetHerdValuationTool::class,
        GetPlanStatusTool::class,
        AddPlanNoteTool::class,
        ModelScenarioTool::class,
        SaveScenarioTool::class,
        ListScenariosTool::class,
        AdoptScenarioTool::class,
    ];

    protected array $prompts = [
        PlanStatusUpdatePrompt::class,
    ];
}
