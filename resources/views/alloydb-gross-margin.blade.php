<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gross Margin — AlloyDB</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="mx-auto max-w-[110rem] px-6 py-8">

    @php
        $money = static function (?float $v): string {
            if ($v === null || abs($v) < 0.005) { return '-'; }
            return $v < 0 ? '(' . number_format(abs($v), 0) . ')' : number_format($v, 0);
        };
        $bytes = static function (int $b): string {
            if ($b <= 0) { return '0 B'; }
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $i = (int) floor(log($b, 1024));
            $i = min($i, count($units) - 1);
            return number_format($b / (1024 ** $i), $i === 0 ? 0 : 1) . ' ' . $units[$i];
        };
    @endphp

    <header class="mb-6">
        <h1 class="text-2xl font-semibold">Gross Margin — mixed enterprise</h1>
        <p class="mt-1 text-sm text-slate-600">
            Sections nested under Income and Direct Costs, mixing <strong>milk and livestock</strong>
            enterprises on one farm, with a Gross Margin line and Actual/Forecast per column.
        </p>
        <p class="mt-2 text-sm text-slate-600">
            Served entirely from <strong>AlloyDB</strong> — journals, accounts, trackers, milk
            production and stock movements all live in one PostgreSQL&nbsp;17 database, so the
            report is an ordinary join rather than a federated one.
        </p>
        <p class="mt-2 text-sm">
            <a href="{{ route('reports') }}" class="text-blue-700 underline">← all reports</a>
        </p>
    </header>

    <form method="GET" class="mb-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-6">
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-700">Farm</span>
                <select name="farm_id" class="w-full rounded border-slate-300 text-sm">
                    @foreach ($farms as $f)
                        <option value="{{ $f['farm_id'] }}" @selected($f['farm_id'] === $farmId)>
                            {{ $f['farm_id'] }}
                        </option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-700">Period from</span>
                <input type="date" name="period_from" value="{{ $periodFrom }}"
                       class="w-full rounded border-slate-300 text-sm">
            </label>
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-700">Period to</span>
                <input type="date" name="period_to" value="{{ $periodTo }}"
                       class="w-full rounded border-slate-300 text-sm">
            </label>
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-700">Horizon</span>
                <input type="date" name="horizon" value="{{ $horizon }}"
                       class="w-full rounded border-slate-300 text-sm">
            </label>
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-700">Basis</span>
                <select name="basis" class="w-full rounded border-slate-300 text-sm">
                    <option value="cash" @selected($basis === 'cash')>cash</option>
                    <option value="accrual" @selected($basis === 'accrual')>accrual</option>
                </select>
            </label>
            <div class="flex items-end">
                <button type="submit"
                        class="w-full rounded bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800">
                    Run report
                </button>
            </div>
        </div>
    </form>

    @if ($error !== null)
        <div class="mb-6 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800">
            <strong class="font-semibold">Query failed:</strong> {{ $error }}
        </div>
    @endif

    @if ($farm !== null && $elapsedMs !== null)
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Enterprise types</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ count($trackerBreakdown) }}</p>
                <p class="mt-1 text-xs text-slate-500">
                    @foreach ($trackerBreakdown as $b){{ $b['tracker_type'] }} ({{ $b['n'] }}){{ !$loop->last ? ', ' : '' }}@endforeach
                </p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Query time</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($elapsedMs, 0) }} ms</p>
                <p class="mt-1 text-xs text-slate-500">1 statement, all levels</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Page load</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums" data-page-ms>&mdash;</p>
                <p class="mt-1 text-xs text-slate-500">
                    @if ($serverMs !== null)
                        server {{ number_format($serverMs, 0) }} ms
                    @endif
                    @if ($summaryMs !== null)
                        · diagnostics {{ number_format($summaryMs, 0) }} ms
                    @endif
                </p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Transaction lines</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">
                    @if ($summaryMs !== null){{ number_format($sourceSummary['n']) }}@else&mdash;@endif
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    @if ($summaryMs !== null)
                        {{ $sourceSummary['n_groups'] }} groups · {{ $sourceSummary['n_trackers'] }} trackers
                    @else
                        skipped &mdash; report over {{ number_format($diagnosticsBudgetMs, 0) }} ms
                    @endif
                </p>
            </div>
            @php
                $used = $columnar['used_bytes'];
                $budget = $columnar['budget_mb'] * 1024 * 1024;
                $pct = $budget > 0 ? ($used / $budget) * 100 : 0;
                $coverage = $columnar['coverage'];
                $partial = $coverage !== null && $coverage < 0.999;
            @endphp
            <div class="rounded-lg border {{ $partial ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-white' }} p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide {{ $partial ? 'text-red-700' : 'text-slate-500' }}">
                    Table in memory
                </p>
                <p class="mt-1 text-2xl font-semibold tabular-nums {{ $partial ? 'text-red-900' : '' }}">
                    @if ($coverage === null)&mdash;@else{{ number_format($coverage * 100, 1) }}%@endif
                </p>
                <p class="mt-1 text-xs {{ $partial ? 'text-red-700' : 'text-slate-500' }}">
                    {{ number_format($columnar['blocks_in_store']) }} of
                    {{ number_format($columnar['blocks_total']) }} blocks held
                </p>
                <p class="mt-0.5 text-xs text-slate-400">
                    using {{ $bytes($used) }} of the {{ $columnar['budget_mb'] }} MB budget
                    @if ($pct < 100)&mdash; {{ number_format(100 - $pct, 0) }}% spare@endif
                </p>
            </div>
        </div>

        <div class="mb-8 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600 shadow-sm">
            <span class="font-medium text-slate-900">One database, one join:</span>
            journals and dimensions are tables in the same instance, so
            <code class="rounded bg-slate-100 px-1">transaction_lines</code> joins
            <code class="rounded bg-slate-100 px-1">accounts</code> the way any two tables do.
            The three levels of the hierarchy — line item, group subtotal, section total — still
            come from a single scan via
            <code class="rounded bg-slate-100 px-1">GROUPING SETS</code>, and the livestock
            running balance from one window function.
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

    @if ($farm !== null)
        {{-- ---------- transaction lines ---------- --}}
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm" open>
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium">
                Transaction lines used
                <span class="ml-2 font-normal text-slate-500">
                    @if ($summaryMs !== null)
                        {{ number_format($sourceSummary['n']) }} lines ·
                        net {{ $money($sourceSummary['net_dollars']) }} ·
                        counted in {{ number_format($summaryMs, 0) }} ms
                    @else
                        skipped
                    @endif
                </span>
            </summary>
            <div class="border-t border-slate-200 px-4 py-3">
                @if ($summaryMs === null)
                    <p class="text-sm text-slate-600">
                        Skipped: the report took over {{ number_format($diagnosticsBudgetMs, 0) }} ms, and
                        counting the lines is a second pass over the same rows.
                        <a href="{{ request()->fullUrlWithQuery(['force_diagnostics' => 1]) }}"
                           class="text-blue-700 underline">Count them anyway</a>.
                    </p>
                @else
                    <p class="mb-3 text-xs text-slate-600">
                        Every journal line the report consumed, counted with the report's own scope
                        predicate — same farm, same basis, same period, same horizon rule. The
                        <strong>Actual/Forecast split</strong> is the column to read: a period
                        straddling the horizon should show both, and an unexpected zero on either
                        side usually means the horizon has silently excluded a date range.
                    </p>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-2 py-1 text-left font-medium">Account</th>
                                <th class="px-2 py-1 text-left font-medium">Section</th>
                                <th class="px-2 py-1 text-right font-medium">Actual</th>
                                <th class="px-2 py-1 text-right font-medium">Forecast</th>
                                <th class="px-2 py-1 text-right font-medium">Lines</th>
                                <th class="px-2 py-1 text-left font-medium">First</th>
                                <th class="px-2 py-1 text-left font-medium">Last</th>
                                <th class="px-2 py-1 text-right font-medium">Net $</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            @foreach ($lineBreakdown as $b)
                                <tr>
                                    <td class="px-2 py-1 whitespace-nowrap">{{ $b['account_name'] }}</td>
                                    <td class="px-2 py-1 text-slate-500 whitespace-nowrap">{{ $b['report_group_label'] }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums">{{ number_format((int) $b['n_actuals']) }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums {{ (int) $b['n_forecast'] > 0 ? 'text-violet-700' : 'text-slate-300' }}">
                                        {{ number_format((int) $b['n_forecast']) }}
                                    </td>
                                    <td class="px-2 py-1 text-right font-medium tabular-nums">{{ number_format((int) $b['n']) }}</td>
                                    <td class="px-2 py-1 text-slate-500 whitespace-nowrap">{{ $b['first_date'] }}</td>
                                    <td class="px-2 py-1 text-slate-500 whitespace-nowrap">{{ $b['last_date'] }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums">{{ $money((float) $b['net_dollars']) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                            <tr>
                                <td class="px-2 py-1" colspan="4">
                                    {{ count($lineBreakdown) }} accounts ·
                                    {{ $sourceSummary['n_trackers'] }} trackers ·
                                    {{ $sourceSummary['n_groups'] }} groups
                                </td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ number_format($sourceSummary['n']) }}</td>
                                <td class="px-2 py-1" colspan="2"></td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ $money($sourceSummary['net_dollars']) }}</td>
                            </tr>
                            </tfoot>
                        </table>
                    </div>

                    <h3 class="mt-4 mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Individual lines
                    </h3>

                    @if ($sourceRowsSkipped)
                        <p class="text-sm text-slate-600">
                            Listing skipped &mdash; {{ number_format($sourceSummary['n']) }} lines in scope,
                            over the {{ number_format($sourceListingMaxRows) }} limit. The counts above
                            cover every line; only the sample is missing.
                            <a href="{{ request()->fullUrlWithQuery(['force_source_rows' => 1]) }}"
                               class="text-blue-700 underline">List anyway</a>.
                        </p>
                    @elseif (empty($sourceRows))
                        <p class="text-sm text-slate-500">No lines in scope for this period.</p>
                    @else
                        <p class="mb-2 text-xs text-slate-500">
                            First {{ number_format(count($sourceRows)) }} by date, of
                            {{ number_format($sourceSummary['n']) }}.
                        </p>
                        <div class="max-h-80 overflow-auto rounded border border-slate-200">
                            <table class="min-w-full text-xs">
                                <thead class="sticky top-0 bg-slate-50 text-slate-600">
                                <tr>
                                    <th class="px-2 py-1 text-left font-medium">Date</th>
                                    <th class="px-2 py-1 text-left font-medium">Type</th>
                                    <th class="px-2 py-1 text-left font-medium">Account</th>
                                    <th class="px-2 py-1 text-left font-medium">Tracker</th>
                                    <th class="px-2 py-1 text-right font-medium">Amount $</th>
                                    <th class="px-2 py-1 text-left font-medium">Line</th>
                                </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                @foreach ($sourceRows as $r)
                                    <tr>
                                        <td class="px-2 py-1 whitespace-nowrap tabular-nums">{{ $r['date'] }}</td>
                                        <td class="px-2 py-1">
                                            <span class="rounded px-1.5 py-0.5
                                                         {{ $r['type'] === 'actuals' ? 'bg-slate-100 text-slate-700' : 'bg-violet-100 text-violet-800' }}">
                                                {{ $r['type'] }}
                                            </span>
                                        </td>
                                        <td class="px-2 py-1 whitespace-nowrap">{{ $r['account_name'] }}</td>
                                        <td class="px-2 py-1 font-mono text-slate-500 whitespace-nowrap">{{ $r['tracker_id'] }}</td>
                                        <td class="px-2 py-1 text-right tabular-nums {{ (float) $r['amount_dollars'] < 0 ? 'text-red-700' : '' }}">
                                            {{ number_format((float) $r['amount_dollars'], 2) }}
                                        </td>
                                        <td class="px-2 py-1 font-mono text-slate-400 whitespace-nowrap">{{ $r['line_id'] }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endif
            </div>
        </details>

        {{-- ---------- columnar engine ---------- --}}
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm" open>
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium">
                Columnar engine
                <span class="ml-2 font-normal text-slate-500">
                    {{ count($columnar['columns']) }} column(s) in memory ·
                    {{ $bytes($columnar['used_bytes']) }} of {{ $columnar['budget_mb'] }} MB
                </span>
            </summary>
            <div class="border-t border-slate-200 px-4 py-3">
                <p class="mb-3 text-xs text-slate-600">
                    The column store is <strong>memory-resident</strong> with a fixed budget, so
                    capacity — not disk — is what limits it. A query whose columns no longer fit
                    falls back to scanning the row store: same answers, far slower, no error.
                </p>

                @if ($columnar['coverage'] !== null && $columnar['coverage'] < 0.999)
                    <p class="mb-3 rounded border-l-4 border-red-400 bg-red-50 px-3 py-2 text-xs text-red-900">
                        <strong>Only {{ number_format($columnar['coverage'] * 100, 1) }}% of the
                        table is in memory.</strong>
                        The store filled up and stopped, so the remaining
                        {{ number_format((1 - $columnar['coverage']) * 100, 1) }}% is read from the
                        heap on every query. Read this figure rather than the budget percentage —
                        a store at 88% of its budget can still hold under a third of the table.
                        Full coverage needs roughly
                        {{ number_format($columnar['coverage'] > 0 ? $columnar['budget_mb'] / $columnar['coverage'] / 1024 : 0, 1) }} GB
                        at this volume.
                    </p>
                @endif

                @if (empty($columnar['columns']))
                    <p class="text-sm text-slate-500">
                        Nothing populated. Run
                        <code class="rounded bg-slate-100 px-1">php artisan alloydb:setup --columnar</code>.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-2 py-1 text-left font-medium">Column</th>
                                <th class="px-2 py-1 text-left font-medium">Type</th>
                                <th class="px-2 py-1 text-left font-medium">Status</th>
                                <th class="px-2 py-1 text-right font-medium">In memory</th>
                                <th class="px-2 py-1 text-right font-medium">Times accessed</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            @foreach ($columnar['columns'] as $c)
                                <tr>
                                    <td class="px-2 py-1 font-mono">{{ $c['column_name'] }}</td>
                                    <td class="px-2 py-1 text-slate-500">{{ $c['column_type'] ?? '' }}</td>
                                    <td class="px-2 py-1">
                                        <span class="rounded px-1.5 py-0.5
                                                     {{ ($c['status'] ?? '') === 'Usable' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                                            {{ $c['status'] ?? '?' }}
                                        </span>
                                    </td>
                                    <td class="px-2 py-1 text-right tabular-nums">{{ $c['in_memory'] ?? '' }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums text-slate-500">
                                        {{ number_format((int) ($c['num_times_accessed'] ?? 0)) }}
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p class="mt-3 text-xs text-slate-500">
                        Table on disk: {{ $bytes($columnar['table_bytes']) }} ·
                        planner row estimate: {{ number_format($columnar['row_estimate']) }}
                        @if ($columnar['row_estimate'] > 0)
                            · <strong>{{ number_format($columnar['used_bytes'] / max(1, $columnar['row_estimate']), 1) }}
                            bytes per row</strong> in the column store
                        @endif
                    </p>
                @endif
            </div>
        </details>

        {{-- ---------- query plan ---------- --}}
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium">
                Query plan
                @if ($plan === null)
                    <span class="ml-2 font-normal text-slate-500">not captured</span>
                @elseif ($plan['columnar_scan'])
                    <span class="ml-2 rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-normal text-emerald-800">
                        columnar scan used
                    </span>
                @else
                    <span class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-normal text-amber-800">
                        row-store scan &mdash; columnar engine not used
                    </span>
                @endif
            </summary>
            <div class="border-t border-slate-200 px-4 py-3">
                @if ($plan === null)
                    <p class="text-sm text-slate-600">
                        <a href="{{ request()->fullUrlWithQuery(['explain' => 1]) }}"
                           class="text-blue-700 underline">Explain this query</a>
                        &mdash; runs it again under
                        <code class="rounded bg-slate-100 px-1">EXPLAIN (ANALYZE, BUFFERS)</code>,
                        so it costs a second execution.
                    </p>
                @elseif ($plan['error'] !== null)
                    <p class="text-sm text-red-700">{{ $plan['error'] }}</p>
                @else
                    <div class="mb-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-5">
                        <div>
                            <p class="text-slate-500">Execution</p>
                            <p class="font-semibold tabular-nums">{{ number_format($plan['execution_ms'] ?? 0, 1) }} ms</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Planning</p>
                            <p class="font-semibold tabular-nums">{{ number_format($plan['planning_ms'] ?? 0, 1) }} ms</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Buffer hits</p>
                            <p class="font-semibold tabular-nums">{{ number_format($plan['shared_hit']) }}</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Buffer reads</p>
                            <p class="font-semibold tabular-nums {{ $plan['shared_read'] > 0 ? 'text-amber-700' : '' }}">
                                {{ number_format($plan['shared_read']) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-slate-500">Temp written</p>
                            <p class="font-semibold tabular-nums {{ $plan['temp_written'] > 0 ? 'text-red-700' : '' }}">
                                {{ number_format($plan['temp_written']) }}
                            </p>
                        </div>
                    </div>

                    @unless ($plan['columnar_scan'])
                        <p class="mb-3 rounded border-l-4 border-amber-400 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                            <strong>The columnar engine did not serve this query.</strong>
                            Expected on a small table — below roughly 5,000 rows the planner
                            prefers the row store, because a sequential scan of a few pages beats
                            the columnar path's setup. On a large table this badge means something
                            has gone wrong: the columns are not populated, or they no longer fit the
                            in-memory budget.
                        </p>
                    @endunless

                    <p class="mb-3 text-xs text-slate-600">
                        <strong>Buffer reads</strong> above zero means pages came from storage rather
                        than <code class="rounded bg-slate-100 px-1">shared_buffers</code>.
                        <strong>Temp written</strong> above zero means the query spilled to disk —
                        the sort or hash aggregate outgrew
                        <code class="rounded bg-slate-100 px-1">work_mem</code>.
                    </p>

                    @if ($plan['columnar_nodes'] !== [])
                        <p class="mb-2 text-xs text-slate-600">
                            Columnar nodes:
                            @foreach ($plan['columnar_nodes'] as $n)
                                <code class="rounded bg-emerald-50 px-1">{{ $n }}</code>
                            @endforeach
                        </p>
                    @endif

                    <pre class="overflow-x-auto rounded bg-slate-900 p-3 text-xs leading-relaxed text-slate-100">{{ $plan['plan'] }}</pre>
                @endif
            </div>
        </details>

        {{-- ---------- the SQL ---------- --}}
        @if ($sql !== null)
            <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
                <summary class="cursor-pointer px-4 py-3 text-sm font-medium">
                    SQL
                    <span class="ml-2 font-normal text-slate-500">one statement, nine CTEs</span>
                </summary>
                <div class="border-t border-slate-200 px-4 py-3">
                    <pre class="overflow-x-auto rounded bg-slate-900 p-3 text-xs leading-relaxed text-slate-100">{{ $sql }}</pre>
                </div>
            </details>
        @endif
    @endif

    <script>
        // Wall clock as the browser experienced it. The server figure stops at
        // view dispatch, so a large gap here is HTML rendering or transfer
        // rather than anything the database did.
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
</div>
</body>
</html>
