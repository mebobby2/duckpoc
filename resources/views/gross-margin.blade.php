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

    @if ($profile !== null && $profile['ok'])
        <div class="mb-4 rounded-lg border border-slate-200 bg-white p-4 text-xs text-slate-600 shadow-sm">
            <span class="font-medium text-slate-900">Query profile:</span>
            {{ number_format($profile['total_seconds'], 2) }}s total ·
            {{ number_format($profile['sql_seconds'], 2) }}s SQL engine ·
            {{ number_format($profile['http_gets'] ?? 0) }} HTTP GETs ·
            {{ $profile['bytes_in'] ?? '—' }} transferred
        </div>
    @elseif ($profile === null && $sql)
        <p class="mb-4 text-xs text-slate-500">
            <a class="underline" href="{{ request()->fullUrlWithQuery(['explain' => 1]) }}">Profile this query</a>
            — runs EXPLAIN ANALYZE, which re-executes it.
        </p>
    @endif

</div>
</body>
</html>
