<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cash Flow — Trackers</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="mx-auto max-w-7xl px-6 py-8">

    @php
        // Accounting convention, matching Figured's own reportNumberFormat.js:
        // negatives bracketed, zero as a dash.
        $money = static function (float $value): string {
            if (abs($value) < 0.005) {
                return '–';
            }

            return $value < 0
                ? '(' . number_format(abs($value), 2) . ')'
                : number_format($value, 2);
        };
    @endphp

    <header class="mb-6">
        <h1 class="text-2xl font-semibold">Cash Flow — per-tracker sections</h1>
        <p class="mt-1 text-sm text-slate-600">
            The same report, with income and direct costs broken out per livestock tracker.
            Figured resolves these with a query per tracker; this resolves all of them by
            grouping a single scan.
        </p>
        <p class="mt-2 text-sm">
            <a href="{{ route('cashflow') }}" class="text-blue-700 underline">← plain Cash Flow report</a>
        </p>
    </header>

    {{-- Report parameters --}}
    <form method="GET" class="mb-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Farm</span>
                @if (empty($farms))
                    <input type="text" name="farm_id" value="{{ $farmId }}"
                           class="w-full rounded border-slate-300 text-sm shadow-sm">
                @else
                    <select name="farm_id" class="w-full rounded border-slate-300 text-sm shadow-sm">
                        @foreach ($farms as $option)
                            <option value="{{ $option['farm_id'] }}"
                                @selected($option['farm_id'] === $farmId)>
                                {{ $option['farm_id'] }} ({{ $option['tracker_count'] }} tracker{{ $option['tracker_count'] == 1 ? '' : 's' }})
                            </option>
                        @endforeach
                    </select>
                @endif
            </label>

            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Period from</span>
                <input type="date" name="period_from" value="{{ $periodFrom }}"
                       class="w-full rounded border-slate-300 text-sm shadow-sm">
            </label>

            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Period to</span>
                <input type="date" name="period_to" value="{{ $periodTo }}"
                       class="w-full rounded border-slate-300 text-sm shadow-sm">
            </label>

            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">
                    Actuals horizon
                </span>
                <input type="date" name="horizon" value="{{ $horizon }}"
                       class="w-full rounded border-slate-300 text-sm shadow-sm">
                <span class="mt-1 block text-xs text-slate-500">actuals on/before, forecast after</span>
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
            <button type="submit"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
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

    {{-- The measurement under test --}}
    @if ($farm !== null && $reportMs !== null)
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Trackers</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($trackerCount) }}</p>
                <p class="mt-1 text-xs text-slate-500">from MySQL</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Consolidated report</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($reportMs, 0) }} ms</p>
                <p class="mt-1 text-xs text-slate-500">1 query, all trackers</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Tracker breakdown</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($detailMs, 0) }} ms</p>
                <p class="mt-1 text-xs text-slate-500">1 query, all trackers</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Queries issued</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">2</p>
                <p class="mt-1 text-xs text-slate-500">
                    Figured: 4 + {{ number_format($trackerCount) }}
                </p>
            </div>
        </div>

        <div class="mb-8 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600 shadow-sm">
            <span class="font-medium text-slate-900">Why 2, not {{ 4 + $trackerCount }}:</span>
            Figured's journal aggregation is capped at 4 Mongo queries (bucketed by interval type),
            but <code class="rounded bg-slate-100 px-1">LivestockStructureBuilder</code> loops per
            tracker and <code class="rounded bg-slate-100 px-1">LivestockQuantities</code> issues one
            more query for each. Here tracker count is a <code class="rounded bg-slate-100 px-1">GROUP BY</code>
            cardinality, so the generated SQL is byte-identical for 1 tracker and for 50.
            Verify with <code class="rounded bg-slate-100 px-1">php artisan duckdb:tracker:curve</code>.
        </div>
    @endif

    {{-- Consolidated report --}}
    @if (!empty($rows))
        <h2 class="mb-2 text-lg font-semibold">Consolidated</h2>
        <div class="mb-8 overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100">
                <tr>
                    <th class="px-3 py-2 text-left font-medium text-slate-600">Row</th>
                    @foreach ($rows as $row)
                        <th class="px-3 py-2 text-right font-medium text-slate-600 whitespace-nowrap">
                            {{ $row['month'] }}
                        </th>
                    @endforeach
                </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @foreach ($reportRows as $definition)
                    <tr class="{{ $definition['isSubtotal'] ? 'bg-slate-50 font-semibold' : '' }}">
                        <td class="px-3 py-1.5 whitespace-nowrap">{{ $definition['label'] }}</td>
                        @foreach ($rows as $row)
                            @php $value = (float) (string) ($row[$definition['field']] ?? 0); @endphp
                            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap
                                       {{ $value < 0 ? 'text-red-700' : '' }}">
                                {{ $money($value) }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Per-tracker breakdown --}}
    @if (!empty($trackerGrid))
        <h2 class="mb-1 text-lg font-semibold">
            Per-tracker gross profit
            <span class="ml-1 text-sm font-normal text-slate-500">
                ({{ count($trackerGrid) }} tracker{{ count($trackerGrid) === 1 ? '' : 's' }})
            </span>
        </h2>
        <p class="mb-2 text-sm text-slate-600">
            Every tracker's income less its direct costs. These are the rows Figured sums by
            building a formula string with one term per tracker
            (<code class="rounded bg-slate-100 px-1">implode(' + ', $trackerGrossProfitIds)</code>);
            the <span class="font-medium">Trackers Gross Profit</span> row above is their total.
        </p>
        <div class="mb-8 overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100">
                <tr>
                    <th class="px-3 py-2 text-left font-medium text-slate-600">Tracker</th>
                    <th class="px-3 py-2 text-left font-medium text-slate-600">Stock</th>
                    @foreach ($months as $month)
                        <th class="px-3 py-2 text-right font-medium text-slate-600 whitespace-nowrap">
                            {{ $month }}
                        </th>
                    @endforeach
                </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @foreach ($trackerGrid as $tracker)
                    <tr>
                        <td class="px-3 py-1.5 whitespace-nowrap">{{ $tracker['tracker_name'] }}</td>
                        <td class="px-3 py-1.5 whitespace-nowrap text-slate-500">{{ $tracker['stock_type'] }}</td>
                        @foreach ($months as $month)
                            @php
                                $value = $tracker['months'][$month]['tracker_gross_profit'] ?? 0.0;
                            @endphp
                            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap
                                       {{ $value < 0 ? 'text-red-700' : '' }}">
                                {{ $money((float) $value) }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Generated SQL --}}
    @if ($sql)
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium">
                Generated SQL — consolidated report
                <span class="ml-1 font-normal text-slate-500">
                    ({{ number_format(strlen($sql)) }} bytes, identical for any tracker count)
                </span>
            </summary>
            <pre class="overflow-x-auto border-t border-slate-200 px-4 py-3 text-xs leading-relaxed">{{ $sql }}</pre>
        </details>
    @endif

    @if ($detailSql)
        <details class="mb-8 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium">
                Generated SQL — per-tracker breakdown
                <span class="ml-1 font-normal text-slate-500">
                    ({{ number_format(strlen($detailSql)) }} bytes)
                </span>
            </summary>
            <pre class="overflow-x-auto border-t border-slate-200 px-4 py-3 text-xs leading-relaxed">{{ $detailSql }}</pre>
        </details>
    @endif

</div>
</body>
</html>
