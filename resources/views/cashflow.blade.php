<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cash Flow</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="mx-auto max-w-7xl px-6 py-8">

    <header class="mb-8">
        <h1 class="text-2xl font-semibold">Cash Flow</h1>
        <p class="mt-1 text-sm text-slate-600">
            Figured's Cash Flow report computed as a single DuckDB query over DuckLake/Parquet in GCS.
        </p>
    </header>

    {{-- Report parameters --}}
    <form method="GET" class="mb-8 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
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
                                {{ $option['farm_id'] }}
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
                    <option value="cash" @selected($basis === 'cash')>cash</option>
                    <option value="accrual" @selected($basis === 'accrual')>accrual</option>
                </select>
            </label>
        </div>

        <div class="mt-4 flex items-center gap-4">
            <button type="submit"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                Run report
            </button>

            @if ($farm !== null)
                <span class="text-xs text-slate-500">
                    Partition:
                    <code class="rounded bg-slate-100 px-1 py-0.5">farm_type={{ $farm['farm_type'] }}</code>
                    <code class="rounded bg-slate-100 px-1 py-0.5">region={{ $farm['region'] }}</code>
                </span>
            @endif

            @if ($elapsedMs !== null)
                <span class="text-xs text-slate-500">
                    Query + DuckLake attach:
                    <strong class="text-slate-900">{{ number_format($elapsedMs, 1) }} ms</strong>
                </span>
            @endif
        </div>
    </form>

    @if ($error)
        <div class="mb-8 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-medium">Query failed</p>
            <p class="mt-1 font-mono text-xs">{{ $error }}</p>
        </div>
    @endif

    {{-- The report --}}
    @if (!empty($rows))
        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50">
                        <th class="px-4 py-2 text-left font-medium text-slate-600">Row</th>
                        @foreach ($rows as $row)
                            <th class="px-3 py-2 text-right font-medium text-slate-600 whitespace-nowrap">
                                {{ $row['month'] }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reportRows as $definition)
                        <tr class="border-b border-slate-100 {{ $definition['isSubtotal'] ? 'bg-slate-50 font-semibold' : '' }}">
                            <td class="px-4 py-1.5 whitespace-nowrap">{{ $definition['label'] }}</td>
                            @foreach ($rows as $row)
                                @php
                                    $value = (float) ($row[$definition['field']] ?? 0);
                                    // Accounting convention, matching Figured's own
                                    // reportNumberFormat.js: negatives are bracketed,
                                    // zero renders as a dash.
                                    $formatted = abs($value) < 0.005
                                        ? '–'
                                        : ($value < 0
                                            ? '(' . number_format(abs($value), 2) . ')'
                                            : number_format($value, 2));
                                @endphp
                                <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap
                                           {{ $value < 0 ? 'text-red-700' : '' }}">
                                    {{ $formatted }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Parquet files DuckLake holds for the tables this report reads --}}
    @if (!empty($files))
        @php
            $inScope = array_values(array_filter($files, fn ($f) => $f['in_scope']));
            $outOfScope = array_values(array_filter($files, fn ($f) => !$f['in_scope']));
            $inScopeBytes = array_sum(array_column($inScope, 'bytes'));
        @endphp

        <details class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm" open>
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
                    <strong>not</strong> pruning, so DuckDB likely opens more year partitions than
                    the count above implies.
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

    {{-- The transaction lines the report actually consumed --}}
    @if (!empty($sourceRows) || $sourceSummary['n'] > 0)
        <details class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Source transactions
                <span class="ml-2 font-normal text-slate-500">
                    {{ number_format($sourceSummary['n']) }} row(s) in scope
                    · net {{ number_format($sourceSummary['net_dollars'], 2) }}
                </span>
            </summary>

            <div class="border-t border-slate-200 px-5 py-4">
                <p class="mb-3 text-xs text-slate-500">
                    These are the lines the report consumed — same in-scope predicate as the
                    report itself (farm, cohort, basis, period, and the actuals/forecast
                    horizon split), so this is not a re-derived approximation.
                    <strong>Amount (raw)</strong> is the stored fixed-point integer:
                    revenue is a credit and therefore negative, expenses positive.
                    @if ($sourceSummary['n'] > count($sourceRows))
                        <br>Showing the first {{ number_format(count($sourceRows)) }}
                        of {{ number_format($sourceSummary['n']) }} rows.
                    @endif
                </p>

                <div class="max-h-96 overflow-auto">
                    <table class="min-w-full text-xs">
                        <thead class="sticky top-0 bg-white">
                            <tr class="border-b border-slate-200 text-left text-slate-600">
                                <th class="py-1.5 pr-4 font-medium">Date</th>
                                <th class="py-1.5 pr-4 font-medium">Type</th>
                                <th class="py-1.5 pr-4 font-medium">Account</th>
                                <th class="py-1.5 pr-4 font-medium">Class</th>
                                <th class="py-1.5 pr-4 font-medium">Category</th>
                                <th class="py-1.5 pr-4 text-right font-medium">Amount (raw)</th>
                                <th class="py-1.5 pr-4 text-right font-medium">Dollars</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sourceRows as $row)
                                @php $dollars = (float) $row['amount_dollars']; @endphp
                                <tr class="border-b border-slate-100">
                                    <td class="py-1 pr-4 font-mono whitespace-nowrap">{{ $row['date'] }}</td>
                                    <td class="py-1 pr-4 whitespace-nowrap">
                                        <span class="rounded px-1.5 py-0.5
                                            {{ $row['type'] === 'actuals' ? 'bg-sky-100 text-sky-800' : 'bg-violet-100 text-violet-800' }}">
                                            {{ $row['type'] }}
                                        </span>
                                    </td>
                                    <td class="py-1 pr-4 whitespace-nowrap">{{ $row['account_name'] }}</td>
                                    <td class="py-1 pr-4 font-mono whitespace-nowrap">{{ $row['account_class'] }}</td>
                                    <td class="py-1 pr-4 font-mono whitespace-nowrap">{{ $row['account_category'] }}</td>
                                    <td class="py-1 pr-4 text-right tabular-nums whitespace-nowrap
                                               {{ $dollars < 0 ? 'text-red-700' : '' }}">
                                        {{ number_format((int) $row['amount_raw']) }}
                                    </td>
                                    <td class="py-1 pr-4 text-right tabular-nums whitespace-nowrap
                                               {{ $dollars < 0 ? 'text-red-700' : '' }}">
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

    {{-- The generated SQL --}}
    @if ($sql)
        <details class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Generated DuckDB SQL
            </summary>
            <pre class="overflow-x-auto border-t border-slate-200 bg-slate-900 p-4 text-xs leading-relaxed text-slate-100"><code>{{ $sql }}</code></pre>
        </details>
    @endif


    <footer class="mt-8 text-xs text-slate-500">
        <p>
            Parity-checked against Figured's real report engine via
            <code class="rounded bg-slate-100 px-1 py-0.5">php artisan duckdb:cashflow:run</code>.
        </p>
    </footer>

</div>
</body>
</html>
