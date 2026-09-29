<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Insights\AlloyDB\InsightsSchema;
use App\Services\Insights\PortfolioAssumption;
use App\Services\Insights\PortfolioLine;
use App\Services\Insights\AlloyDB\PortfolioModellingQuery;
use App\Services\Insights\AlloyDB\PortfolioScope;
use App\Services\Insights\ReportBasis;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * FIP's Portfolio Modelling page, served live from AlloyDB with no synced
 * copy: one statement over the practice's raw journals per page load.
 *
 * Assumptions ride in the query string as `a[]=line:season:percent`, so a
 * modelled view is a URL that can be shared or reloaded.
 */
class InsightsPortfolioController extends Controller
{
    private const string SUMMARY_TOTAL = 'total';
    private const string SUMMARY_AVERAGE = 'average';

    public function __invoke(Request $request): View|StreamedResponse
    {
        $db = DB::connection('alloydb');
        $s = InsightsSchema::SCHEMA;

        $practices = array_map(
            static fn (object $p): array => ['id' => (int) $p->id, 'name' => (string) $p->name, 'farms' => (int) $p->farms],
            $db->select("SELECT p.id, p.name, count(fp.farm_id) AS farms
                         FROM {$s}.practices p LEFT JOIN {$s}.farm_practice fp ON fp.practice_id = p.id
                         GROUP BY p.id, p.name ORDER BY p.id"),
        );

        $practiceId = (int) $request->query('practice', (string) ($practices[0]['id'] ?? 1));
        $summary = $request->query('summary') === self::SUMMARY_TOTAL ? self::SUMMARY_TOTAL : self::SUMMARY_AVERAGE;
        $basis = ReportBasis::tryFrom((string) $request->query('basis', '')) ?? ReportBasis::Cash;
        $lines = PortfolioLine::forBasis($basis);
        $selectedLine = PortfolioLine::tryFrom((string) $request->query('line', '')) ?? PortfolioLine::OperatingSurplus;
        if (!in_array($selectedLine, $lines, true)) {
            $selectedLine = PortfolioLine::OperatingSurplus;
        }
        $selectedSeason = (int) $request->query('season', (string) PortfolioScope::CURRENT_SEASON);

        [$specs, $error] = $this->assumptionSpecs($request);
        $assumptions = array_map(PortfolioAssumption::parse(...), $specs);

        $scope = null;
        $rows = [];
        $elapsedMs = null;
        $farms = [];

        try {
            $scope = PortfolioScope::forPractice($db, $practiceId, basis: $basis);
            $started = microtime(true);
            $rows = (new PortfolioModellingQuery($db))->run($scope, $assumptions);
            $elapsedMs = (microtime(true) - $started) * 1000;
            $farms = $this->farms($db, $scope->farmIds);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $seasons = $scope === null ? [] : range($scope->firstSeason, $scope->lastSeason);
        if (!in_array($selectedSeason, $seasons, true) && $seasons !== []) {
            $selectedSeason = PortfolioScope::CURRENT_SEASON;
        }

        $totals = [];
        $breakdown = [];
        foreach ($rows as $r) {
            if ($r['farm_id'] === null) {
                $totals[$r['line']][$r['season']] = $r;
            } elseif ($r['line'] === $selectedLine->value && $r['season'] === $selectedSeason) {
                $breakdown[] = $r + ['farm' => $farms[$r['farm_id']] ?? null];
            }
        }

        $sort = (string) $request->query('sort', 'variance');
        usort($breakdown, match ($sort) {
            'farm' => static fn (array $a, array $b): int => $a['farm_id'] <=> $b['farm_id'],
            'original' => static fn (array $a, array $b): int => $b['original'] <=> $a['original'],
            'modelled' => static fn (array $a, array $b): int => $b['modelled'] <=> $a['modelled'],
            default => static fn (array $a, array $b): int => $a['variance'] <=> $b['variance'] ?: $a['farm_id'] <=> $b['farm_id'],
        });

        if ($request->query('export') === 'csv') {
            return $this->csv($totals, $seasons, $summary, $lines);
        }

        return view('insights-portfolio', [
            'practices' => $practices,
            'practiceId' => $practiceId,
            'summary' => $summary,
            'lines' => $lines,
            'basis' => $basis,
            'seasons' => $seasons,
            'selectedLine' => $selectedLine,
            'selectedSeason' => $selectedSeason,
            'assumptions' => $assumptions,
            'specs' => $specs,
            'totals' => $totals,
            'breakdown' => $breakdown,
            'sort' => $sort,
            'farmCount' => $scope === null ? 0 : count($scope->farmIds),
            'horizon' => $scope?->horizon,
            'elapsedMs' => $elapsedMs,
            'journalLines' => $scope === null ? 0 : $this->journalLines($db, $scope->farmIds),
            'error' => $error,
        ]);
    }

    /**
     * The existing `a[]` specs, plus the one the Add form submitted.
     *
     * @return array{0: list<string>, 1: ?string}
     */
    private function assumptionSpecs(Request $request): array
    {
        $specs = [];
        $error = null;

        $candidates = array_values(array_filter((array) $request->query('a', []), 'is_string'));
        $line = (string) $request->query('new_line', '');
        $percent = (string) $request->query('new_percent', '');
        if ($line !== '' && $percent !== '') {
            $candidates[] = sprintf('%s:%s:%s', $line, (string) $request->query('new_season', ''), $percent);
        }

        $seen = [];
        foreach ($candidates as $spec) {
            try {
                $a = PortfolioAssumption::parse($spec);
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
                continue;
            }
            // A newer assumption for the same line and season replaces the older one.
            $seen[$a->line->value.':'.$a->season] = sprintf('%s:%d:%s', $a->line->value, $a->season, rtrim(rtrim(number_format($a->percent, 2, '.', ''), '0'), '.'));
        }

        foreach ($seen as $spec) {
            $specs[] = $spec;
        }

        return [$specs, $error];
    }

    /**
     * @param list<int> $farmIds
     * @return array<int, array{name: string, region: string, type: string}>
     */
    private function farms(ConnectionInterface $db, array $farmIds): array
    {
        $s = InsightsSchema::SCHEMA;
        $farms = [];
        foreach ($db->select(
            "SELECT f.id, f.name, f.region_primary, t.name AS type
             FROM {$s}.farms f
             LEFT JOIN {$s}.farms_operation_types o ON o.farm_id = f.id AND o._valid_to IS NULL
             LEFT JOIN {$s}.farm_types t ON t.uuid = o.farm_type_uuid
             WHERE f.id = ANY (CAST(? AS INTEGER[])) AND f._valid_to IS NULL",
            ['{'.implode(',', $farmIds).'}'],
        ) as $f) {
            $farms[(int) $f->id] = ['name' => (string) $f->name, 'region' => (string) $f->region_primary, 'type' => (string) ($f->type ?? '')];
        }

        return $farms;
    }

    /**
     * @param list<int> $farmIds
     */
    private function journalLines(ConnectionInterface $db, array $farmIds): int
    {
        $s = InsightsSchema::SCHEMA;

        return (int) ($db->selectOne(
            "SELECT count(*) AS n FROM {$s}.transaction_lines WHERE farm_id IN (SELECT unnest(CAST(? AS INTEGER[])))",
            ['{'.implode(',', $farmIds).'}'],
        )->n ?? 0);
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $totals
     * @param list<int> $seasons
     * @param list<PortfolioLine> $lines
     */
    private function csv(array $totals, array $seasons, string $summary, array $lines): StreamedResponse
    {
        return response()->streamDownload(function () use ($totals, $seasons, $summary, $lines): void {
            $out = fopen('php://output', 'w');
            $header = ['Line'];
            foreach ($seasons as $season) {
                array_push($header, "FY{$season} Baseline", "FY{$season} Modelled", "FY{$season} Variance");
            }
            fputcsv($out, $header);

            foreach ($lines as $line) {
                $row = [$line->label()];
                foreach ($seasons as $season) {
                    $cell = $totals[$line->value][$season] ?? null;
                    $divisor = $summary === self::SUMMARY_AVERAGE && $cell !== null ? max(1, $cell['farms']) : 1;
                    foreach (['original', 'modelled', 'variance'] as $field) {
                        $row[] = $cell === null ? '' : round($cell[$field] / $divisor, 2);
                    }
                }
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'portfolio-modelling.csv', ['Content-Type' => 'text/csv']);
    }
}
