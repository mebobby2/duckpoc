<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Overdraft interest — duckpoc</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="mx-auto max-w-6xl px-6 py-8">

    @php
        $money = static function (?float $v, int $dp = 2): string {
            if ($v === null) { return '—'; }
            return $v < 0 ? '(' . number_format(abs($v), $dp) . ')' : number_format($v, $dp);
        };
    @endphp

    <header class="mb-6">
        <h1 class="text-2xl font-semibold">Overdraft interest</h1>
        <p class="mt-1 text-sm text-slate-600">
            Phase 3 — the first report here that a window function cannot express. Interest is
            charged on a balance that <strong>excludes interest</strong>, so the running total of
            what has already accrued has to be subtracted to find the true overdrawn position.
            Month N's interest raises month N+1's charge base.
        </p>
        <p class="mt-2 text-sm">
            <a href="{{ route('reports') }}" class="text-blue-700 underline">← all reports</a>
        </p>
    </header>

    <form method="GET" class="mb-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
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

    {{-- settings write to the overdrafts table, which is where the SQL reads them --}}
    <form method="POST" action="{{ route('overdraft.save') }}" class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-5 shadow-sm">
        @csrf
        <input type="hidden" name="farm_id" value="{{ $farmId }}">
        <input type="hidden" name="period_from" value="{{ $periodFrom }}">
        <input type="hidden" name="period_to" value="{{ $periodTo }}">
        <input type="hidden" name="horizon" value="{{ $horizon }}">
        <p class="mb-3 text-xs text-slate-600">
            The rate and term live in the <code class="rounded bg-white px-1">overdrafts</code> table —
            the SQL reads them there rather than taking them as parameters, so changing them is a write.
        </p>
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
                <button type="submit" class="w-full rounded bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
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

    @if ($elapsedMs !== null)
        @php
            $totalAccrued = 0.0; $totalPosted = 0.0;
            foreach ($rows as $r) {
                $totalAccrued += (float) (string) $r['interest_accrued'];
                if ($r['interest_posted'] !== null) { $totalPosted += (float) (string) $r['interest_posted']; }
            }
            $conserved = abs($totalAccrued - $totalPosted) < 0.005;
        @endphp

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Query time</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($elapsedMs, 0) }} ms</p>
                <p class="mt-1 text-xs text-slate-500">1 statement, recursive CTE</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Interest accrued</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $money($totalAccrued) }}</p>
                <p class="mt-1 text-xs text-slate-500">every month, whatever the term</p>
            </div>
            <div class="rounded-lg border {{ $conserved ? 'border-slate-200 bg-white' : 'border-red-300 bg-red-50' }} p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide {{ $conserved ? 'text-slate-500' : 'text-red-700' }}">Interest posted</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $money($totalPosted) }}</p>
                <p class="mt-1 text-xs {{ $conserved ? 'text-slate-500' : 'text-red-700' }}">
                    {{ $conserved ? 'conserved — nothing dropped' : 'LOST ' . $money($totalAccrued - $totalPosted) }}
                </p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Page load</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums" data-page-ms>&mdash;</p>
                <p class="mt-1 text-xs text-slate-500">
                    @if ($serverMs !== null) server {{ number_format($serverMs, 0) }} ms @endif
                </p>
            </div>
        </div>
    @endif

    @if (!empty($rows))
        <div class="mb-8 overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100 text-slate-600">
                <tr>
                    <th class="px-3 py-2 text-left font-medium">Month</th>
                    <th class="px-3 py-2 text-right font-medium">Closing balance<br><span class="text-xs font-normal">before interest</span></th>
                    <th class="px-3 py-2 text-right font-medium">Interest accrued</th>
                    <th class="px-3 py-2 text-right font-medium">Interest posted</th>
                    @if ($isOracleFarm)
                        <th class="px-3 py-2 text-right font-medium">Figured<br><span class="text-xs font-normal">accrual oracle</span></th>
                        <th class="px-3 py-2 text-center font-medium">Parity</th>
                    @endif
                </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @foreach ($rows as $i => $r)
                    @php
                        $closing = (float) (string) $r['closing_before_interest'];
                        $accrued = (float) (string) $r['interest_accrued'];
                        $posted = $r['interest_posted'] === null ? null : (float) (string) $r['interest_posted'];
                        $expected = $oracle[$i] ?? null;
                        $matches = $expected !== null && (int) round($accrued * 10000) === $expected;
                    @endphp
                    <tr class="{{ $posted !== null ? 'bg-blue-50/40' : '' }}">
                        <td class="px-3 py-1.5 whitespace-nowrap">{{ $r['month'] }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums {{ $closing < 0 ? 'text-red-700' : '' }}">{{ $money($closing) }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ $money($accrued, 4) }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums font-medium">{{ $posted === null ? '—' : $money($posted, 4) }}</td>
                        @if ($isOracleFarm)
                            <td class="px-3 py-1.5 text-right tabular-nums text-slate-500">
                                {{ $expected === null ? '—' : number_format($expected / 10000, 4) }}
                            </td>
                            <td class="px-3 py-1.5 text-center">
                                @if ($expected === null)
                                    <span class="text-slate-400">—</span>
                                @elseif ($matches)
                                    <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-800">match</span>
                                @else
                                    <span class="rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">differs</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="mb-6 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600 shadow-sm">
            <span class="font-medium text-slate-900">What to look for:</span>
            the closing balance is flat on the oracle farm — one expense, nothing afterwards — yet the
            interest rises every month. Nothing else in the data is changing, so that growth is
            interest compounding on itself. Shaded rows are posting months; change the term above and
            the accrual stays identical while the posting months move, and the totals must still agree.
        </div>
    @endif

    @if ($elapsedMs !== null)
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
                @if (empty($inScope) && !empty($rows))
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
