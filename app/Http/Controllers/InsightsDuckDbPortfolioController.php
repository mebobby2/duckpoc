<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Insights\DuckDB\DuckDbStatement;
use App\Services\Insights\DuckDB\InsightsDuckDb;
use App\Services\Insights\DuckDB\PortfolioModellingQuery;
use App\Services\Insights\DuckDB\PortfolioScope;
use App\Services\Insights\PortfolioAssumption;
use App\Services\Insights\PortfolioBreakdown;
use App\Services\Insights\PortfolioBreakdownSort;
use App\Services\Insights\PortfolioLine;
use App\Services\Insights\ReportBasis;
use Illuminate\Http\Request;
use Saturio\DuckDB\DuckDB;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * FIP's Portfolio Modelling page, served live from DuckDB with no synced
 * copy: one statement per page load over the practice's journal lines in the
 * lake, joined to farm and production data in MySQL.
 *
 * Assumptions ride in the query string as `a[]=line:season:percent`, so a
 * modelled view is a URL that can be shared or reloaded.
 */
class InsightsDuckDbPortfolioController extends Controller
{
    private const string SUMMARY_TOTAL = 'total';
    private const string SUMMARY_AVERAGE = 'average';

    public function __invoke(Request $request): View|StreamedResponse
    {
        $mysql = InsightsDuckDb::mysql();

        $practices = array_map(
            static fn (object $p): array => ['id' => (int) $p->id, 'name' => (string) $p->name, 'farms' => (int) $p->farms],
            $mysql->select('SELECT p.id, p.name, count(fp.farm_id) AS farms
                            FROM practices p LEFT JOIN farm_practice fp ON fp.practice_id = p.id
                            GROUP BY p.id, p.name ORDER BY p.id'),
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

        $sort = PortfolioBreakdownSort::tryFrom((string) $request->query('sort', '')) ?? PortfolioBreakdownSort::Variance;
        $exportCsv = $request->query('export') === 'csv';

        $scope = null;
        $seasons = [];
        $rows = [];
        $elapsedMs = null;
        $connectMs = 0.0;
        $journalLines = 0;

        try {
            $started = microtime(true);
            $db = InsightsDuckDb::connect();
            $connectMs = (microtime(true) - $started) * 1000;
            $scope = PortfolioScope::forPractice($practiceId, basis: $basis);
            $seasons = range($scope->firstSeason, $scope->lastSeason);
            if (!in_array($selectedSeason, $seasons, true)) {
                $selectedSeason = PortfolioScope::CURRENT_SEASON;
            }
            $breakdown = $exportCsv ? PortfolioBreakdown::none() : PortfolioBreakdown::of($selectedLine, $selectedSeason, $sort);
            $started = microtime(true);
            $rows = (new PortfolioModellingQuery($db))->run($scope, $assumptions, $breakdown);
            $elapsedMs = (microtime(true) - $started) * 1000;
            $journalLines = $this->journalLines($db, $scope->farmIds);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $totals = [];
        $breakdown = [];
        foreach ($rows as $r) {
            if ($r['farm_id'] === null) {
                $totals[$r['line']][$r['season']] = $r;
            } else {
                $breakdown[] = $r;
            }
        }

        if ($exportCsv) {
            return $this->csv($totals, $seasons, $summary, $lines);
        }

        return view('insights-duckdb-portfolio', [
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
            'sort' => $sort->value,
            'farmCount' => $scope === null ? 0 : count($scope->farmIds),
            'horizon' => $scope?->horizon,
            'elapsedMs' => $elapsedMs,
            'journalLines' => $journalLines,
            'connectMs' => $connectMs,
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
     */
    private function journalLines(DuckDB $db, array $farmIds): int
    {
        $ids = implode(',', array_map('intval', $farmIds));
        $rows = DuckDbStatement::rows($db, 'SELECT count(*) AS n FROM '.InsightsDuckDb::lines()." WHERE farm_id IN ({$ids})");

        return (int) ($rows[0]['n'] ?? 0);
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
                    $suffix = $summary === self::SUMMARY_AVERAGE ? '_per_farm' : '';
                    foreach (['original', 'modelled', 'variance'] as $field) {
                        $row[] = $cell === null ? '' : $cell[$field . $suffix];
                    }
                }
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'portfolio-modelling.csv', ['Content-Type' => 'text/csv']);
    }
}
