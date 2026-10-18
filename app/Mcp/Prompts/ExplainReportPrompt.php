<?php

declare(strict_types=1);

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

/**
 * How to explain a Figured report and the differences between its
 * versions: guidance, not rules, written to be edited as Figured learns
 * what farmers and advisors find clear.
 */
#[Name('explain-report')]
#[Description('Explain a report, or the difference between two versions of it (budget, current forecast, a snapshot, last year), in plain terms.')]
class ExplainReportPrompt extends Prompt
{
    public const string GUIDANCE = <<<'TEXT'
        - Say which report it is. The profit and loss counts income when earned and costs when incurred, including
          the livestock valuation change and depreciation, and leaves out capital purchases and loan principal. The
          cash flow counts money when it moves: GST, capital purchases, loan repayments and drawings included,
          ending in the bank balance.
        - When profit and cash disagree, give the reasons that apply: bills paid after the month they belong to
          (forecast bills about 20 days later; milk on the 20th of the next month); GST collected or claimed and
          settled with Inland Revenue in two-monthly returns; the livestock valuation change (profit, no cash);
          capital purchases and loan principal (cash, no profit); depreciation (profit, no cash); overdraft
          interest (both).
        - Name versions plainly: current is actuals to date then the forecast as it stands; the budget is the plan
          as agreed; a snapshot is the forecast as it stood on the day it was saved; last year is the same months a
          year earlier.
        - For a comparison, lead with the bottom line (operating surplus, net profit or the closing bank balance),
          then the few rows that explain most of the gap. Say which differences are timing and which will not
          come back by the season's end.
        - For a quick what-if on a saved scenario, use model-scenario with its scenario_id, or get-report with
          scenario:N beside current and the budget.
        - Quote the tools' numbers with their version and period; do not recompute them. If the tools cannot break
          a number down further, say so.
        TEXT;

    private const array AUDIENCES = [
        'farmer' => 'the farmer, in plain language: what the report says, the two or three things behind it, and what it means for them.',
        'advisor' => 'their advisor: the structure, the numbers behind each point, and what to raise with the farmer.',
    ];

    public function handle(Request $request): Response
    {
        $audience = self::AUDIENCES[$request->get('audience', 'farmer')] ?? self::AUDIENCES['farmer'];
        $report = $request->get('report');

        return Response::text(sprintf(
            "Explain %s for %s\n\nUse get-report (and get-farm-profile if you do not know the farm yet).\n\n%s",
            is_string($report) && $report !== '' ? "the {$report}" : 'the report the farmer is asking about',
            $audience,
            self::GUIDANCE,
        ));
    }

    /**
     * @return list<Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('report', 'what to explain, e.g. "profit and loss against budget this season"'),
            new Argument('audience', 'farmer or advisor (default farmer)'),
        ];
    }
}
