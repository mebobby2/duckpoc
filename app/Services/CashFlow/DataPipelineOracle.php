<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;

/**
 * Figured's `DataPipeline`, transliterated pipe by pipe into PHP arrays —
 * the oracle `DataPipelineSqlBuilder` is checked against, one stage at a time.
 *
 * Kept in the shape of the original on purpose: the data is
 * `[account_id][interval_index] => amount`, each pipe is a method that takes
 * that array and returns it changed, and `run()` applies them in
 * `DataPipelineService::$pipes` order, keeping a snapshot after every one. A
 * stage's snapshot is what the command diffs the same-named CTE against, so
 * an ordering mistake in the SQL fails at the pipe that made it.
 *
 * Reads the farm's lines from the lake once and the dimensions from MySQL,
 * then does everything else in PHP. Checking SQL against SQL would only prove
 * the statement is self-consistent; the point is that this loop and that
 * statement agree.
 *
 * A reporting-group report runs the pipes once per child entity on the
 * parent's financial year, as Figured's per-child requests do, except that
 * pipe 11 needs every entity's pipe 10 first — so the entities go through
 * pipes 1–10 together, then 11, then 12–24, then `CombineReports`. A stage's
 * snapshot is then keyed `entity|account`.
 */
final class DataPipelineOracle
{
    private const int FIXED_POINT = 10000;

    /** @var list<array{account_id: string, date: string, amount: int, tag: ?string, type: string}> */
    private array $lines = [];

    /** @var array<string, array<string, mixed>> account_id => row */
    private array $accounts = [];

    /** @var list<array{interval_index: int, month_start: string, month_end: string, fy: int, is_fy_start: bool}> */
    private array $months = [];

    private int $fyEndMonth = 6;

    /** The entity whose state the pipes below are reading. */
    private string $entity = '';

    /**
     * @var array<string, array{lines: list<array<string, mixed>>, accounts: array<string, array<string, mixed>>}>
     */
    private array $state = [];

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
    ) {
    }

    /**
     * @return array<string, array<string, array<int, int|float>>> stage name => [account_id][interval_index] => amount
     */
    public function run(string $farmId, string $from, string $to, string $horizon, PipelineOptions $o): array
    {
        $entities = $o->isReportingGroup() ? $o->reportingGroupEntities : [$farmId];
        $this->fyEndMonth = (int) (DB::table('farms')->where('farm_id', $farmId)->value('financial_year_end_month') ?? 6);
        $this->buildMonths($from, $to);
        foreach ($entities as $entity) {
            $this->load($entity);
        }

        /** @var array<string, array<string, array<string, array<int, int|float>>>> $byEntity stage => entity => data */
        $byEntity = [];
        $data = [];
        $inner = $o->forOverdraftSubReport();
        $innerWithGst = [];
        $gstVjs = [];

        foreach ($entities as $e) {
            $this->use($e);
            $data[$e] = $this->p01Empty();
            $byEntity['p01_empty'][$e] = $data[$e];

            $scan = $this->scan($from, $to, $horizon, $o);
            foreach (['p02_scan', 'p03_mf_trackers', 'p04_mapped_in', 'p05_nesting', 'p06_query'] as $st) {
                $byEntity[$st][$e] = $this->scanAsCells($scan, $from);
            }

            $data[$e] = $this->cells($data[$e], $scan, $from);
            $byEntity['p07_cells'][$e] = $data[$e];

            $innerScan = $this->scan($from, $to, $horizon, $inner);
            $gstVjs[$e] = $this->gstPaymentsRefundsVj($innerScan);
            $innerWithGst[$e] = $this->addJournals($this->cells($this->p01Empty(), $innerScan, $from), $gstVjs[$e]);
        }

        // The overdraft sub-report is a pipeline of its own: in a reporting
        // group its pipes 11 and 20 run too, across the same entities.
        $innerCells = $this->consolidate($this->offsets($innerWithGst, $farmId, $inner), $farmId, $inner);

        foreach ($entities as $e) {
            $this->use($e);
            $innerCashflow = $this->innerCashFlow($innerCells[$e], $e, $from, $horizon);
            $odVj = $this->overdraftVj($innerCashflow, $e, $to);

            $data[$e] = $this->addJournals($this->addJournals($data[$e], $gstVjs[$e]), $odVj);
            $byEntity['p08_merge_vj'][$e] = $data[$e];
            $data[$e] = $this->openingBank($data[$e], $e, $o);
            $byEntity['p09_opening_bank'][$e] = $data[$e];
            $data[$e] = $this->openingGst($data[$e], $e, $o);
            $byEntity['p10_opening_gst'][$e] = $data[$e];
        }

        $data = $this->offsets($data, $farmId, $o);
        $byEntity['p11_offsets'] = $data;

        foreach ($entities as $e) {
            $this->use($e);
            $byEntity['p12_gst_payments'][$e] = $data[$e];
            $data[$e] = $this->mergeMapped($data[$e]);
            $byEntity['p13_merge_mapped'][$e] = $data[$e];
            $data[$e] = $this->currentYearEarnings($data[$e], $from, $to, $horizon, $o);
            $byEntity['p14_cye'][$e] = $data[$e];
            $data[$e] = $this->retainedEarnings($data[$e], $to, $horizon, $o);
            $byEntity['p15_retained'][$e] = $data[$e];
            $data[$e] = $this->ytd($data[$e], $o);
            $byEntity['p16_ytd'][$e] = $data[$e];
            $byEntity['p17_contra_gst'][$e] = $data[$e];
            $data[$e] = $this->expectedSign($data[$e], $o);
            $byEntity['p18_expected_sign'][$e] = $data[$e];
            $data[$e] = $this->inverse($data[$e], $o);
            $byEntity['p19_inverse'][$e] = $data[$e];
        }

        $data = $this->consolidate($data, $farmId, $o);
        $byEntity['p20_consolidate'] = $data;

        foreach ($entities as $e) {
            $this->use($e);
            $data[$e] = $this->dynamicBank($data[$e], $o);
            $byEntity['p21_dynamic_bank'][$e] = $data[$e];
            $byEntity['p22_hide_empty'][$e] = $data[$e];
            $byEntity['p23_hide_accounts'][$e] = $data[$e];
            $data[$e] = $this->format($data[$e]);
            $byEntity['p24_format'][$e] = $data[$e];
        }

        $snap = [];
        foreach ($byEntity as $stage => $perEntity) {
            $snap[$stage] = $this->keyed($perEntity, $o->isReportingGroup());
        }
        $snap['p25_combine'] = $this->combine($data);

        return $snap;
    }

    /**
     * One entity's data as the stage's table: keyed by account for a single
     * farm, `entity|account` for a reporting group, as the command keys the
     * statement's rows.
     *
     * @param array<string, array<string, array<int, int|float>>> $perEntity
     * @return array<string, array<int, int|float>>
     */
    private function keyed(array $perEntity, bool $group): array
    {
        $out = [];
        foreach ($perEntity as $entity => $accounts) {
            foreach ($accounts as $id => $byIdx) {
                $out[$group ? "{$entity}|{$id}" : $id] = $byIdx;
            }
        }

        return $out;
    }

    /**
     * `CombineReports`: the finished reports summed by account and interval.
     *
     * @param array<string, array<string, array<int, float>>> $data
     * @return array<string, array<int, float>>
     */
    private function combine(array $data): array
    {
        $out = [];
        foreach ($data as $accounts) {
            foreach ($accounts as $id => $byIdx) {
                foreach ($byIdx as $idx => $amt) {
                    $out[$id][$idx] = ($out[$id][$idx] ?? 0.0) + $amt;
                }
            }
        }

        return $out;
    }

    /**
     * Pipe 11: inter-entity transfers — the rule the builder's CTE states.
     *
     * @param array<string, array<string, array<int, int>>> $data entity => data
     * @return array<string, array<string, array<int, int>>>
     */
    private function offsets(array $data, string $parentId, PipelineOptions $o): array
    {
        if (!$o->isReportingGroup() || !$o->mergedAccounts) {
            return $data;
        }
        $transfers = DB::table('merged_accounts')->where('parent_farm_id', $parentId)
            ->whereIn('from_farm_id', $o->reportingGroupEntities)
            ->whereIn('to_farm_id', $o->reportingGroupEntities)
            ->get();

        $additions = [];
        $zero = [];
        foreach ($transfers as $t) {
            $from = (string) $t->from_farm_id;
            $fromAccount = (string) $t->from_account_id;
            if (!isset($data[$from][$fromAccount])) {
                continue;
            }
            foreach ($data[$from] as $id => $byIdx) {
                $mapped = $this->state[$from]['accounts'][$id]['mapped_to_account_id'] ?? null;
                if ($id !== $fromAccount && $mapped !== $fromAccount) {
                    continue;
                }
                $zero[$from][$id] = true;
                foreach ($byIdx as $idx => $amt) {
                    $additions[(string) $t->to_farm_id][(string) $t->to_account_id][$idx] =
                        ($additions[(string) $t->to_farm_id][(string) $t->to_account_id][$idx] ?? 0) + $amt;
                }
            }
        }

        foreach ($data as $entity => $accounts) {
            foreach ($accounts as $id => $byIdx) {
                foreach ($byIdx as $idx => $amt) {
                    $data[$entity][$id][$idx] = isset($zero[$entity][$id])
                        ? 0
                        : $amt + ($additions[$entity][$id][$idx] ?? 0);
                }
            }
        }

        return $data;
    }

    /**
     * Pipe 20: consolidated accounts — old zeroed, new gains its cells.
     *
     * @param array<string, array<string, array<int, int>>> $data entity => data
     * @return array<string, array<string, array<int, int>>>
     */
    private function consolidate(array $data, string $parentId, PipelineOptions $o): array
    {
        if (!$o->isReportingGroup() || !$o->consolidateAccounts) {
            return $data;
        }
        $consolidations = DB::table('consolidated_accounts')->where('parent_farm_id', $parentId)
            ->distinct()->get(['old_account_id', 'new_account_id']);

        foreach ($data as $entity => $accounts) {
            foreach ($consolidations as $c) {
                $old = (string) $c->old_account_id;
                $new = (string) $c->new_account_id;
                if (!isset($accounts[$old])) {
                    continue;
                }
                foreach ($data[$entity][$old] as $idx => $amt) {
                    $data[$entity][$new][$idx] = ($data[$entity][$new][$idx] ?? 0) + $amt;
                    $data[$entity][$old][$idx] = 0;
                }
            }
        }

        return $data;
    }

    /** Switch the pipes to one entity's lines and accounts. */
    private function use(string $entity): void
    {
        $this->entity = $entity;
        $this->lines = $this->state[$entity]['lines'];
        $this->accounts = $this->state[$entity]['accounts'];
    }

    private function load(string $farmId): void
    {
        $accounts = [];
        foreach (DB::table('accounts')->where(fn ($q) => $q->whereNull('farm_id')->orWhere('farm_id', $farmId))->orderBy('account_id')->get() as $a) {
            $accounts[(string) $a->account_id] = (array) $a;
        }

        // Lines are read pre-bucketed by (account, month, type, tag). Every
        // predicate the pipes apply — period, horizon, YTD start, the inner
        // report's "before the period" — falls on a month boundary in this
        // PoC, so the bucket carries everything a line would, and pipe 7's
        // SUM is the only thing that has happened to it. A million lines
        // become a few thousand rows and the transliteration stays a
        // transliteration at volume.
        $lines = [];
        $sql = sprintf(
            "SELECT account_id, CAST(date_trunc('month', date) AS VARCHAR) AS date, SUM(amount) AS amount, tag, type
             FROM %s.transaction_lines WHERE farm_id = '%s'
             GROUP BY 1, 2, 4, 5 ORDER BY 2, 1",
            $this->alias,
            str_replace("'", "''", $farmId),
        );
        foreach ($this->db->query($sql)->rows(true) as $r) {
            $lines[] = [
                'account_id' => (string) $r['account_id'],
                'date' => substr((string) $r['date'], 0, 10),
                'amount' => (int) (string) $r['amount'],
                'tag' => $r['tag'] === null ? null : (string) $r['tag'],
                'type' => (string) $r['type'],
            ];
        }

        $this->state[$farmId] = ['lines' => $lines, 'accounts' => $accounts];
    }

    /** The month spine, on the report farm's financial year. */
    private function buildMonths(string $from, string $to): void
    {
        $this->months = [];
        $cursor = strtotime(substr($from, 0, 7).'-01');
        $end = strtotime(substr($to, 0, 7).'-01');
        $i = 0;
        while ($cursor <= $end) {
            $i++;
            $ms = date('Y-m-d', $cursor);
            $this->months[] = [
                'interval_index' => $i,
                'month_start' => $ms,
                'month_end' => date('Y-m-t', $cursor),
                'fy' => $this->fyOf($ms),
                'is_fy_start' => (int) date('n', $cursor) === ($this->fyEndMonth % 12) + 1,
            ];
            $cursor = strtotime($ms.' +1 month');
        }
    }

    private function fyOf(string $date): int
    {
        $y = (int) substr($date, 0, 4);
        $m = (int) substr($date, 5, 2);

        return $y + ($m > $this->fyEndMonth ? 1 : 0);
    }

    private function fyStartOf(string $date): string
    {
        $y = (int) substr($date, 0, 4);
        $m = (int) substr($date, 5, 2);
        $startMonth = ($this->fyEndMonth % 12) + 1;

        return sprintf('%04d-%02d-01', $m > $this->fyEndMonth ? $y : $y - 1, $startMonth);
    }

    /** @return list<string> */
    private function reportAccounts(): array
    {
        $ids = [];
        foreach ($this->lines as $l) {
            $ids[$l['account_id']] = true;
            $mapped = $this->accounts[$l['account_id']]['mapped_to_account_id'] ?? null;
            if ($mapped !== null) {
                $ids[(string) $mapped] = true;
            }
        }
        foreach ($this->systemAccounts() as $id) {
            $ids[$id] = true;
        }
        ksort($ids);

        return array_keys($ids);
    }

    /** @return array<string, array<int, int>> */
    private function p01Empty(): array
    {
        $data = [];
        foreach ($this->reportAccounts() as $id) {
            foreach ($this->months as $m) {
                $data[$id][$m['interval_index']] = 0;
            }
        }

        return $data;
    }

    /**
     * Pipes 2–6.
     *
     * @return list<array{account_id: string, date: string, amount: int, tag: ?string, type: string}>
     */
    private function scan(string $from, string $to, string $horizon, PipelineOptions $o): array
    {
        $start = $o->ytd ? $this->fyStartOf($from) : $from;

        return array_values(array_filter($this->lines, function (array $l) use ($start, $to, $horizon, $o): bool {
            if ($l['date'] < $start || $l['date'] > $to) {
                return false;
            }
            $inHorizon = $l['date'] <= $horizon ? $l['type'] === 'actuals' : $l['type'] === 'forecast';
            if (!$inHorizon) {
                return false;
            }
            if ($o->excludeEoyJournals && $l['tag'] === DataPipelineSqlBuilder::TAG_EOY) {
                return false;
            }

            return true;
        }));
    }

    private function intervalOf(string $date): int
    {
        foreach ($this->months as $m) {
            if ($date >= $m['month_start'] && $date <= $m['month_end']) {
                return $m['interval_index'];
            }
        }

        return 1;
    }

    /** The scan as a cells table, for diffing the p02–p06 CTEs. */
    private function scanAsCells(array $scan, string $from): array
    {
        return $this->cells([], $scan, $from);
    }

    /** Pipe 7. */
    private function cells(array $empty, array $scan, string $from): array
    {
        $data = $empty;
        foreach ($scan as $l) {
            $idx = $l['date'] < $from ? 1 : $this->intervalOf($l['date']);
            $data[$l['account_id']][$idx] = ($data[$l['account_id']][$idx] ?? 0) + $l['amount'];
        }

        return $data;
    }

    /**
     * The entity's account of one system kind: its own before a global one,
     * then the lowest id — the builder's `sys_accounts` rule.
     */
    private function systemAccount(string $system): ?string
    {
        return $this->systemAccounts()[$system] ?? null;
    }

    /** @return array<string, string> system kind => account id */
    private function systemAccounts(): array
    {
        $chosen = [];
        foreach ([true, false] as $farmSpecific) {
            foreach ($this->accounts as $id => $a) {
                $kind = $a['system_account'] ?? null;
                if ($kind === null || isset($chosen[$kind]) || (($a['farm_id'] ?? null) !== null) !== $farmSpecific) {
                    continue;
                }
                $chosen[(string) $kind] = (string) $id;
            }
        }

        return $chosen;
    }

    /**
     * The default bank among the entity's report accounts, its own first —
     * the builder's `entity_accounts.bank_id` rule.
     */
    private function defaultBank(): ?string
    {
        $candidates = array_values(array_filter(
            $this->reportAccounts(),
            fn (string $id): bool => (bool) ($this->accounts[$id]['is_default_bank_account'] ?? false),
        ));
        usort($candidates, fn (string $a, string $b): int => [($this->accounts[$a]['farm_id'] ?? null) === null, $a]
            <=> [($this->accounts[$b]['farm_id'] ?? null) === null, $b]);

        return $candidates[0] ?? null;
    }

    /**
     * The GST payments/refunds handler, the same rule as the builder's CTE.
     *
     * @return list<array{account_id: string, date: string, amount: int}>
     */
    private function gstPaymentsRefundsVj(array $innerScan): array
    {
        $gst = $this->systemAccount('GST');
        $payments = $this->systemAccount('GSTPAYMENTS');
        if ($gst === null || $payments === null) {
            return [];
        }

        $netByMonth = [];
        $settlements = [];
        foreach ($innerScan as $l) {
            if ($l['account_id'] !== $gst) {
                continue;
            }
            if ($l['tag'] === DataPipelineSqlBuilder::TAG_GST_PAYMENT) {
                $settlements[] = $l;
            } else {
                $ms = substr($l['date'], 0, 7).'-01';
                $netByMonth[$ms] = ($netByMonth[$ms] ?? 0) + $l['amount'];
            }
        }

        $journals = [];
        $oddIsPayment = (($this->fyEndMonth + 1) % 2);
        foreach ($this->months as $m) {
            $mon = (int) substr($m['month_start'], 5, 2);
            if (($mon % 2) !== $oddIsPayment) {
                continue;
            }
            $year = (int) substr($m['month_start'], 0, 4);
            $payDate = sprintf('%04d-%02d-28', $year, $mon);
            if ($mon === 1 && $this->fyEndMonth % 2 === 1) {
                $payDate = sprintf('%04d-01-15', $year);
            }
            if ($mon === 5 && $this->fyEndMonth % 2 === 1) {
                $payDate = sprintf('%04d-05-07', $year);
            }
            $windowStart = date('Y-m-d', strtotime($m['month_start'].' -2 month'));
            $windowEnd = date('Y-m-d', strtotime($m['month_start'].' -1 day'));

            $net = 0;
            foreach ($netByMonth as $ms => $v) {
                if ($ms >= $windowStart && $ms <= $windowEnd) {
                    $net += $v;
                }
            }
            $settled = 0;
            foreach ($settlements as $s) {
                if ($s['date'] >= $windowStart && $s['date'] <= $windowEnd) {
                    $settled += $s['amount'];
                }
            }
            $amount = -$net - $settled;
            if ($amount !== 0) {
                $journals[] = ['account_id' => $payments, 'date' => $payDate, 'amount' => $amount];
            }
        }

        foreach ($settlements as $s) {
            $journals[] = ['account_id' => $gst, 'date' => $s['date'], 'amount' => -$s['amount']];
            $journals[] = ['account_id' => $payments, 'date' => $s['date'], 'amount' => $s['amount']];
        }

        return $journals;
    }

    /** `MergeVirtualJournals` for one handler's journals. */
    private function addJournals(array $data, array $journals): array
    {
        $first = $this->months[0]['month_start'];
        $last = $this->months[count($this->months) - 1]['month_end'];
        foreach ($journals as $j) {
            if ($j['date'] < $first || $j['date'] > $last) {
                continue;
            }
            $idx = $this->intervalOf($j['date']);
            $data[$j['account_id']][$idx] = ($data[$j['account_id']][$idx] ?? 0) + $j['amount'];
        }

        return $data;
    }

    /**
     * The overdraft handler's sub-report: income − expense + gst, bank
     * excluded; opening from the bank accounts' own lines before the period.
     *
     * @return list<array{interval_index: int, month_end: string, closing: int}>
     */
    private function innerCashFlow(array $cells, string $farmId, string $from, string $horizon): array
    {
        $opening = 0;
        foreach ($this->lines as $l) {
            $a = $this->accounts[$l['account_id']] ?? null;
            if ($a === null || ($a['account_type'] ?? null) !== 'BANK' || $l['date'] >= $from) {
                continue;
            }
            $inHorizon = $l['date'] <= $horizon ? $l['type'] === 'actuals' : $l['type'] === 'forecast';
            if ($inHorizon) {
                $opening += $l['amount'];
            }
        }

        $rows = [];
        $running = $opening;
        foreach ($this->months as $m) {
            $idx = $m['interval_index'];
            $income = 0;
            $expense = 0;
            $gst = 0;
            foreach ($cells as $id => $byIdx) {
                $amt = $byIdx[$idx] ?? 0;
                $a = $this->accounts[$id] ?? [];
                $isGst = (bool) ($a['is_gst_account'] ?? false) || in_array($a['system_account'] ?? '', ['GST', 'GSTPAYMENTS'], true);
                if (($a['account_class'] ?? '') === 'REVENUE') {
                    $income += $amt;
                } elseif ($isGst) {
                    $gst += $amt;
                } elseif (($a['account_type'] ?? null) !== 'BANK') {
                    $expense += $amt;
                }
            }
            $movement = -$income - $expense - $gst;
            $running += $movement;
            $rows[] = ['interval_index' => $idx, 'month_end' => $m['month_end'], 'closing' => $running];
        }

        return $rows;
    }

    /**
     * Phase 3's recurrence over the inner closing row: cum carries full
     * precision, the posted amount is floored — as the SQL does.
     *
     * @return list<array{account_id: string, date: string, amount: int}>
     */
    private function overdraftVj(array $cashflow, string $farmId, string $to): array
    {
        $od = DB::table('overdrafts')->where('farm_id', $farmId)->where('start_date', '<=', $to)->orderByDesc('start_date')->first();
        $account = $this->systemAccount('OVERDRAFT');
        if ($od === null || $account === null) {
            return [];
        }
        $monthly = ((int) $od->rate / 10000.0 / 100.0) / 12.0;

        $journals = [];
        $cum = 0.0;
        foreach ($cashflow as $row) {
            $principal = $row['closing'] - $cum;
            $interest = $principal < 0 ? -$principal * $monthly : 0.0;
            $cum += $interest;
            if ($interest > 0) {
                $journals[] = ['account_id' => $account, 'date' => $row['month_end'], 'amount' => (int) floor($interest)];
            }
        }

        return $journals;
    }

    /** Pipe 9. */
    private function openingBank(array $data, string $farmId, PipelineOptions $o): array
    {
        if ($o->type !== 'budget') {
            return $data;
        }
        $bank = $this->defaultBank();
        $re = $this->systemAccount('RETAINED_EARNINGS');
        $seen = [];
        foreach ($this->months as $m) {
            if (isset($seen[$m['fy']])) {
                continue;
            }
            $seen[$m['fy']] = true;
            $ob = DB::table('opening_balances')->where('farm_id', $farmId)->where('financial_year', $m['fy'])->value('opening_bank') ?? 0;
            if ($bank !== null && isset($data[$bank])) {
                $data[$bank][$m['interval_index']] += (int) $ob;
            }
            if ($re !== null && isset($data[$re])) {
                $data[$re][$m['interval_index']] -= (int) $ob;
            }
        }

        return $data;
    }

    /** Pipe 10. */
    private function openingGst(array $data, string $farmId, PipelineOptions $o): array
    {
        if ($o->type !== 'budget' || !$o->ytd || !$o->includeOpeningBudgetGst) {
            return $data;
        }
        $gst = $this->systemAccount('GST');
        $re = $this->systemAccount('RETAINED_EARNINGS');
        foreach ($this->months as $m) {
            if (!$m['is_fy_start']) {
                continue;
            }
            $og = DB::table('opening_balances')->where('farm_id', $farmId)->where('financial_year', $m['fy'])->value('opening_gst') ?? 0;
            if ($gst !== null && isset($data[$gst])) {
                $data[$gst][$m['interval_index']] += (int) $og;
            }
            if ($re !== null && isset($data[$re])) {
                $data[$re][$m['interval_index']] -= (int) $og;
            }
        }

        return $data;
    }

    /** Pipe 13. */
    private function mergeMapped(array $data): array
    {
        foreach (array_keys($data) as $id) {
            $target = $this->accounts[$id]['mapped_to_account_id'] ?? null;
            if ($target === null) {
                continue;
            }
            foreach ($data[$id] as $idx => $amt) {
                $data[$target][$idx] = ($data[$target][$idx] ?? 0) + $amt;
            }
            unset($data[$id]);
        }

        return $data;
    }

    /** Pipe 14. */
    private function currentYearEarnings(array $data, string $from, string $to, string $horizon, PipelineOptions $o): array
    {
        if (!$o->calculateCurrentYearEarnings) {
            return $data;
        }
        $cye = $this->systemAccount('CURRENT_YEAR_EARNINGS');
        if ($cye === null || !isset($data[$cye])) {
            return $data;
        }
        $scan = $this->scan($from, $to, $horizon, $o->withYtd());
        $income = [];
        $expenses = [];
        foreach ($scan as $l) {
            $idx = $l['date'] < $from ? 1 : $this->intervalOf($l['date']);
            $class = $this->accounts[$l['account_id']]['account_class'] ?? '';
            if ($class === 'REVENUE') {
                $income[$idx] = ($income[$idx] ?? 0) + $l['amount'];
            } elseif ($class === 'EXPENSE') {
                $expenses[$idx] = ($expenses[$idx] ?? 0) + $l['amount'];
            }
        }
        $runIncome = 0;
        $runExpense = 0;
        foreach ($this->months as $m) {
            $idx = $m['interval_index'];
            $runIncome += $income[$idx] ?? 0;
            $runExpense += $expenses[$idx] ?? 0;
            $data[$cye][$idx] = -($runIncome - $runExpense);
        }

        return $data;
    }

    /** Pipe 15. */
    private function retainedEarnings(array $data, string $to, string $horizon, PipelineOptions $o): array
    {
        if (!$o->calculateRetained) {
            return $data;
        }
        $re = $this->systemAccount('RETAINED_EARNINGS');
        if ($re === null || !isset($data[$re])) {
            return $data;
        }
        $seasonProfit = [];
        foreach ($this->lines as $l) {
            if ($l['date'] > $to) {
                continue;
            }
            $inHorizon = $l['date'] <= $horizon ? $l['type'] === 'actuals' : $l['type'] === 'forecast';
            if (!$inHorizon) {
                continue;
            }
            if ($o->excludeEoyJournals && $l['tag'] === DataPipelineSqlBuilder::TAG_EOY) {
                continue;
            }
            $class = $this->accounts[$l['account_id']]['account_class'] ?? '';
            $season = $this->fyOf($l['date']);
            if ($class === 'REVENUE') {
                $seasonProfit[$season] = ($seasonProfit[$season] ?? 0) + $l['amount'];
            } elseif ($class === 'EXPENSE') {
                $seasonProfit[$season] = ($seasonProfit[$season] ?? 0) - $l['amount'];
            }
        }
        $current = 0;
        foreach ($this->months as $m) {
            $idx = $m['interval_index'];
            $retained = 0;
            foreach ($seasonProfit as $season => $np) {
                if ($season < $m['fy']) {
                    $retained += $np;
                }
            }
            $current += $data[$re][$idx];
            $data[$re][$idx] = (int) ($current - $retained);
        }

        return $data;
    }

    /** Pipe 16. */
    private function ytd(array $data, PipelineOptions $o): array
    {
        if (!$o->ytd) {
            return $data;
        }
        $skip = array_filter([$this->systemAccount('RETAINED_EARNINGS'), $this->systemAccount('CURRENT_YEAR_EARNINGS')]);
        foreach ($data as $id => $byIdx) {
            if (in_array($id, $skip, true)) {
                continue;
            }
            $running = 0;
            $lastFy = null;
            foreach ($this->months as $m) {
                if ($o->ytdType === 'season' && $lastFy !== null && $m['fy'] !== $lastFy) {
                    $running = 0;
                }
                $lastFy = $m['fy'];
                $running += $byIdx[$m['interval_index']] ?? 0;
                $data[$id][$m['interval_index']] = $running;
            }
        }

        return $data;
    }

    /** Pipe 18. */
    private function expectedSign(array $data, PipelineOptions $o): array
    {
        if (!$o->showExpectedSign) {
            return $data;
        }
        foreach ($data as $id => $byIdx) {
            if ($this->accounts[$id]['inverted_for_user'] ?? false) {
                foreach ($byIdx as $idx => $amt) {
                    $data[$id][$idx] = -$amt;
                }
            }
        }

        return $data;
    }

    /** Pipe 19. */
    private function inverse(array $data, PipelineOptions $o): array
    {
        if (!$o->inverse) {
            return $data;
        }
        foreach ($data as $id => $byIdx) {
            foreach ($byIdx as $idx => $amt) {
                $data[$id][$idx] = -$amt;
            }
        }

        return $data;
    }

    /** Pipe 21. */
    private function dynamicBank(array $data, PipelineOptions $o): array
    {
        if (!$o->dynamicBankAccount || $o->isReportingGroup()) {
            return $data;
        }
        $bank = $this->defaultBank();
        $liability = $this->systemAccount('LIABILITY');
        if ($bank === null || $liability === null || !isset($data[$bank], $data[$liability])) {
            return $data;
        }
        foreach ($this->months as $m) {
            $idx = $m['interval_index'];
            $v = $data[$bank][$idx];
            if ($v < 0) {
                $data[$bank][$idx] = 0;
                $data[$liability][$idx] = -$v;
            } else {
                $data[$liability][$idx] = 0;
            }
        }

        return $data;
    }

    /** Pipe 24. */
    private function format(array $data): array
    {
        foreach ($data as $id => $byIdx) {
            foreach ($byIdx as $idx => $amt) {
                $data[$id][$idx] = $amt / self::FIXED_POINT;
            }
        }

        return $data;
    }
}
