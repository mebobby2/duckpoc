<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Overdraft interest — duckpoc</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="mx-auto max-w-7xl px-6 py-8">

    @php
        $money = static function (?float $v, int $dp = 2): string {
            if ($v === null) { return '—'; }
            return $v < 0 ? '(' . number_format(abs($v), $dp) . ')' : number_format($v, $dp);
        };
    @endphp

    <header class="mb-6">
        <h1 class="text-2xl font-semibold">Overdraft interest</h1>
        <p class="mt-1 text-sm text-slate-600">
            Interest charged on the overdrawn cash position, month by month, posted per the repayment term.
        </p>
        <p class="mt-2 text-sm">
            <a href="{{ route('reports') }}" class="text-blue-700 underline">← all reports</a>
        </p>
    </header>

    <form method="GET" class="mb-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <input type="hidden" name="pipeline_type" value="{{ $options->type }}">
        @foreach (['ytd' => $options->ytd, 'exclude_eoy' => $options->excludeEoyJournals, 'opening_gst' => $options->includeOpeningBudgetGst, 'cye' => $options->calculateCurrentYearEarnings, 'retained' => $options->calculateRetained, 'expected_sign' => $options->showExpectedSign, 'dynamic_bank' => $options->dynamicBankAccount] as $k => $v)
            <input type="hidden" name="{{ $k }}" value="{{ $v ? 1 : 0 }}">
        @endforeach
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-700">Farm</span>
                <select name="farm_id" class="w-full rounded border-slate-300 text-sm">
                    @foreach ($farms as $f)
                        <option value="{{ $f['farm_id'] }}" @selected($f['farm_id'] === $farmId)>{{ $f['farm_id'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-700">Period from</span>
                <input type="date" name="period_from" value="{{ $periodFrom }}" class="w-full rounded border-slate-300 text-sm">
            </label>
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-700">Period to</span>
                <input type="date" name="period_to" value="{{ $periodTo }}" class="w-full rounded border-slate-300 text-sm">
            </label>
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-700">Horizon</span>
                <input type="date" name="horizon" value="{{ $horizon }}" class="w-full rounded border-slate-300 text-sm">
            </label>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800">
                    Run report
                </button>
            </div>
        </div>
    </form>

    <form method="POST" action="{{ route('overdraft.save') }}" class="mb-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        @csrf
        <input type="hidden" name="farm_id" value="{{ $farmId }}">
        <input type="hidden" name="period_from" value="{{ $periodFrom }}">
        <input type="hidden" name="period_to" value="{{ $periodTo }}">
        <input type="hidden" name="horizon" value="{{ $horizon }}">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-700">Annual rate %</span>
                <input type="number" step="0.01" min="0" max="100" name="rate"
                       value="{{ $config ? number_format($config['rate'] / 10000, 2, '.', '') : '5.00' }}"
                       class="w-full rounded border-slate-300 text-sm">
            </label>
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-700">Repayment term</span>
                <select name="payment_term" class="w-full rounded border-slate-300 text-sm">
                    @foreach ($terms as $t)
                        <option value="{{ $t }}" @selected($config && $config['payment_term'] === $t)>{{ $t }}</option>
                    @endforeach
                </select>
            </label>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded bg-slate-700 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                    Save &amp; rerun
                </button>
            </div>
        </div>
    </form>

    @if ($error !== null)
        <div class="mb-6 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800">
            <strong class="font-semibold">Query failed:</strong> {{ $error }}
        </div>
    @endif

    @if ($pipeline !== null)
        @php
            $months = $pipeline['months'];
            $final = $pipeline['final'];
            $odKey = null;
            foreach (array_keys($final) as $acc) { if (str_contains($acc, 'od-interest')) { $odKey = $acc; } }
            $odRow = $odKey === null ? [] : $final[$odKey];
            // Under YTD the interest row is already a running total; otherwise the period's total is the sum.
            $odTotal = $odRow === [] ? 0.0 : ($options->ytd ? (float) end($odRow) : array_sum($odRow));
            $allPass = $pipeline['passed'] === count($pipeline['stages']);
        @endphp

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Overdraft interest</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $money($odTotal) }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ $periodFrom }} to {{ $periodTo }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Query time</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($pipeline['statement_ms'], 0) }} ms</p>
                <p class="mt-1 text-xs text-slate-500">one statement</p>
            </div>
            <div class="rounded-lg border {{ $allPass ? 'border-slate-200 bg-white' : 'border-red-300 bg-red-50' }} p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide {{ $allPass ? 'text-slate-500' : 'text-red-700' }}">Check</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $pipeline['passed'] }}/{{ count($pipeline['stages']) }}</p>
                <p class="mt-1 text-xs {{ $allPass ? 'text-slate-500' : 'text-red-700' }}">
                    @if ($serverMs !== null) page {{ number_format($serverMs, 0) }} ms @endif
                </p>
            </div>
        </div>

        {{-- the report: months as columns --}}
        <div class="mb-6 overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100 text-xs uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="sticky left-0 z-10 bg-slate-100 px-4 py-2 text-left"></th>
                        @foreach ($months as $m)<th class="px-3 py-2 text-right whitespace-nowrap">{{ $m }}</th>@endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr class="bg-amber-50 font-semibold">
                        <td class="sticky left-0 z-10 bg-amber-50 px-4 py-2">Overdraft interest</td>
                        @foreach ($months as $i => $m)
                            <td class="px-3 py-2 text-right tabular-nums">{{ $money((float) ($odRow[$i + 1] ?? 0)) }}</td>
                        @endforeach
                    </tr>
                    @foreach ($final as $acc => $cells)
                        @if ($acc === $odKey) @continue @endif
                        <tr>
                            <td class="sticky left-0 z-10 bg-white px-4 py-1.5 font-mono text-xs text-slate-600">{{ $acc }}</td>
                            @foreach ($months as $i => $m)
                                @php $v = (float) ($cells[$i + 1] ?? 0); @endphp
                                <td class="px-3 py-1.5 text-right tabular-nums {{ $v < 0 ? 'text-red-700' : '' }}">{{ $money($v) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- ---------- underneath ---------- --}}
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Options
                <span class="ml-2 font-normal text-slate-500">{{ $options->type }} · {{ collect(['ytd' => $options->ytd, 'excludeEoy' => $options->excludeEoyJournals, 'openingGst' => $options->includeOpeningBudgetGst, 'cye' => $options->calculateCurrentYearEarnings, 'retained' => $options->calculateRetained, 'expectedSign' => $options->showExpectedSign, 'dynamicBank' => $options->dynamicBankAccount])->filter()->keys()->implode(', ') ?: 'defaults' }}</span>
            </summary>
            <form method="GET" class="flex flex-wrap items-end gap-3 border-t border-slate-200 px-5 py-4 text-sm">
                <input type="hidden" name="farm_id" value="{{ $farmId }}">
                <input type="hidden" name="period_from" value="{{ $periodFrom }}">
                <input type="hidden" name="period_to" value="{{ $periodTo }}">
                <input type="hidden" name="horizon" value="{{ $horizon }}">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-slate-600">type</span>
                    <select name="pipeline_type" class="rounded border-slate-300 text-sm">
                        <option value="actualsForecast" @selected($options->type === 'actualsForecast')>actualsForecast</option>
                        <option value="budget" @selected($options->type === 'budget')>budget</option>
                    </select>
                </label>
                @foreach ([
                    'ytd' => ['ytd', $options->ytd], 'exclude_eoy' => ['excludeEoyJournals', $options->excludeEoyJournals],
                    'opening_gst' => ['includeOpeningBudgetGst', $options->includeOpeningBudgetGst], 'cye' => ['calculateCurrentYearEarnings', $options->calculateCurrentYearEarnings],
                    'retained' => ['calculateRetained', $options->calculateRetained], 'expected_sign' => ['showExpectedSign', $options->showExpectedSign],
                    'dynamic_bank' => ['dynamicBankAccount', $options->dynamicBankAccount],
                ] as $param => [$label, $on])
                    <label class="flex items-center gap-1.5 rounded border border-slate-200 px-2 py-1.5">
                        <input type="hidden" name="{{ $param }}" value="0">
                        <input type="checkbox" name="{{ $param }}" value="1" @checked($on) class="rounded border-slate-300">
                        <span class="font-mono text-xs">{{ $label }}</span>
                    </label>
                @endforeach
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-slate-600">ytd_type</span>
                    <select name="ytd_type" class="rounded border-slate-300 text-sm">
                        <option value="" @selected($options->ytdType === null)>—</option>
                        <option value="season" @selected($options->ytdType === 'season')>season</option>
                    </select>
                </label>
                <button type="submit" class="rounded bg-slate-700 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">Apply</button>
            </form>
        </details>

        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Pipeline stages
                <span class="ml-2 font-normal text-slate-500">{{ $pipeline['passed'] }}/{{ count($pipeline['stages']) }} match the transliteration · {{ number_format($pipeline['oracle_ms'], 0) }} ms oracle</span>
            </summary>
            @php
                $pipeLabels = [
                    'p01_empty' => 'PrepareEmptyArray', 'p02_scan' => 'BuildAggregationPipeline', 'p03_mf_trackers' => 'UpdatePipelineForV3MultiFarmTrackers',
                    'p04_mapped_in' => 'AddMappedAccountsToPipeline', 'p05_nesting' => 'CheckMaxNesting', 'p06_query' => 'QueryMongo',
                    'p07_cells' => 'AddResultsToEmptyArray', 'p08_merge_vj' => 'MergeVirtualJournals', 'p09_opening_bank' => 'AddOpeningBudgetBankBalance',
                    'p10_opening_gst' => 'AddOpeningBudgetGstBalance', 'p11_offsets' => 'ReportingGroupOffsetAccounts', 'p12_gst_payments' => 'AddGstPaymentsRefunds',
                    'p13_merge_mapped' => 'MergeMappedResults', 'p14_cye' => 'CurrentYearEarnings', 'p15_retained' => 'RetainedEarnings',
                    'p16_ytd' => 'FixYearToDateValues', 'p17_contra_gst' => 'ContraGstPaymentsRefunds', 'p18_expected_sign' => 'ShowExpectedSign',
                    'p19_inverse' => 'InverseAmounts', 'p20_consolidate' => 'ReportingGroupConsolidateAccounts', 'p21_dynamic_bank' => 'DynamicBankBalance',
                    'p22_hide_empty' => 'HideEmpty', 'p23_hide_accounts' => 'HideEmptyAccounts', 'p24_format' => 'FormatCells',
                ];
                $pipeNotes = [
                    'p03_mf_trackers' => 'pass-through', 'p11_offsets' => 'pass-through', 'p20_consolidate' => 'pass-through',
                    'p12_gst_payments' => 'disabled in Figured', 'p17_contra_gst' => 'disabled in Figured',
                    'p22_hide_empty' => 'display only', 'p23_hide_accounts' => 'display only',
                ];
                $gateOf = [
                    'p09_opening_bank' => $options->type === 'budget', 'p10_opening_gst' => $options->type === 'budget' && $options->ytd && $options->includeOpeningBudgetGst,
                    'p14_cye' => $options->calculateCurrentYearEarnings, 'p15_retained' => $options->calculateRetained, 'p16_ytd' => $options->ytd,
                    'p18_expected_sign' => $options->showExpectedSign, 'p19_inverse' => false, 'p21_dynamic_bank' => $options->dynamicBankAccount,
                ];
            @endphp
            <div class="overflow-x-auto border-t border-slate-200">
                <table class="min-w-full text-xs">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr><th class="px-3 py-2">#</th><th class="px-3 py-2">stage</th><th class="px-3 py-2">pipe</th><th class="px-3 py-2">gate</th><th class="px-3 py-2 text-right">cells</th><th class="px-3 py-2 text-right">ms</th><th class="px-3 py-2">result</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($pipeline['stages'] as $i => $st)
                            <tr class="{{ $st['ok'] ? '' : 'bg-red-50' }}">
                                <td class="px-3 py-1.5 tabular-nums text-slate-500">{{ $i + 1 }}</td>
                                <td class="px-3 py-1.5 font-mono">{{ $st['stage'] }}</td>
                                <td class="px-3 py-1.5">{{ $pipeLabels[$st['stage']] ?? '' }}@if (isset($pipeNotes[$st['stage']])) <span class="text-slate-400">— {{ $pipeNotes[$st['stage']] }}</span>@endif</td>
                                <td class="px-3 py-1.5">@if (array_key_exists($st['stage'], $gateOf))<span class="rounded px-1.5 py-0.5 {{ $gateOf[$st['stage']] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $gateOf[$st['stage']] ? 'on' : 'off' }}</span>@else<span class="text-slate-400">always</span>@endif</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ number_format($st['cells']) }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ number_format($st['ms'], 1) }}</td>
                                <td class="px-3 py-1.5 {{ $st['ok'] ? 'text-emerald-700' : 'font-medium text-red-700' }}">{{ $st['ok'] ? 'pass' : 'FAIL — ' . $st['detail'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>

        @php
            $bytes = static function (?int $b): string {
                if (!$b) { return '0 B'; }
                $u = ['B','KB','MB','GB','TB']; $i = (int) floor(log($b, 1024)); $i = min($i, 4);
                return number_format($b / (1024 ** $i), $i === 0 ? 0 : 1) . ' ' . $u[$i];
            };
            $inScope = array_values(array_filter($files, static fn (array $f): bool => (bool) $f['in_scope']));
            $outOfScope = count($files) - count($inScope);
            $inScopeBytes = array_sum(array_column($inScope, 'bytes'));
        @endphp

        {{-- ---------- source transactions ---------- --}}
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm" open>
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Source transactions
                <span class="ml-2 font-normal text-slate-500">
                    {{ number_format((int) ($sourceSummary['n'] ?? 0)) }} lines ·
                    net {{ $money((float) ($sourceSummary['net_cash'] ?? 0)) }}
                    @if ($sourceRowsMs !== null) · {{ number_format($sourceRowsMs, 0) }} ms @endif
                </span>
            </summary>
            <div class="border-t border-slate-200 px-5 py-4">
                <p class="mb-3 text-xs text-slate-600">
                    The journal lines behind the closing balance, read with the report's own scope
                    predicate. <strong>Cash effect</strong> is the negation the balance applies:
                    revenue is stored as a credit and expense as a debit, so one negation turns both
                    into cash movement. Getting that sign wrong inverts the balance and the interest
                    disappears — which is exactly how it was caught here.
                </p>

                @if (empty($sourceRows))
                    <p class="text-sm text-slate-500">No lines in scope for this period.</p>
                @else
                    <div class="mb-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
                        <div>
                            <p class="text-slate-500">Lines</p>
                            <p class="font-semibold tabular-nums">{{ number_format((int) ($sourceSummary['n'] ?? 0)) }}</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Accounts</p>
                            <p class="font-semibold tabular-nums">{{ number_format((int) ($sourceSummary['n_accounts'] ?? 0)) }}</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Span</p>
                            <p class="font-semibold tabular-nums">{{ $sourceSummary['first_date'] ?? '—' }} … {{ $sourceSummary['last_date'] ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Net cash</p>
                            <p class="font-semibold tabular-nums">{{ $money((float) ($sourceSummary['net_cash'] ?? 0)) }}</p>
                        </div>
                    </div>
                    <div class="max-h-96 overflow-auto rounded border border-slate-200">
                        <table class="min-w-full text-xs">
                            <thead class="sticky top-0 bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-2 py-1 text-left font-medium">Date</th>
                                <th class="px-2 py-1 text-left font-medium">Type</th>
                                <th class="px-2 py-1 text-left font-medium">Account</th>
                                <th class="px-2 py-1 text-left font-medium">Class</th>
                                <th class="px-2 py-1 text-right font-medium">Amount $</th>
                                <th class="px-2 py-1 text-right font-medium">Cash effect $</th>
                                <th class="px-2 py-1 text-left font-medium">Line</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            @foreach ($sourceRows as $r)
                                <tr>
                                    <td class="px-2 py-1 whitespace-nowrap tabular-nums">{{ $r['date'] }}</td>
                                    <td class="px-2 py-1">
                                        <span class="rounded px-1.5 py-0.5 {{ $r['type'] === 'actuals' ? 'bg-slate-100 text-slate-700' : 'bg-violet-100 text-violet-800' }}">{{ $r['type'] }}</span>
                                    </td>
                                    <td class="px-2 py-1 whitespace-nowrap">{{ $r['account_name'] }}</td>
                                    <td class="px-2 py-1 text-slate-500">{{ $r['account_class'] }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums">{{ number_format((float) (string) $r['amount_dollars'], 2) }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums {{ (float) (string) $r['cash_effect'] < 0 ? 'text-red-700' : 'text-emerald-700' }}">
                                        {{ number_format((float) (string) $r['cash_effect'], 2) }}
                                    </td>
                                    <td class="px-2 py-1 font-mono text-slate-400 whitespace-nowrap">{{ $r['line_id'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if (count($sourceRows) >= $sourceRowLimit)
                        <p class="mt-2 text-xs text-slate-500">First {{ number_format($sourceRowLimit) }} by date.</p>
                    @endif
                @endif
            </div>
        </details>

        {{-- ---------- parquet files ---------- ---}}
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Parquet files
                <span class="ml-2 font-normal text-slate-500">
                    {{ count($inScope) }} in scope of {{ count($files) }} · {{ $bytes($inScopeBytes) }}
                </span>
            </summary>
            <div class="border-t border-slate-200 px-5 py-4">
                <p class="mb-3 text-xs text-slate-600">
                    Every file DuckLake holds for this table, and whether the partition key put it in
                    scope. {{ $outOfScope }} pruned before a byte was read — partition pruning is
                    resolved from the catalog, so a pruned file costs nothing at all.
                </p>
                @if (empty($inScope) && $pipeline !== null)
                    <p class="mb-3 rounded border-l-4 border-sky-400 bg-sky-50 px-3 py-2 text-xs text-sky-900">
                        <strong>Nothing in scope, yet the report returned rows — this is expected
                        here.</strong> DuckLake keeps small writes inlined in the catalog rather than
                        writing a Parquet file for them, and the oracle farm is a single journal
                        line. So the numbers above came out of the catalog, and every file below
                        belongs to another farm and was correctly pruned.
                        <code class="rounded bg-white px-1">php artisan duckdb:cashflow:flush</code>
                        writes inlined rows out if you want this section to show something.
                    </p>
                @endif

                @if (empty($files))
                    <p class="text-sm text-slate-500">No files listed.</p>
                @else
                    <div class="max-h-80 overflow-auto rounded border border-slate-200">
                        <table class="min-w-full text-xs">
                            <thead class="sticky top-0 bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-2 py-1 text-left font-medium">Scope</th>
                                <th class="px-2 py-1 text-left font-medium">Table</th>
                                <th class="px-2 py-1 text-left font-medium">Partition</th>
                                <th class="px-2 py-1 text-right font-medium">Size</th>
                                <th class="px-2 py-1 text-left font-medium">File</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            @foreach ($files as $file)
                                <tr class="{{ $file['in_scope'] ? '' : 'text-slate-400' }}">
                                    <td class="px-2 py-1">
                                        @if ($file['in_scope'])
                                            <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-emerald-800">read</span>
                                        @else
                                            <span class="rounded bg-slate-100 px-1.5 py-0.5">pruned</span>
                                        @endif
                                    </td>
                                    <td class="px-2 py-1 font-mono">{{ $file['table'] }}</td>
                                    <td class="px-2 py-1 font-mono">{{ $file['partition'] }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums">{{ $bytes((int) $file['bytes']) }}</td>
                                    <td class="px-2 py-1 font-mono text-slate-400 whitespace-nowrap">{{ $file['name'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </details>

        {{-- ---------- storage requests ---------- --}}
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Storage requests
                <span class="ml-2 font-normal text-slate-500">
                    @if (($storage['predicted_requests'] ?? null) !== null)
                        ~{{ number_format((int) $storage['predicted_requests']) }} predicted
                    @else
                        not estimated
                    @endif
                    @if ($reportRequests !== null) · {{ number_format($reportRequests) }} connection events @endif
                </span>
            </summary>
            <div class="border-t border-slate-200 px-5 py-4">
                <p class="mb-3 text-xs text-slate-600">
                    DuckDB issues <strong>one HTTP range request per (row group × column)</strong>, so
                    request count rather than bytes is what a query over object storage costs. This is
                    the measure that made row group size the single biggest win in this PoC.
                </p>
                <div class="grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
                    <div><p class="text-slate-500">Files in scope</p><p class="font-semibold tabular-nums">{{ number_format((int) ($storage['files_in_scope'] ?? 0)) }}</p></div>
                    <div><p class="text-slate-500">Row groups</p><p class="font-semibold tabular-nums">{{ $storage['row_groups'] === null ? '—' : number_format((int) $storage['row_groups']) }}</p></div>
                    <div><p class="text-slate-500">Columns read</p><p class="font-semibold tabular-nums">{{ number_format((int) ($storage['columns_read'] ?? 0)) }}</p></div>
                    <div><p class="text-slate-500">Rows in files</p><p class="font-semibold tabular-nums">{{ $storage['rows_in_files'] === null ? '—' : number_format((int) $storage['rows_in_files']) }}</p></div>
                </div>
                @if (!empty($storage['note']))
                    <p class="mt-3 text-xs text-slate-500">{{ $storage['note'] }}</p>
                @endif
            </div>
        </details>

        {{-- ---------- duckdb trace ---------- --}}
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                DuckDB execution trace
                <span class="ml-2 font-normal text-slate-500">
                    @if ($trace === null)
                        not captured
                    @elseif (!$trace['ok'])
                        failed
                    @else
                        {{ count($trace['steps']) }} steps · {{ number_format($trace['total_ms'], 1) }} ms
                    @endif
                </span>
            </summary>
            <div class="border-t border-slate-200 px-5 py-4">
                @if ($trace === null)
                    <p class="text-sm text-slate-600">
                        <a href="{{ request()->fullUrlWithQuery(['trace' => 1]) }}" class="text-blue-700 underline">Trace this query</a>
                        — arms DuckDB's own log around the run. It is per-process, so it has to be
                        armed before the query and read immediately after.
                    </p>
                @elseif (!$trace['ok'])
                    <p class="text-sm text-red-700">{{ $trace['error'] }}</p>
                @elseif (empty($trace['steps']))
                    <p class="text-sm text-slate-500">No steps logged — the query answered from memory.</p>
                @else
                    <table class="min-w-full text-xs">
                        <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-2 py-1 text-left font-medium">Type</th>
                            <th class="px-2 py-1 text-right font-medium">Duration</th>
                            <th class="px-2 py-1 text-right font-medium">Share</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        @foreach ($trace['by_type'] as $t)
                            <tr>
                                <td class="px-2 py-1 font-mono">{{ $t['type'] ?? '?' }}</td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ number_format((float) ($t['ms'] ?? 0), 1) }} ms</td>
                                <td class="px-2 py-1 text-right tabular-nums text-slate-500">{{ number_format((float) ($t['pct'] ?? 0), 1) }}%</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </details>

        {{-- ---------- query profile ---------- --}}
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Query profile
                <span class="ml-2 font-normal text-slate-500">
                    @if ($profile === null)
                        not captured
                    @elseif (!$profile['ok'])
                        failed
                    @else
                        {{ number_format((float) $profile['total_seconds'] * 1000, 0) }} ms ·
                        {{ number_format((int) ($profile['http_gets'] ?? 0)) }} HTTP GETs
                    @endif
                </span>
            </summary>
            <div class="border-t border-slate-200 px-5 py-4">
                @if ($profile === null)
                    <p class="text-sm text-slate-600">
                        <a href="{{ request()->fullUrlWithQuery(['explain' => 1]) }}" class="text-blue-700 underline">Explain this query</a>
                        — reruns it under <code class="rounded bg-slate-100 px-1">EXPLAIN ANALYZE</code>,
                        so it costs a second execution.
                    </p>
                @elseif (!$profile['ok'])
                    <p class="text-sm text-red-700">{{ $profile['error'] }}</p>
                @else
                    <div class="mb-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
                        <div><p class="text-slate-500">Total</p><p class="font-semibold tabular-nums">{{ number_format((float) $profile['total_seconds'] * 1000, 1) }} ms</p></div>
                        <div><p class="text-slate-500">SQL</p><p class="font-semibold tabular-nums">{{ $profile['sql_seconds'] === null ? '—' : number_format((float) $profile['sql_seconds'] * 1000, 1) . ' ms' }}</p></div>
                        <div><p class="text-slate-500">HTTP GETs</p><p class="font-semibold tabular-nums">{{ number_format((int) ($profile['http_gets'] ?? 0)) }}</p></div>
                        <div><p class="text-slate-500">Bytes in</p><p class="font-semibold tabular-nums">{{ $bytes((int) ($profile['bytes_in'] ?? 0)) }}</p></div>
                    </div>
                    @if (!empty($profile['operators']))
                        <table class="min-w-full text-xs">
                            <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-2 py-1 text-left font-medium">Operator</th>
                                <th class="px-2 py-1 text-right font-medium">Time</th>
                                <th class="px-2 py-1 text-right font-medium">Rows</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            @foreach ($profile['operators'] as $op)
                                <tr>
                                    <td class="px-2 py-1 font-mono">{{ $op['name'] ?? '?' }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums">{{ number_format((float) ($op['seconds'] ?? 0) * 1000, 1) }} ms</td>
                                    <td class="px-2 py-1 text-right tabular-nums text-slate-500">{{ number_format((int) ($op['rows'] ?? 0)) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                    @if (!empty($profile['raw']))
                        <details class="mt-4">
                            <summary class="cursor-pointer text-xs font-medium text-slate-600">Full EXPLAIN ANALYZE output</summary>
                            <pre class="mt-2 overflow-x-auto rounded bg-slate-900 p-3 text-xs leading-relaxed text-slate-100">{{ $profile['raw'] }}</pre>
                        </details>
                    @endif
                @endif
            </div>
        </details>
    @endif

    @if ($sql !== null)
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium">
                SQL
                <span class="ml-2 font-normal text-slate-500">one statement, WITH RECURSIVE</span>
            </summary>
            <div class="border-t border-slate-200 px-4 py-3">
                <pre class="overflow-x-auto rounded bg-slate-900 p-3 text-xs leading-relaxed text-slate-100">{{ $sql }}</pre>
            </div>
        </details>
    @endif

    <script>
        (() => {
            const write = () => {
                const ms = Math.round(performance.now());
                document.querySelectorAll('[data-page-ms]').forEach((el) => {
                    el.textContent = ms.toLocaleString() + ' ms';
                });
            };
            if (document.readyState === 'complete') { write(); } else { window.addEventListener('load', write); }
        })();
    </script>
</div>
</body>
</html>
