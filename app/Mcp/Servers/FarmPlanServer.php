<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Prompts\ExplainReportPrompt;
use App\Mcp\Prompts\PlanStatusUpdatePrompt;
use App\Mcp\Tools\AddPlanNoteTool;
use App\Mcp\Tools\AdoptScenarioTool;
use App\Mcp\Tools\GetCashOutlookTool;
use App\Mcp\Tools\GetFarmProfileTool;
use App\Mcp\Tools\GetHerdValuationTool;
use App\Mcp\Tools\GetMilkProductionTool;
use App\Mcp\Tools\GetPlanStatusTool;
use App\Mcp\Tools\GetPositionTool;
use App\Mcp\Tools\GetReportTool;
use App\Mcp\Tools\ListScenariosTool;
use App\Mcp\Tools\ModelScenarioTool;
use App\Mcp\Tools\SaveScenarioTool;
use App\Mcp\Tools\SaveSnapshotTool;
use App\Mcp\Tools\WithdrawScenarioTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Figured farm plan')]
#[Version('0.3.0')]
#[Instructions(<<<'MARKDOWN'
    Figured's model of one farm: its history, its budget, where it is heading now, and what-ifs. Every number
    comes from Figured's own reports (profit and loss, cash flow, position, milk, livestock), computed live from
    the farm's ledger and production records: quote them rather than recomputing them, and say which version,
    period and basis they are for.

    - Start with get-farm-profile: the farm, its area and enterprises, its overdraft, its plan dates, its
      snapshots, and the ids of its milk supplies and livestock mobs that the other tools take.
    - Amounts are NZ dollars excluding GST, income and costs both positive. Milk is in kgMS. Months after
      actuals_to are forecast, not results.
    - How the farm has performed (history, an enterprise, per hectare): get-report with past seasons as versions
      (season:YYYY), the enterprise, per_ha.
    - How it is tracking against budget and last year, so far and at year end: get-report with versions current,
      budget, last_year; for a judged update, get-plan-status and the plan-status-update prompt (no built-in
      thresholds). Record what the farmer or advisor says matters with add-plan-note.
    - Cash over the months ahead, the overdraft, and whether something is affordable: get-cash-outlook (with the
      change, for "can I afford it").
    - Position, net worth, equity, debt and loans, now and at year end: get-position.
    - Explaining a report, or budget against forecast against a snapshot: get-report with those versions and the
      explain-report prompt; save-snapshot keeps today's forecast to compare with later.
    - What-ifs: describe the change to model-scenario and let Figured work out the GST, timing, depreciation, loan
      and overdraft interest. save-scenario keeps one; adopt-scenario writes it into the forecast and
      withdraw-scenario takes it out again, each only when the farmer explicitly says so. Milk price and production
      changes are not supported yet.
    MARKDOWN)]
class FarmPlanServer extends Server
{
    protected array $tools = [
        GetFarmProfileTool::class,
        GetReportTool::class,
        GetCashOutlookTool::class,
        GetPositionTool::class,
        GetPlanStatusTool::class,
        GetMilkProductionTool::class,
        GetHerdValuationTool::class,
        AddPlanNoteTool::class,
        ModelScenarioTool::class,
        SaveScenarioTool::class,
        ListScenariosTool::class,
        AdoptScenarioTool::class,
        WithdrawScenarioTool::class,
        SaveSnapshotTool::class,
    ];

    protected array $prompts = [
        PlanStatusUpdatePrompt::class,
        ExplainReportPrompt::class,
    ];
}
