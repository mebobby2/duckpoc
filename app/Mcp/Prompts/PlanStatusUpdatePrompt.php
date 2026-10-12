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
 * The judgement layer of plan status, as guidance rather than rules: what
 * to weigh when deciding what in the signals matters, written to be edited
 * as Figured learns what farmers and advisors want to hear.
 */
#[Name('plan-status-update')]
#[Description('Give the farmer or their advisor an update on how the farm is tracking against its active plan.')]
class PlanStatusUpdatePrompt extends Prompt
{
    /** How to judge the plan status signals; get-plan-status hands it to clients that do not use prompts. */
    public const string GUIDANCE = <<<'TEXT'
        - Lead with what changes the season's outcome: operating surplus, net profit and the lowest month-end bank
          balance in the headline.
        - A signal deserves attention when it is both outside the farm's normal variation (unusual_z well away from 0)
          and material (share_of_season_income_pct). attention combines the two for ranking, but it is a starting
          point, not the answer.
        - Before calling an overspend a problem, check timing: if most of it landed in the latest month and it is about
          what the plan still has coming next month, it is probably spending brought forward. Say so, and say when it
          should even out.
        - Use the peers: if peer_median_pct is close to the farm's own variance, the cause is probably shared (weather,
          prices) and not this farm's doing; if the farm sits at an extreme peer_percentile, it is specific to the farm.
        - When season_if_trend_continues differs much from season_if_back_on_plan, give both and say which looks
          likelier and why.
        - The year-to-date bank balance carries GST timing: actual milk cheques include GST that is paid back at the
          next return, and the plan's milk forecast shows none. Judge cash on the Season figures and the lowest
          month-end balance, not the year-to-date gap alone.
        - The notes are the farmer's and advisor's own priorities and context: follow them, even over the ranking.
        - Quote the tools' numbers with their period. Do not invent figures or do more than simple sums. If the
          evidence is unclear, say what you would check, for example with compare-plan or get-milk-production.
        TEXT;

    private const array AUDIENCES = [
        'farmer' => 'the farmer. Plain language, no jargon: the two or three things that matter most, why, and one sensible next step.',
        'advisor' => 'their advisor. More detail and the numbers behind each point: what is off track and why, what is probably timing, what to raise with the farmer, and what to watch.',
    ];

    public function handle(Request $request): Response
    {
        $audience = self::AUDIENCES[$request->get('audience', 'farmer')] ?? self::AUDIENCES['farmer'];

        return Response::text(sprintf(
            "Give an update on how this farm is tracking against its active plan, for %s\n\n"
            ."Call get-plan-status (and get-farm-profile if you do not know the farm yet). Then judge; do not just list.\n\n%s",
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
            new Argument('audience', 'farmer or advisor (default farmer)'),
        ];
    }
}
