<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Insights\AlloyDB\InsightsSingleFarmReportQuery;
use App\Services\Insights\AlloyDB\InsightsSchema;
use App\Services\Insights\PortfolioAssumption;
use App\Services\Insights\PortfolioBreakdown;
use App\Services\Insights\PortfolioLine;
use App\Services\Insights\AlloyDB\PortfolioModellingOracle;
use App\Services\Insights\AlloyDB\PortfolioModellingQuery;
use App\Services\Insights\AlloyDB\PortfolioScope;
use App\Services\Insights\ReportBasis;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class InsightsPortfolioCommand extends Command
{
    protected $signature = 'insights:portfolio
        {--practice=1 : Practice whose farms make up the portfolio}
        {--basis=cash : cash (cash flow, closing cash) or accrual (profit and loss, net profit)}
        {--limit= : Only the practice\'s first N farms}
        {--assumption=* : line:season:percent, e.g. milk_income:2027:-5}
        {--runs=5 : Timed runs}
        {--summary : Portfolio totals only, no per-farm breakdown}
        {--check=0 : Recompute this many farms in PHP and compare every value}
        {--check-single=0 : Run this many farms\' single-farm report (cash flow or P&L, by basis) over the window and compare its season totals}
        {--explain : Print EXPLAIN ANALYZE instead of timing}';

    protected $description = 'Run FIP\'s Portfolio Modelling over a practice, live from raw journals, and time it';

    public function handle(): int
    {
        $db = DB::connection('alloydb');
        $query = new PortfolioModellingQuery($db);

        try {
            $limit = $this->option('limit');
            $basis = ReportBasis::tryFrom((string) $this->option('basis'))
                ?? throw new \InvalidArgumentException('--basis is cash or accrual.');
            $scope = PortfolioScope::forPractice($db, (int) $this->option('practice'), $limit === null ? null : (int) $limit, $basis);
            $assumptions = array_map(PortfolioAssumption::parse(...), (array) $this->option('assumption'));
            if ($assumptions === []) {
                $assumptions = [
                    new PortfolioAssumption(PortfolioLine::MilkIncome, PortfolioScope::CURRENT_SEASON, -5),
                    new PortfolioAssumption(PortfolioLine::Fertiliser, PortfolioScope::CURRENT_SEASON, 10),
                    new PortfolioAssumption(PortfolioLine::MilkIncome, PortfolioScope::CURRENT_SEASON + 1, 3),
                ];
            }
            $breakdown = !$this->option('summary');
            $rowsFor = $breakdown ? PortfolioBreakdown::everyLine() : PortfolioBreakdown::none();

            $this->info(sprintf(
                'Portfolio of %s farms, %s, seasons %d-%d, horizon %s, %d assumption(s)%s',
                number_format(count($scope->farmIds)),
                strtolower($basis->label()),
                $scope->firstSeason,
                $scope->lastSeason,
                $scope->horizon,
                count($assumptions),
                $breakdown ? ', with per-farm breakdown' : ', totals only',
            ));

            if ($this->option('explain')) {
                $this->line($query->explain($scope, $assumptions, $rowsFor));

                return self::SUCCESS;
            }

            $timings = [];
            $rows = [];
            for ($i = 0; $i < max(1, (int) $this->option('runs')); $i++) {
                $t = hrtime(true);
                $rows = $query->run($scope, $assumptions, $rowsFor);
                $timings[] = (hrtime(true) - $t) / 1e6;
                $this->line(sprintf('  run %d: %8.1f ms  (%s rows)', $i + 1, end($timings), number_format(count($rows))));
            }

            sort($timings);
            $this->info(sprintf('median %.1f ms, min %.1f ms, max %.1f ms', $timings[intdiv(count($timings), 2)], $timings[0], end($timings)));

            $this->table(
                ['season', 'line', 'farms', 'original', 'modelled', 'variance'],
                array_map(static fn (array $r): array => [
                    $r['season'],
                    PortfolioLine::from($r['line'])->label(),
                    $r['farms'],
                    number_format($r['original'], 2),
                    number_format($r['modelled'], 2),
                    number_format($r['variance'], 2),
                ], array_values(array_filter($rows, static fn (array $r): bool => $r['farm_id'] === null))),
            );

            $status = self::SUCCESS;

            $check = (int) $this->option('check');
            if ($check > 0 && $this->check($db, $scope, $assumptions, $rows, $check, $breakdown) !== self::SUCCESS) {
                $status = self::FAILURE;
            }

            $checkSingle = (int) $this->option('check-single');
            if ($checkSingle > 0 && $this->checkSingleFarm($db, $scope, $rows, $checkSingle, $breakdown) !== self::SUCCESS) {
                $status = self::FAILURE;
            }

            return $status;
        } catch (Throwable $e) {
            $this->error('Portfolio run failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param list<PortfolioAssumption> $assumptions
     * @param list<array<string, mixed>> $rows
     */
    private function check(ConnectionInterface $db, PortfolioScope $scope, array $assumptions, array $rows, int $farms, bool $breakdown): int
    {
        if (!$breakdown) {
            $this->warn('Parity needs the per-farm breakdown; rerun without --summary.');

            return self::FAILURE;
        }

        $sample = new PortfolioScope(array_slice($scope->farmIds, 0, $farms), $scope->horizon, $scope->firstSeason, $scope->lastSeason, $scope->basis);
        $this->info(sprintf('Recomputing %d farm(s) in PHP…', count($sample->farmIds)));
        $expected = (new PortfolioModellingOracle($db))->compute($sample, $assumptions);

        $compared = 0;
        $mismatches = [];
        foreach ($rows as $r) {
            if ($r['farm_id'] === null || !isset($expected[$r['farm_id']])) {
                continue;
            }
            $want = $expected[$r['farm_id']][$r['season']][$r['line']];
            foreach (['original', 'modelled'] as $field) {
                $compared++;
                if (abs($want[$field] - $r[$field]) > 0.011) {
                    $mismatches[] = [$r['farm_id'], $r['season'], $r['line'], $field, number_format($want[$field], 2), number_format($r[$field], 2)];
                }
            }
        }

        if ($mismatches === []) {
            $this->info(sprintf('Parity: %s values match to the cent.', number_format($compared)));

            return self::SUCCESS;
        }

        $this->error(sprintf('Parity: %d of %s values differ.', count($mismatches), number_format($compared)));
        $this->table(['farm', 'season', 'line', 'value', 'oracle', 'statement'], array_slice($mismatches, 0, 40));

        return self::FAILURE;
    }

    /**
     * The shared-logic constraint, checked: each farm's single-farm report
     * (the cash flow, or the P&L on accrual) over its whole window, rolled up
     * into seasons, must equal the portfolio's original values. Both run on
     * ReportLinesSqlBuilder, so a difference here is a presentation bug in
     * one of them.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function checkSingleFarm(ConnectionInterface $db, PortfolioScope $scope, array $rows, int $farms, bool $breakdown): int
    {
        if (!$breakdown) {
            $this->warn('Parity needs the per-farm breakdown; rerun without --summary.');

            return self::FAILURE;
        }

        $portfolio = [];
        foreach ($rows as $r) {
            if ($r['farm_id'] !== null) {
                $portfolio[$r['farm_id']][$r['season']][$r['line']] = $r['original'];
            }
        }

        $isCash = $scope->basis === ReportBasis::Cash;
        $report = new InsightsSingleFarmReportQuery($db, $scope->basis);
        $reportName = $isCash ? 'cash flow' : 'profit and loss';
        $s = InsightsSchema::SCHEMA;
        $compared = 0;
        $mismatches = [];
        $timings = [];

        foreach (array_slice($scope->farmIds, 0, $farms) as $farmId) {
            $farm = $db->selectOne("SELECT financial_year_end_month AS m, financial_year_end_day AS d FROM {$s}.farms WHERE id = ? AND _valid_to IS NULL", [$farmId]);
            $fyMonth = (int) $farm->m;
            $from = (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $scope->firstSeason - 1, $fyMonth, (int) $farm->d)))->modify('+1 day')->format('Y-m-d');
            $to = sprintf('%04d-%02d-%02d', $scope->lastSeason, $fyMonth, (int) $farm->d);

            $t = hrtime(true);
            $result = $report->run($farmId, $from, $to, $scope->horizon);
            $timings[] = (hrtime(true) - $t) / 1e6;

            $seasons = [];
            foreach ($result as $r) {
                if ($r['month'] === 'Total') {
                    continue;
                }
                $season = (int) substr($r['month'], 0, 4) + ((int) substr($r['month'], 5, 2) > $fyMonth ? 1 : 0);
                $v = &$seasons[$season];
                $v ??= $isCash
                    ? ['milk_income' => 0.0, 'total_income' => 0.0, 'fertiliser' => 0.0, 'total_operating_expenses' => 0.0, 'operating_surplus' => 0.0, 'net_cash_movement' => 0.0, 'closing_cash' => 0.0]
                    : ['milk_income' => 0.0, 'total_income' => 0.0, 'fertiliser' => 0.0, 'total_operating_expenses' => 0.0, 'operating_surplus' => 0.0, 'net_profit' => 0.0];
                match (true) {
                    $r['kind'] === 'category' && $r['category'] === 'Milk Income' => $v['milk_income'] += $r['amount'],
                    $r['kind'] === 'category' && $r['category'] === 'Fertiliser' => $v['fertiliser'] += $r['amount'],
                    $r['kind'] === 'section' && $r['field'] === 'income' => $v['total_income'] += $r['amount'],
                    $r['kind'] === 'section' && $r['field'] === 'operating_expenses' => $v['total_operating_expenses'] += $r['amount'],
                    $r['kind'] === 'calculation' && $r['field'] === 'operating_surplus' => $v['operating_surplus'] += $r['amount'],
                    $r['kind'] === 'calculation' && $r['field'] === 'net_cash_movement' => $v['net_cash_movement'] += $r['amount'],
                    $r['kind'] === 'calculation' && $r['field'] === 'net_profit' => $v['net_profit'] += $r['amount'],
                    $r['kind'] === 'balance' && $r['field'] === 'closing' => $v['closing_cash'] = $r['amount'],
                    default => null,
                };
                unset($v);
            }

            foreach ($seasons as $season => $v) {
                $v['other_income'] = $v['total_income'] - $v['milk_income'];
                $v['other_operating_expenses'] = $v['total_operating_expenses'] - $v['fertiliser'];
                foreach ($v as $line => $amount) {
                    $compared++;
                    $want = $portfolio[$farmId][$season][$line] ?? null;
                    // Monthly rounding in the single-farm report against
                    // seasonal rounding in the portfolio: a cent per month at most.
                    if ($want === null || abs($want - $amount) > 0.13) {
                        $mismatches[] = [$farmId, $season, $line, $want === null ? 'missing' : number_format($want, 2), number_format($amount, 2)];
                    }
                }
            }
        }

        sort($timings);
        $this->line(sprintf('  single-farm %s over the window: median %.1f ms per farm', $reportName, $timings[intdiv(count($timings), 2)]));

        if ($mismatches === []) {
            $this->info(sprintf('Shared logic: %s season values match the single-farm %s.', number_format($compared), $reportName));

            return self::SUCCESS;
        }

        $this->error(sprintf('Shared logic: %d of %s season values differ from the single-farm %s.', count($mismatches), number_format($compared), $reportName));
        $this->table(['farm', 'season', 'line', 'portfolio', $reportName], array_slice($mismatches, 0, 40));

        return self::FAILURE;
    }
}
