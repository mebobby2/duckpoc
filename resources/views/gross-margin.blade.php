<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gross Margin — Trackers</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="mx-auto max-w-7xl px-6 py-8">

    @php
        $money = static function ($value): string {
            if ($value === null) { return '–'; }
            $v = (float) (string) $value;
            if (abs($v) < 0.005) { return '–'; }
            return $v < 0 ? '(' . number_format(abs($v), 2) . ')' : number_format($v, 2);
        };
        $head = static function ($value): string {
            if ($value === null) { return '–'; }
            $v = (float) (string) $value;
            return number_format($v, 0);
        };
    @endphp

    <header class="mb-6">
        <h1 class="text-2xl font-semibold">Gross Margin — per operating entity</h1>
        <p class="mt-1 text-sm text-slate-600">
            Income, direct costs and <strong>stock quantities</strong> per tracker, with margin
            expressed per head. Journals come from DuckLake/Parquet in GCS; trackers and stock
            movements from MySQL — resolved as one federated query.
        </p>
        <p class="mt-2 text-sm">
            <a href="{{ route('reports') }}" class="text-blue-700 underline">← all reports</a>
            <span class="mx-2 text-slate-300">|</span>
            <a href="{{ route('tracker-cashflow') }}" class="text-blue-700 underline">tracker Cash Flow</a>
        </p>
    </header>

    <form method="GET" class="mb-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Farm</span>
                @if (empty($farms))
                    <input type="text" name="farm_id" value="{{ $farmId }}" class="w-full rounded border-slate-300 text-sm shadow-sm">
                @else
                    <select name="farm_id" class="w-full rounded border-slate-300 text-sm shadow-sm">
                        @foreach ($farms as $option)
                            <option value="{{ $option['farm_id'] }}" @selected($option['farm_id'] === $farmId)>
                                {{ $option['farm_id'] }} ({{ $option['tracker_count'] }} tracker{{ $option['tracker_count'] == 1 ? '' : 's' }})
                            </option>
                        @endforeach
                    </select>
                @endif
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Period from</span>
                <input type="date" name="period_from" value="{{ $periodFrom }}" class="w-full rounded border-slate-300 text-sm shadow-sm">
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Period to</span>
                <input type="date" name="period_to" value="{{ $periodTo }}" class="w-full rounded border-slate-300 text-sm shadow-sm">
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Actuals horizon</span>
                <input type="date" name="horizon" value="{{ $horizon }}" class="w-full rounded border-slate-300 text-sm shadow-sm">
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Basis</span>
                <select name="basis" class="w-full rounded border-slate-300 text-sm shadow-sm">
                    @foreach (['cash', 'accrual'] as $option)
                        <option value="{{ $option }}" @selected($option === $basis)>{{ $option }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="mt-4">
            <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                Run report
            </button>
        </div>
    </form>

    @if ($error)
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-sm font-medium text-red-800">Query failed</p>
            <pre class="mt-2 overflow-x-auto whitespace-pre-wrap text-xs text-red-700">{{ $error }}</pre>
        </div>
    @endif

    @if ($farm !== null && $elapsedMs !== null)
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Operating entities</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($trackerCount) }}</p>
                <p class="mt-1 text-xs text-slate-500">trackers, from MySQL</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Report time</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($elapsedMs, 0) }} ms</p>
                <p class="mt-1 text-xs text-slate-500">1 query, all trackers</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Queries issued</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">1</p>
                <p class="mt-1 text-xs text-slate-500">Figured: 4 + {{ number_format($trackerCount * 2) }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Stock movement rows</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($movementRowCount) }}</p>
                <p class="mt-1 text-xs text-slate-500">whole history, for opening stock</p>
            </div>
        </div>

        <div class="mb-8 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600 shadow-sm">
            <span class="font-medium text-slate-900">Why 1 query, not {{ 4 + $trackerCount * 2 }}:</span>
            Figured's <code class="rounded bg-slate-100 px-1">Types::getTrackerQtys()</code> loops
            <code class="rounded bg-slate-100 px-1">foreach ($trackerGroups ...)</code> and calls
            <code class="rounded bg-slate-100 px-1">TrackerQuantityService</code> once per tracker — and
            <code class="rounded bg-slate-100 px-1">BaseGrossMargin</code> invokes it twice per report
            (lines 76 and 205), so {{ $trackerCount }} trackers means {{ $trackerCount * 2 }} quantity
            computations on top of the journal queries.
            <br>
            Here the running stock chain is one window function —
            <code class="rounded bg-slate-100 px-1">SUM(net_movement) OVER (PARTITION BY tracker_id ORDER BY month)</code>
            — which computes opening and closing stock for every tracker in a single pass. That is the
            operation a document store cannot express, and therefore the reason the current engine loops
            in PHP at all.
        </div>
    @endif

    @foreach ($trackers as $tracker)
        <h2 class="mb-2 mt-6 text-base font-semibold">
            {{ $tracker['tracker_name'] }}
            <span class="ml-1 text-sm font-normal text-slate-500">{{ $tracker['stock_type'] }}</span>
        </h2>
        <div class="mb-4 overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100">
                <tr>
                    <th class="px-3 py-2 text-left font-medium text-slate-600">Row</th>
                    @foreach ($months as $month)
                        <th class="px-3 py-2 text-right font-medium text-slate-600 whitespace-nowrap">{{ $month }}</th>
                    @endforeach
                </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @foreach ($reportRows as $definition)
                    <tr class="{{ $definition['isSubtotal'] ? 'bg-slate-50 font-semibold' : '' }}">
                        <td class="px-3 py-1.5 whitespace-nowrap">
                            {{ $definition['label'] }}
                            @if ($definition['kind'] === 'head')
                                <span class="ml-1 text-xs font-normal text-slate-400">qty</span>
                            @endif
                        </td>
                        @foreach ($months as $month)
                            @php
                                $cell = $tracker['months'][$month][$definition['field']] ?? null;
                                $numeric = $cell === null ? 0.0 : (float) (string) $cell;
                            @endphp
                            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap
                                       {{ $numeric < 0 ? 'text-red-700' : '' }}
                                       {{ $definition['kind'] === 'head' ? 'text-slate-600' : '' }}">
                                {{ $definition['kind'] === 'head' ? $head($cell) : $money($cell) }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endforeach

    {{-- Storage range requests — the measured cost driver --}}
    @if ($storage !== null)
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm" open>
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Storage requests
                <span class="ml-2 font-normal text-slate-500">
                    @if ($storage['connection_events'] !== null)
                        {{ number_format($storage['connection_events']) }} range requests
                    @endif
                    @if ($storage['row_groups'] !== null)
                        · {{ number_format($storage['row_groups']) }} row groups
                        across {{ number_format($storage['files_in_scope']) }} file(s)
                    @endif
                </span>
            </summary>

            <div class="border-t border-slate-200 px-5 py-4">
                <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-4">
                    <div class="rounded border border-slate-200 p-3">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Range requests</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums">
                            {{ $storage['connection_events'] !== null ? number_format($storage['connection_events']) : '—' }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">measured, this request</p>
                    </div>
                    <div class="rounded border border-slate-200 p-3">
                        <p class="text-xs uppercase tracking-wide text-slate-500">New TLS connections</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums">
                            {{ $storage['connection_misses'] !== null ? number_format($storage['connection_misses']) : '—' }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">rest reused the cache</p>
                    </div>
                    <div class="rounded border border-slate-200 p-3">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Row groups read</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums">
                            {{ $storage['row_groups'] !== null ? number_format($storage['row_groups']) : '—' }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">
                            in {{ number_format($storage['files_in_scope']) }} in-scope file(s)
                        </p>
                    </div>
                    <div class="rounded border border-slate-200 p-3">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Upper bound</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums">
                            {{ $storage['predicted_requests'] !== null ? number_format($storage['predicted_requests']) : '—' }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ number_format($storage['row_groups'] ?? 0) }} × {{ $storage['columns_read'] }} columns
                        </p>
                    </div>
                </div>

                <p class="text-xs text-slate-500">
                    <strong>This is the number that decides how long the report takes.</strong>
                    DuckDB issues one HTTP range request per (row group × column), and each pays a network
                    round trip — so cost tracks request count, not data volume. Row group size is the lever:
                    raising it from DuckDB's disk-oriented default of 122,880 to 1,000,000 took the
                    billion-row report from 4,247 requests / 54.8s to 1,014 / 9.3s, while
                    <em>increasing</em> bytes read by 37%.
                    <br>
                    <strong>Range requests</strong> is measured from DuckDB's <code class="rounded bg-slate-100 px-1">HTTPFSInfo</code>
                    log for the queries this page just ran — validated within 1% of
                    <code class="rounded bg-slate-100 px-1">EXPLAIN ANALYZE</code>'s own counter on two
                    windows (1,014 vs 1,024 and 1,104 vs 1,115), and unlike EXPLAIN ANALYZE it costs nothing
                    because the report has already run. It covers every lake read in this request, so the
                    tracker-detail query and the diagnostics below are included, not just the report.
                    <br>
                    <strong>Upper bound</strong> assumes one request per column chunk; DuckDB coalesces
                    adjacent chunks when prefetching, so the real figure comes in lower — ~1.7× lower on the
                    hero farm. Treat it as the ceiling a layout change moves, not a prediction.
                </p>

                @if ($storage['note'])
                    <p class="mt-2 rounded border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900">
                        {{ $storage['note'] }}
                    </p>
                @endif
            </div>
        </details>
    @endif

    {{-- Parquet files DuckLake holds for the tables this report reads --}}
    @if (!empty($files))
        @php
            $inScope = array_values(array_filter($files, fn ($f) => $f['in_scope']));
            $outOfScope = array_values(array_filter($files, fn ($f) => !$f['in_scope']));
            $inScopeBytes = array_sum(array_column($inScope, 'bytes'));
        @endphp

        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Parquet files
                <span class="ml-2 font-normal text-slate-500">
                    {{ count($inScope) }} in scope
                    ({{ number_format($inScopeBytes / 1024, 1) }} KB)
                    @if (!empty($outOfScope))
                        · {{ count($outOfScope) }} out of scope
                    @endif
                </span>
            </summary>

            <div class="border-t border-slate-200 px-5 py-4">
                <p class="mb-3 text-xs text-slate-500">
                    "In scope" is derived from each file's <em>partition path</em> against this
                    query's farm cohort and year range — it is what the partition predicate
                    <em>should</em> let DuckDB skip, not a readout of the query plan.
                    Measurement in this PoC found <code class="rounded bg-slate-100 px-1">farm_type</code>/<code class="rounded bg-slate-100 px-1">region</code>
                    pruning working but the <code class="rounded bg-slate-100 px-1">year(date)</code> transform
                    <strong>not</strong> pruning — only a raw <code class="rounded bg-slate-100 px-1">date BETWEEN</code>
                    range does, via row-group statistics — so DuckDB likely opens more year
                    partitions than the count above implies.
                    <br>
                    Note that <code class="rounded bg-slate-100 px-1">tracker_id</code> is deliberately
                    <strong>not</strong> a partition key: it is high-cardinality, and partitioning on it
                    would multiply file count, which is the measured dominant cost here.
                </p>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-slate-600">
                                <th class="py-1.5 pr-4 font-medium">Scope</th>
                                <th class="py-1.5 pr-4 font-medium">Table</th>
                                <th class="py-1.5 pr-4 font-medium">Partition</th>
                                <th class="py-1.5 pr-4 font-medium">File</th>
                                <th class="py-1.5 pr-4 text-right font-medium">Size</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($files as $file)
                                <tr class="border-b border-slate-100 {{ $file['in_scope'] ? '' : 'text-slate-400' }}">
                                    <td class="py-1 pr-4 whitespace-nowrap">
                                        @if ($file['in_scope'])
                                            <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-emerald-800">read</span>
                                        @else
                                            <span class="rounded bg-slate-100 px-1.5 py-0.5">skippable</span>
                                        @endif
                                    </td>
                                    <td class="py-1 pr-4 font-mono whitespace-nowrap">{{ $file['table'] }}</td>
                                    <td class="py-1 pr-4 font-mono whitespace-nowrap">
                                        {{ $file['partition'] ?? '—' }}
                                    </td>
                                    <td class="py-1 pr-4 font-mono whitespace-nowrap">
                                        {{ \Illuminate\Support\Str::limit($file['name'], 34) }}
                                        @if ($file['has_delete_file'])
                                            <span class="ml-1 rounded bg-amber-100 px-1 py-0.5 text-amber-800"
                                                  title="Has a delete-file tombstone, which must also be read and reconciled">
                                                +delete
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-1 pr-4 text-right tabular-nums whitespace-nowrap">
                                        {{ number_format($file['bytes'] / 1024, 1) }} KB
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </details>
    @endif

    @if (!$diagnosticsAffordable)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900">
            <p class="text-sm font-medium">Source-transaction diagnostics skipped</p>
            <p class="mt-1">
                The report took {{ number_format($elapsedMs, 0) }} ms, over the
                {{ number_format($diagnosticsBudgetMs, 0) }} ms budget, so the diagnostic scans would cost
                more than the report.
                <a class="font-medium underline" href="{{ request()->fullUrlWithQuery(['force_diagnostics' => 1]) }}">Run them anyway</a>.
            </p>
        </div>
    @endif

    {{-- The journal lines the report consumed --}}
    @if ($diagnosticsAffordable && $sourceSummary['n'] > 0)
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Source transactions
                <span class="ml-2 font-normal text-slate-500">
                    {{ number_format($sourceSummary['n']) }} row(s) in scope
                    across {{ number_format($sourceSummary['n_trackers']) }} tracker(s)
                    · net {{ number_format($sourceSummary['net_dollars'], 2) }}
                    @if ($summaryMs !== null) · counted in {{ number_format($summaryMs, 0) }} ms @endif
                </span>
            </summary>
            <div class="border-t border-slate-200 px-5 py-4">
                <p class="mb-3 text-xs text-slate-500">
                    Same in-scope predicate as the report itself, so this is genuinely what fed the
                    numbers. Note that <strong>only tracker-tagged lines appear</strong> — a Gross Margin
                    report is per operating entity, so farm-level overheads have no tracker to belong to
                    and are out of scope by definition. That is a real difference from Cash Flow, where
                    those lines are most of the report.
                    <strong>Amount (raw)</strong> is the stored fixed-point integer: revenue is a credit
                    and therefore negative.
                    @if ($sourceSummary['n'] > count($sourceRows))
                        <br>Showing the first {{ number_format(count($sourceRows)) }}
                        of {{ number_format($sourceSummary['n']) }} rows@if ($sourceRowsMs !== null), fetched in {{ number_format($sourceRowsMs, 0) }} ms@endif.
                    @endif
                </p>
                <div class="max-h-96 overflow-auto">
                    <table class="min-w-full text-xs">
                        <thead class="sticky top-0 bg-white">
                            <tr class="border-b border-slate-200 text-left text-slate-600">
                                <th class="py-1.5 pr-4 font-medium">Date</th>
                                <th class="py-1.5 pr-4 font-medium">Type</th>
                                <th class="py-1.5 pr-4 font-medium">Account</th>
                                <th class="py-1.5 pr-4 font-medium">Category</th>
                                <th class="py-1.5 pr-4 font-medium">Tracker</th>
                                <th class="py-1.5 pr-4 text-right font-medium">Amount (raw)</th>
                                <th class="py-1.5 pr-4 text-right font-medium">Dollars</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sourceRows as $row)
                                @php $dollars = (float) (string) $row['amount_dollars']; @endphp
                                <tr class="border-b border-slate-100">
                                    <td class="py-1 pr-4 font-mono whitespace-nowrap">{{ $row['date'] }}</td>
                                    <td class="py-1 pr-4 whitespace-nowrap">
                                        <span class="rounded px-1.5 py-0.5 {{ $row['type'] === 'actuals' ? 'bg-sky-100 text-sky-800' : 'bg-violet-100 text-violet-800' }}">
                                            {{ $row['type'] }}
                                        </span>
                                    </td>
                                    <td class="py-1 pr-4 whitespace-nowrap">{{ $row['account_name'] }}</td>
                                    <td class="py-1 pr-4 font-mono whitespace-nowrap">{{ $row['account_category'] }}</td>
                                    <td class="py-1 pr-4 whitespace-nowrap">
                                        <span class="rounded bg-indigo-100 px-1.5 py-0.5 text-indigo-800">
                                            {{ $row['tracker_name'] ?? $row['tracker_id'] }}
                                        </span>
                                    </td>
                                    <td class="py-1 pr-4 text-right tabular-nums whitespace-nowrap {{ $dollars < 0 ? 'text-red-700' : '' }}">
                                        {{ number_format((int) (string) $row['amount_raw']) }}
                                    </td>
                                    <td class="py-1 pr-4 text-right tabular-nums whitespace-nowrap {{ $dollars < 0 ? 'text-red-700' : '' }}">
                                        {{ number_format($dollars, 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </details>
    @endif

    {{-- Query profile (EXPLAIN ANALYZE) --}}
    @if ($profile === null)
        <div class="mb-4 rounded-lg border border-slate-200 bg-white p-4 text-xs text-slate-600 shadow-sm">
            <span class="font-medium text-slate-900">Query profile:</span>
            not run. <code class="rounded bg-slate-100 px-1">EXPLAIN ANALYZE</code> executes the query, so it
            costs a second full run and is opt-in.
            <a class="font-medium text-blue-700 underline"
               href="{{ request()->fullUrlWithQuery(['explain' => 1]) }}">Profile this query</a>
        </div>
    @elseif (!$profile['ok'])
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-sm font-medium text-red-800">Profiling failed</p>
            <pre class="mt-2 overflow-x-auto whitespace-pre-wrap text-xs text-red-700">{{ $profile['error'] }}</pre>
        </div>
    @else
        @php
            $total = $profile['total_seconds'];
            $sqlSecs = $profile['sql_seconds'];
            $storage = ($total !== null && $sqlSecs !== null) ? max(0, $total - $sqlSecs) : null;
        @endphp
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm" open>
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Query profile
                <span class="ml-2 font-normal text-slate-500">
                    {{ $total !== null ? number_format($total, 2) . 's total' : '' }}
                    @if ($profile['http_gets'] !== null)
                        · {{ number_format($profile['http_gets']) }} HTTP GETs
                    @endif
                    @if ($profile['bytes_in'])
                        · {{ $profile['bytes_in'] }} transferred
                    @endif
                </span>
            </summary>

            <div class="border-t border-slate-200 px-5 py-4">
                <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-4">
                    <div class="rounded border border-slate-200 p-3">
                        <p class="text-xs uppercase tracking-wide text-slate-500">SQL engine</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums">
                            {{ $sqlSecs !== null ? number_format($sqlSecs, 2) . 's' : '—' }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">sum of operator times</p>
                    </div>
                    <div class="rounded border border-slate-200 p-3">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Storage / waiting</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums">
                            {{ $storage !== null ? number_format($storage, 2) . 's' : '—' }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">total minus SQL</p>
                    </div>
                    <div class="rounded border border-slate-200 p-3">
                        <p class="text-xs uppercase tracking-wide text-slate-500">HTTP GETs</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums">
                            {{ $profile['http_gets'] !== null ? number_format($profile['http_gets']) : '—' }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">one per row group x column</p>
                    </div>
                    <div class="rounded border border-slate-200 p-3">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Transferred</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums">{{ $profile['bytes_in'] ?? '—' }}</p>
                        <p class="mt-1 text-xs text-slate-500">only the columns read</p>
                    </div>
                </div>

                <p class="mb-3 text-xs text-slate-500">
                    Read the two left-hand numbers together. If <strong>SQL engine</strong> is small and
                    <strong>Storage / waiting</strong> is large, the query logic is not the problem — the
                    time is going on fetching data, and the levers are partition pruning, row-group sizing
                    and how many columns the query touches. If SQL engine dominates, the plan below is where
                    to look.
                    <br>
                    On the billion-row farm, profiled cold from the CLI, this read 4,248 GETs for 26.5 MiB
                    with ~1.7s of SQL out of 56.6s total — which is what ruled out both the query plan and
                    MySQL as the cause.
                </p>

                <p class="mb-3 rounded border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900">
                    <strong>Warm cache.</strong> Profiling runs after the report has already executed in this
                    same process, so httpfs has cached what the report read. The GET count and transferred
                    bytes above therefore describe a <em>second</em> read, not a cold one, and will often show
                    0 — which is why they can look implausibly good here. For cold numbers, profile in a fresh
                    process:
                    <code class="mt-1 block rounded bg-amber-100 px-1 py-0.5">php artisan duckdb:tracker:profile --farm=&lt;id&gt;</code>
                    The SQL-vs-storage split stays meaningful either way, since both halves are measured the
                    same run.
                </p>

                @if (!empty($profile['operators']))
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead>
                                <tr class="border-b border-slate-200 text-left text-slate-600">
                                    <th class="py-1.5 pr-4 font-medium">Slowest operators</th>
                                    <th class="py-1.5 pr-4 text-right font-medium">Time</th>
                                    <th class="py-1.5 pr-4 text-right font-medium">Share of total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($profile['operators'] as $op)
                                    <tr class="border-b border-slate-100">
                                        <td class="py-1 pr-4 font-mono whitespace-nowrap">{{ $op['name'] }}</td>
                                        <td class="py-1 pr-4 text-right tabular-nums whitespace-nowrap">
                                            {{ number_format($op['seconds'], 2) }}s
                                        </td>
                                        <td class="py-1 pr-4 text-right tabular-nums whitespace-nowrap text-slate-500">
                                            {{ $total ? number_format($op['seconds'] / $total * 100, 1) . '%' : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <details class="mt-4">
                    <summary class="cursor-pointer text-xs font-medium text-slate-600">Full EXPLAIN ANALYZE output</summary>
                    <pre class="mt-2 max-h-96 overflow-auto rounded bg-slate-900 p-3 text-xs leading-relaxed text-slate-100">{{ $profile['raw'] }}</pre>
                </details>
            </div>
        </details>
    @endif

    @if ($sql)
        <details class="mb-4 mt-6 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium">
                Generated SQL
                <span class="ml-1 font-normal text-slate-500">
                    ({{ number_format(strlen($sql)) }} bytes, identical for any tracker count)
                </span>
            </summary>
            <pre class="overflow-x-auto border-t border-slate-200 px-4 py-3 text-xs leading-relaxed">{{ $sql }}</pre>
        </details>
    @endif

</div>
</body>
</html>
