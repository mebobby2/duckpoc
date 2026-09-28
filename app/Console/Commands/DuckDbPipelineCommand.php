<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashFlow\CashFlowSchema;
use App\Services\CashFlow\DataPipelineCheck;
use App\Services\CashFlow\DataPipelineOracle;
use App\Services\CashFlow\DataPipelineSqlBuilder;
use App\Services\CashFlow\PipelineOptions;
use App\Services\CashFlow\PipelineOracleSeeder;
use App\Services\CashFlow\PipelineScaleSeeder;
use App\Services\CashFlow\ReportingGroupEntities;
use App\Services\CashFlow\ReportingGroupScaleSeeder;
use App\Services\CashFlow\ReportingGroupSeeder;
use Illuminate\Console\Command;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Checks `DataPipelineSqlBuilder` against `DataPipelineOracle` after every
 * pipe, not only at the end.
 *
 * A pipeline whose stages are ordering-dependent can be wrong at stage 9 and
 * right again by stage 24 if two mistakes cancel. Diffing each stage's CTE
 * against the transliteration's snapshot of the same pipe is what makes the
 * ordering itself the thing under test.
 */
class DuckDbPipelineCommand extends Command
{
    protected $signature = 'duckdb:pipeline
        {--farm= : Farm (defaults to the pipeline oracle farm)}
        {--from= : Period start}
        {--to= : Period end}
        {--horizon= : Actuals/forecast boundary}
        {--type=actualsForecast : budget | actualsForecast}
        {--basis=cash : cash | accrual}
        {--ytd : Year-to-date (BuildAggregationPipeline + FixYearToDateValues)}
        {--ytd-type= : season, to reset the running total each financial year}
        {--exclude-eoy : excludeEoyJournals}
        {--opening-gst : includeOpeningBudgetGst}
        {--cye : calculateCurrentYearEarnings}
        {--retained : calculateRetained}
        {--expected-sign : showExpectedSign}
        {--inverse : inverse}
        {--dynamic-bank : dynamicBankAccount}
        {--all : Turn on every logic pipe that has a gate}
        {--seed : Seed the pipeline oracle farm first}
        {--scale= : Seed pipeline-scale-<N> with about N lines and run against it}
        {--accounts=0 : With --scale, spread expense lines across this many extra accounts}
        {--show= : Print this stage as a table (e.g. p25_combine)}
        {--seed-group : Seed the reporting-group oracle (a parent and two child entities) and run against its parent}
        {--seed-scale-group= : Seed a reporting group of --group-farms children, about this many lines each, and run against its parent}
        {--group-farms=10 : With --seed-scale-group, how many child entities}
        {--no-offsets : mergedAccounts off — pipe 11 passes through}
        {--no-consolidate : consolidateAccounts off — pipe 20 passes through}';

    protected $description = "Run Figured's DataPipeline as one statement and check every stage against the PHP transliteration";

    public function handle(DuckDB $db): int
    {
        $alias = config('duckdb.attached_alias');
        $appAlias = config('duckdb.app_database.alias');

        if ($this->option('seed')) {
            (new CashFlowSchema($db, $alias, $appAlias))->addTagColumn();
            $n = (new PipelineOracleSeeder($db, $alias))->seed();
            $this->line("  seeded ".PipelineOracleSeeder::FARM_ID." with {$n} lines");
            $this->line('');
        }

        $farmId = (string) ($this->option('farm') ?: PipelineOracleSeeder::FARM_ID);

        if ($this->option('seed-group')) {
            (new CashFlowSchema($db, $alias, $appAlias))->addTagColumn();
            $n = (new ReportingGroupSeeder($db, $alias))->seed();
            $farmId = ReportingGroupSeeder::PARENT;
            $this->line("  seeded {$farmId} (".implode(', ', ReportingGroupSeeder::CHILDREN).") with {$n} lines");
            $this->line('');
        }

        if ($this->option('seed-scale-group')) {
            (new CashFlowSchema($db, $alias, $appAlias))->addTagColumn();
            $farms = (int) $this->option('group-farms');
            $perFarm = (int) $this->option('seed-scale-group');
            $t = hrtime(true);
            $n = (new ReportingGroupScaleSeeder($db, $alias))->seed($farms, $perFarm);
            $farmId = ReportingGroupScaleSeeder::parentId($farms, $perFarm);
            $this->line(sprintf('  seeded %s: %d children, %s lines in %.1f s', $farmId, $farms, number_format($n), (hrtime(true) - $t) / 1e9));
            $this->line('');
        }

        if ($this->option('scale')) {
            $target = (int) $this->option('scale');
            (new CashFlowSchema($db, $alias, $appAlias))->addTagColumn();
            $t = hrtime(true);
            $extra = (int) $this->option('accounts');
            $n = (new PipelineScaleSeeder($db, $alias))->seed($target, $extra);
            $farmId = PipelineScaleSeeder::farmId($target).($extra > 0 ? '-'.$extra.'acc' : '');
            $this->line(sprintf('  seeded %s with %s lines in %.1f s', $farmId, number_format($n), (hrtime(true) - $t) / 1e9));
            $this->line('');
        }
        $from = (string) ($this->option('from') ?: PipelineOracleSeeder::PERIOD_FROM);
        $to = (string) ($this->option('to') ?: PipelineOracleSeeder::PERIOD_TO);
        $horizon = (string) ($this->option('horizon') ?: PipelineOracleSeeder::HORIZON);

        $all = (bool) $this->option('all');
        $options = new PipelineOptions(
            type: (string) $this->option('type'),
            basis: (string) $this->option('basis'),
            ytd: $all || (bool) $this->option('ytd'),
            ytdType: $this->option('ytd-type') ?: null,
            excludeEoyJournals: $all || (bool) $this->option('exclude-eoy'),
            includeOpeningBudgetGst: $all || (bool) $this->option('opening-gst'),
            calculateCurrentYearEarnings: $all || (bool) $this->option('cye'),
            calculateRetained: $all || (bool) $this->option('retained'),
            showExpectedSign: $all || (bool) $this->option('expected-sign'),
            inverse: (bool) $this->option('inverse'),
            dynamicBankAccount: $all || (bool) $this->option('dynamic-bank'),
            mergedAccounts: !$this->option('no-offsets'),
            consolidateAccounts: !$this->option('no-consolidate'),
        );
        $entities = ReportingGroupEntities::for($farmId);
        if ($entities !== []) {
            $options = $options->forReportingGroup($entities);
        }

        $this->info(sprintf('DataPipeline — %s, %s to %s (horizon %s), %s, %s', $farmId, $from, $to, $horizon, $options->type, $options->basis));
        $this->line('  gates: '.$this->gates($options));
        if ($entities !== []) {
            $this->line('  reporting group: '.implode(', ', $entities));
        }
        $this->line('');

        $check = new DataPipelineCheck($db, $alias, $appAlias);
        $group = $options->isReportingGroup();

        $builder = new DataPipelineSqlBuilder($alias, $appAlias, $options);

        $t = hrtime(true);
        $expected = (new DataPipelineOracle($db, $alias))->run($farmId, $from, $to, $horizon, $options);
        $oracleMs = (hrtime(true) - $t) / 1e6;

        $failed = 0;
        $sqlMs = 0.0;
        $this->line(sprintf('  %-20s %5s %8s  %s', 'stage', 'cells', 'ms', 'result'));

        foreach (DataPipelineSqlBuilder::STAGES as $stage) {
            try {
                $t = hrtime(true);
                $actual = $check->stage($builder, $stage, $farmId, $from, $to, $horizon, $group);
                $ms = (hrtime(true) - $t) / 1e6;
                $sqlMs += $ms;
            } catch (Throwable $e) {
                $this->error(sprintf('  %-20s %5s %8s  ERROR %s', $stage, '-', '-', substr($e->getMessage(), 0, 120)));
                $failed++;
                continue;
            }

            if ($stage === 'p02_scan' && $actual === []) {
                $this->error(sprintf('  %-20s %5d %8.1f  FAIL scan is empty — no lines in scope; nothing to check', $stage, 0, $ms));
                $failed++;
                continue;
            }

            $diff = $check->diff($actual, $expected[$stage] ?? [], DataPipelineCheck::isDeflated($stage));
            if ($diff === null) {
                $this->line(sprintf('  %-20s %5d %8.1f  pass', $stage, count($actual), $ms));
            } else {
                $failed++;
                $this->error(sprintf('  %-20s %5d %8.1f  FAIL %s', $stage, count($actual), $ms, $diff));
            }
        }

        $this->line('');
        $this->line(sprintf('  statement, all %d stages   %8.1f ms (sum of per-stage runs)', count(DataPipelineSqlBuilder::STAGES), $sqlMs));
        $this->line(sprintf('  PHP transliteration        %8.1f ms', $oracleMs));

        if ($this->option('show')) {
            $this->line('');
            $this->show($check->stage($builder, (string) $this->option('show'), $farmId, $from, $to, $horizon, $group));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function gates(PipelineOptions $o): string
    {
        $on = [];
        foreach ([
            'ytd' => $o->ytd, 'excludeEoy' => $o->excludeEoyJournals, 'openingGst' => $o->includeOpeningBudgetGst,
            'cye' => $o->calculateCurrentYearEarnings, 'retained' => $o->calculateRetained,
            'expectedSign' => $o->showExpectedSign, 'inverse' => $o->inverse, 'dynamicBank' => $o->dynamicBankAccount,
        ] as $name => $v) {
            if ($v) {
                $on[] = $name;
            }
        }

        return $on === [] ? '(none)' : implode(', ', $on);
    }

    private function show(array $stage): void
    {
        $idxs = [];
        foreach ($stage as $byIdx) {
            foreach (array_keys($byIdx) as $i) {
                $idxs[$i] = true;
            }
        }
        ksort($idxs);
        $header = sprintf('  %-22s', 'account').implode('', array_map(static fn ($i) => sprintf('%12s', "m{$i}"), array_keys($idxs)));
        $this->line($header);
        ksort($stage);
        foreach ($stage as $id => $byIdx) {
            $cells = '';
            foreach (array_keys($idxs) as $i) {
                $v = $byIdx[$i] ?? 0;
                $cells .= sprintf('%12s', is_float($v) ? number_format($v, 2) : number_format($v));
            }
            $this->line(sprintf('  %-22s', $id).$cells);
        }
    }
}
