<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gross Margin V2</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="mx-auto max-w-[110rem] px-6 py-8">

    @php
        $money = static function (?float $v): string {
            if ($v === null || abs($v) < 0.005) { return '-'; }
            return $v < 0 ? '(' . number_format(abs($v), 0) . ')' : number_format($v, 0);
        };
    @endphp

    <header class="mb-6">
        <h1 class="text-2xl font-semibold">Gross Margin V2 — mixed enterprise</h1>
        <p class="mt-1 text-sm text-slate-600">
            Sections nested under Income and Direct Costs, mixing <strong>milk and livestock</strong>
            enterprises on one farm, with a Gross Margin line and Actual/Forecast per column.
        </p>
        <p class="mt-2 text-sm">
            <a href="{{ route('reports') }}" class="text-blue-700 underline">← all reports</a>
            <span class="mx-2 text-slate-300">|</span>
            <a href="{{ route('gross-margin') }}" class="text-blue-700 underline">Gross Margin V1 (flat, livestock only)</a>
        </p>
    </header>

    <form method="GET" class="mb-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Farm</span>
                <select name="farm_id" class="w-full rounded border-slate-300 text-sm shadow-sm">
                    @foreach ($farms as $option)
                        <option value="{{ $option['farm_id'] }}" @selected($option['farm_id'] === $farmId)>{{ $option['farm_id'] }}</option>
                    @endforeach
                </select>
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
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Actuals to</span>
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
            <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">Run report</button>
        </div>
    </form>

    @if ($error)
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-sm font-medium text-red-800">Query failed</p>
            <pre class="mt-2 overflow-x-auto whitespace-pre-wrap text-xs text-red-700">{{ $error }}</pre>
        </div>
    @endif

    @if ($farm !== null && $elapsedMs !== null)
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-{{ $jitter !== null ? 6 : 5 }}">
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Enterprise types</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ count($trackerBreakdown) }}</p>
                <p class="mt-1 text-xs text-slate-500">
                    @foreach ($trackerBreakdown as $b){{ $b['tracker_type'] }} ({{ $b['n'] }}){{ !$loop->last ? ', ' : '' }}@endforeach
                </p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">DuckDB report</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($elapsedMs, 0) }} ms</p>
                <p class="mt-1 text-xs text-slate-500">1 query, all levels</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Page load</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums" data-page-ms>&mdash;</p>
                <p class="mt-1 text-xs text-slate-500">
                    @if ($serverMs !== null)
                        server {{ number_format($serverMs, 0) }} ms
                    @endif
                    @if ($summaryMs !== null)
                        · diagnostics {{ number_format($summaryMs + ($sourceRowsMs ?? 0), 0) }} ms
                    @endif
                </p>
            </div>
            @if ($jitter !== null)
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-amber-700">Total, actual + jitter</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-amber-900">
                        {{ number_format($jitter['low_ms'], 0) }}&ndash;{{ number_format($jitter['high_ms'], 0) }} ms
                    </p>
                    <p class="mt-1 text-xs text-amber-700">
                        {{ number_format($elapsedMs, 0) }} ms actual
                        + {{ number_format($jitter['added_low_ms'], 0) }}&ndash;{{ number_format($jitter['added_high_ms'], 0) }} ms network
                    </p>
                    <p class="mt-0.5 text-xs text-amber-600">
                        {{ number_format($jitter['requests']) }} reqs
                        &times; {{ number_format($jitter['low_latency_ms'], 0) }}&ndash;{{ number_format($jitter['high_latency_ms'], 0) }} ms
                    </p>
                </div>
            @endif
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Queries issued</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">1</p>
                <p class="mt-1 text-xs text-slate-500">Figured: 2 reports, stitched</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Aggregation levels</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">4</p>
                <p class="mt-1 text-xs text-slate-500">line · group · section · margin</p>
            </div>
        </div>

        <div class="mb-8 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600 shadow-sm">
            <span class="font-medium text-slate-900">Why this is one query:</span>
            In Figured, milk and livestock are different reports —
            <code class="rounded bg-slate-100 px-1">TrackerQuantityService</code> branches
            <code class="rounded bg-slate-100 px-1">case 'milk'</code> to
            <code class="rounded bg-slate-100 px-1">getMilkQuantities()</code> and
            <code class="rounded bg-slate-100 px-1">case 'tracker'</code> to
            <code class="rounded bg-slate-100 px-1">getStockQuantities()</code>, and the two structure
            builders exclude each other (<code class="rounded bg-slate-100 px-1">LivestockGrossMarginStructureBuilder</code>
            skips the milk virtual journal). A combined report means running both and stitching.
            <br>
            Here the three aggregation levels come from one scan via
            <code class="rounded bg-slate-100 px-1">GROUPING SETS</code>, the section hierarchy is data
            (<code class="rounded bg-slate-100 px-1">accounts.report_group</code>) rather than builder
            branching, and the two quantity shapes — milk as a flow, livestock as a running balance from
            movements — are separate CTEs in the same statement rather than separate queries.
        </div>
    @endif

    @if (!empty($tree))
        <div class="mb-8 overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead>
                <tr class="bg-slate-100">
                    <th class="sticky left-0 bg-slate-100 px-3 py-2 text-left font-medium text-slate-600">Row</th>
                    @foreach ($months as $col)
                        <th class="px-3 py-2 text-right font-medium text-slate-700 whitespace-nowrap">{{ $col['month'] }}</th>
                    @endforeach
                </tr>
                <tr class="bg-slate-50">
                    <th class="sticky left-0 bg-slate-50 px-3 py-1"></th>
                    @foreach ($months as $col)
                        <th class="px-3 py-1 text-right text-xs font-normal
                                   {{ $col['basis'] === 'Actual' ? 'text-slate-500' : 'text-violet-600' }}">
                            {{ $col['basis'] }}
                        </th>
                    @endforeach
                </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @foreach ($tree as $row)
                    @php
                        $indent = max(0, 2 - $row['level']) * 16 + 8;
                        $emphasis = match ($row['level']) {
                            3 => 'bg-slate-100 font-semibold text-base',
                            2 => 'bg-slate-50 font-semibold',
                            1 => 'font-medium',
                            default => '',
                        };
                    @endphp
                    <tr class="{{ $emphasis }} {{ $row['level'] >= 2 ? 'border-t-2 border-slate-300' : '' }}">
                        <td class="sticky left-0 whitespace-nowrap bg-white px-3 py-1.5 {{ $emphasis }}"
                            style="padding-left: {{ $indent }}px">
                            {{ $row['label'] }}
                        </td>
                        @foreach ($months as $col)
                            @php
                                $cell = $row['months'][$col['month']] ?? null;
                                $amount = $cell['amount'] ?? null;
                            @endphp
                            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap
                                       {{ ($amount ?? 0) < 0 ? 'text-red-700' : '' }}">
                                {{ $money($amount) }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- DuckDB's own execution waterfall for THIS request --}}
    @if ($trace === null)
        <div class="mb-4 rounded-lg border border-slate-200 bg-white p-4 text-xs text-slate-600 shadow-sm">
            <span class="font-medium text-slate-900">DuckDB trace:</span>
            not captured. DuckDB's log lives in the process, so it cannot be read after the request ends —
            it has to be armed before the query runs.
            <a class="font-medium text-blue-700 underline"
               href="{{ request()->fullUrlWithQuery(['trace' => 1]) }}">Trace this request</a>
        </div>
    @elseif (!$trace['ok'])
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-xs text-red-700">
            Trace failed: {{ $trace['error'] }}
        </div>
    @else
        @php
            $colour = [
                'HTTPFSInfo' => 'bg-rose-500',
                'HTTP' => 'bg-orange-400',
                'FileSystem' => 'bg-amber-400',
                'DuckLakeMetadata' => 'bg-violet-500',
                'PhysicalOperator' => 'bg-sky-500',
                'QueryLog' => 'bg-emerald-500',
                'Transaction' => 'bg-slate-400',
            ];
            $text = [
                'HTTPFSInfo' => 'text-rose-700',
                'HTTP' => 'text-orange-700',
                'FileSystem' => 'text-amber-700',
                'DuckLakeMetadata' => 'text-violet-700',
                'PhysicalOperator' => 'text-sky-700',
                'QueryLog' => 'text-emerald-700',
                'Transaction' => 'text-slate-600',
            ];
            $span = max(0.001, $trace['total_ms']);
        @endphp

        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm" open>
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                DuckDB execution waterfall
                <span class="ml-2 font-normal text-slate-500">
                    {{ number_format($trace['total_ms'], 0) }} ms traced ·
                    {{ number_format(count($trace['steps'])) }} steps
                </span>
            </summary>

            <div class="border-t border-slate-200 px-5 py-4">
                <p class="mb-3 text-xs text-slate-500">
                    Every event DuckDB logged, in the order it happened, with the time that elapsed
                    <em>after</em> it. That is the key to reading this: a step's duration is the wait that
                    followed it, not its own cost — so a 300 ms
                    <span class="{{ $text['HTTPFSInfo'] }}">HTTPFSInfo</span> row means DuckDB was blocked
                    on that network read for 300 ms.
                </p>

                {{-- Time by phase --}}
                <p class="mb-1 text-xs font-medium text-slate-700">Where the time went</p>
                <div class="mb-4 overflow-x-auto rounded border border-slate-200">
                    <table class="min-w-full text-xs">
                        <thead class="bg-slate-50">
                            <tr class="text-left text-slate-600">
                                <th class="px-2 py-1 font-medium">Phase</th>
                                <th class="px-2 py-1 text-right font-medium">Total</th>
                                <th class="px-2 py-1 text-right font-medium">Share</th>
                                <th class="px-2 py-1 text-right font-medium">Events</th>
                                <th class="px-2 py-1 text-right font-medium">Worst step</th>
                                <th class="px-2 py-1 font-medium">&nbsp;</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($trace['by_type'] as $t)
                                <tr class="border-t border-slate-100">
                                    <td class="px-2 py-1 font-medium {{ $text[$t['type']] ?? 'text-slate-700' }}">{{ $t['type'] }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums">{{ number_format($t['ms'], 1) }} ms</td>
                                    <td class="px-2 py-1 text-right tabular-nums">{{ number_format($t['share'], 1) }}%</td>
                                    <td class="px-2 py-1 text-right tabular-nums text-slate-500">{{ number_format($t['events']) }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums text-slate-500">{{ number_format($t['worst_ms'], 1) }} ms</td>
                                    <td class="px-2 py-1 w-1/3">
                                        <div class="h-2 rounded {{ $colour[$t['type']] ?? 'bg-slate-400' }}"
                                             style="width: {{ max(1, $t['share']) }}%"></div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Biggest single waits --}}
                @if (!empty($trace['hotspots']))
                    <p class="mb-1 text-xs font-medium text-slate-700">Biggest single waits</p>
                    <div class="mb-4 overflow-x-auto rounded border border-slate-200">
                        <table class="min-w-full text-xs">
                            <thead class="bg-slate-50">
                                <tr class="text-left text-slate-600">
                                    <th class="px-2 py-1 text-right font-medium">at</th>
                                    <th class="px-2 py-1 text-right font-medium">waited</th>
                                    <th class="px-2 py-1 font-medium">after this step</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($trace['hotspots'] as $h)
                                    <tr class="border-t border-slate-100">
                                        <td class="px-2 py-1 text-right tabular-nums text-slate-400">{{ number_format($h['at_ms'], 0) }} ms</td>
                                        <td class="px-2 py-1 text-right font-semibold tabular-nums {{ $text[$h['type']] ?? '' }}">{{ number_format($h['dur_ms'], 1) }} ms</td>
                                        <td class="px-2 py-1 font-mono text-slate-600">
                                            <span class="{{ $text[$h['type']] ?? '' }}">{{ $h['type'] }}</span>
                                            · {{ \Illuminate\Support\Str::limit($h['detail'], 100) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                {{-- Full timeline --}}
                <p class="mb-1 text-xs font-medium text-slate-700">
                    Full timeline
                    <span class="font-normal text-slate-500">— in the order it happened</span>
                </p>
                <div class="max-h-[32rem] overflow-auto rounded border border-slate-200">
                    <table class="min-w-full text-xs">
                        <thead class="sticky top-0 bg-slate-50">
                            <tr class="text-left text-slate-600">
                                <th class="px-2 py-1 text-right font-medium">#</th>
                                <th class="px-2 py-1 text-right font-medium">at</th>
                                <th class="px-2 py-1 text-right font-medium">waited</th>
                                <th class="px-2 py-1 font-medium">step</th>
                                <th class="px-2 py-1 font-medium w-1/4">timeline</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($trace['steps'] as $i => $step)
                                <tr class="border-t border-slate-100 {{ $step['slow'] ? 'bg-rose-50' : '' }}">
                                    <td class="px-2 py-0.5 text-right text-slate-400">{{ $i + 1 }}</td>
                                    <td class="px-2 py-0.5 text-right tabular-nums text-slate-400">{{ number_format($step['at_ms'], 1) }}</td>
                                    <td class="px-2 py-0.5 text-right tabular-nums {{ $step['slow'] ? 'font-semibold text-rose-700' : 'text-slate-500' }}">
                                        {{ $step['dur_ms'] >= 0.05 ? number_format($step['dur_ms'], 2) : '·' }}
                                    </td>
                                    <td class="px-2 py-0.5 whitespace-nowrap">
                                        <span class="{{ $text[$step['type']] ?? 'text-slate-600' }} font-medium">{{ $step['type'] }}</span>
                                        <span class="ml-1 font-mono text-slate-500">{{ \Illuminate\Support\Str::limit($step['detail'], 84) }}</span>
                                    </td>
                                    <td class="px-2 py-0.5">
                                        <div class="relative h-2 w-full rounded bg-slate-100">
                                            <div class="absolute h-2 rounded {{ $colour[$step['type']] ?? 'bg-slate-400' }}"
                                                 style="left: {{ min(99, $step['at_ms'] / $span * 100) }}%; width: {{ max(0.6, $step['dur_ms'] / $span * 100) }}%"></div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-1 text-xs text-slate-500">
                    Rows shaded red waited 5 ms or more. A "·" means under 0.05 ms.
                </p>
            </div>
        </details>
    @endif

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
                more than the report itself.
                <a class="font-medium underline" href="{{ request()->fullUrlWithQuery(['force_diagnostics' => 1]) }}">Run them anyway</a>.
            </p>
        </div>
    @endif

    @if ($sourceRowsSkipped)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900">
            <p class="text-sm font-medium">Source-row listing skipped</p>
            <p class="mt-1">
                {{ number_format($sourceSummary['n']) }} rows in scope, over the
                {{ number_format($sourceListingMaxRows) }} row limit. The listing is
                <code class="rounded bg-amber-100 px-1">ORDER BY date LIMIT {{ $sourceRowLimit }}</code>,
                which DuckDB answers with a bounded top-N heap — but it still reads the sort column for
                every row in scope. The row counts and net total above are already computed, so nothing
                is missing except the sample rows.
                <a class="font-medium underline" href="{{ request()->fullUrlWithQuery(['force_source_rows' => 1]) }}">Load it anyway</a>.
            </p>
        </div>
    @endif

    {{-- The journal lines the report consumed --}}
    @if ($diagnosticsAffordable && !$sourceRowsSkipped && $sourceSummary['n'] > 0)
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Source transactions
                <span class="ml-2 font-normal text-slate-500">
                    {{ number_format($sourceSummary['n']) }} row(s) in scope
                    across {{ number_format($sourceSummary['n_groups']) }} section(s)
                    and {{ number_format($sourceSummary['n_trackers']) }} tracker(s)
                    · net {{ number_format($sourceSummary['net_dollars'], 2) }}
                    @if ($summaryMs !== null) · counted in {{ number_format($summaryMs, 0) }} ms @endif
                </span>
            </summary>
            <div class="border-t border-slate-200 px-5 py-4">
                <p class="mb-3 text-xs text-slate-500">
                    Same in-scope predicate as the report, so this is what actually fed the numbers.
                    Only accounts that declare a <strong>report group</strong> appear — this report can
                    only place a line it knows a section for, so accounts belonging to the other reports
                    on this lake are out of scope by definition rather than by omission.
                    <br>
                    The <strong>Section</strong> and <strong>Enterprise</strong> columns are the mapping
                    that makes the hierarchy work: the section is a property of the <em>account</em>, while
                    the enterprise type comes from the <em>tracker</em> — which is why milk and livestock
                    can sit in one report here but need two in Figured.
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
                                <th class="py-1.5 pr-4 font-medium">Section</th>
                                <th class="py-1.5 pr-4 font-medium">Enterprise</th>
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
                                    <td class="py-1 pr-4 whitespace-nowrap text-slate-600">{{ $row['report_group_label'] }}</td>
                                    <td class="py-1 pr-4 whitespace-nowrap">
                                        <span class="rounded px-1.5 py-0.5 {{ $row['tracker_type'] === 'milk' ? 'bg-blue-100 text-blue-800' : 'bg-emerald-100 text-emerald-800' }}">
                                            {{ $row['tracker_type'] }}
                                        </span>
                                        <span class="ml-1 text-slate-500">{{ $row['tracker_name'] }}</span>
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
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium">
                Generated SQL
                <span class="ml-1 font-normal text-slate-500">({{ number_format(strlen($sql)) }} bytes)</span>
            </summary>
            <pre class="overflow-x-auto border-t border-slate-200 px-4 py-3 text-xs leading-relaxed">{{ $sql }}</pre>
        </details>
    @endif

</div>
    <script>
        // Wall clock as the browser experienced it. The server figure stops at
        // view dispatch, so a large gap here is HTML rendering or transfer
        // rather than anything DuckDB did — the distinction that made a 3,575 ms
        // report look like a 20-30 second page.
        (() => {
            const write = () => {
                const ms = Math.round(performance.now());
                document.querySelectorAll('[data-page-ms]').forEach((el) => {
                    el.textContent = ms.toLocaleString() + ' ms';
                });
            };
            if (document.readyState === 'complete') {
                write();
            } else {
                window.addEventListener('load', write);
            }
        })();
    </script>
</body>
</html>
