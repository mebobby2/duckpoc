<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cash Flow — actuals + forecast</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="mx-auto max-w-[96rem] px-6 py-8">

    @php
        // Figured's reportNumberFormat.js: negatives bracketed, zero a dash.
        $money = static function (mixed $value): string {
            if ($value === null) {
                return '';
            }
            $value = (float) (string) $value;
            if (abs($value) < 0.005) {
                return '–';
            }

            return $value < 0
                ? '(' . number_format(abs($value), 2) . ')'
                : number_format($value, 2);
        };
        $columnBadge = [
            'actuals' => 'bg-sky-100 text-sky-800',
            'actualsForecast' => 'bg-amber-100 text-amber-800',
            'forecast' => 'bg-violet-100 text-violet-800',
            'total' => 'bg-slate-200 text-slate-700',
        ];
        $columns = $farmRows;
    @endphp

    <header class="mb-6">
        <h1 class="text-2xl font-semibold">Cash Flow — actuals + forecast</h1>
        <p class="mt-1 text-sm text-slate-600">
            Figured's <code class="rounded bg-slate-100 px-1">/reports/data/cash_flow?type=actualsForecast</code>
            as {{ $engineLabel }}: the from/to period, the actuals/forecast horizon,
            the milk, GST and overdraft virtual journals, per-tracker sections, the section chain, the running balance
            and the Total column are all inside the SQL. PHP binds five parameters and pivots the result.
        </p>
        <p class="mt-2 text-sm">
            <a href="{{ route('reports') }}" class="text-blue-700 underline">← all reports</a>
        </p>
    </header>

    {{-- Report parameters, named as Figured's URL names them --}}
    <form method="GET" class="mb-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-8">
            <label class="block col-span-2">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Farm</span>
                <select name="farm_id" class="w-full rounded border-slate-300 text-sm shadow-sm">
                    @foreach ($farms as $option)
                        <option value="{{ $option['farm_id'] }}" @selected($option['farm_id'] === $farmId)>
                            {{ $option['farm_id'] }} (balance date month {{ $option['financial_year_end_month'] }})
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">from</span>
                <input type="date" name="from" value="{{ $from }}"
                       class="w-full rounded border-slate-300 text-sm shadow-sm">
            </label>

            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">to</span>
                <input type="date" name="to" value="{{ $to }}"
                       class="w-full rounded border-slate-300 text-sm shadow-sm">
                <span class="mt-1 block text-xs text-slate-500">inclusive; one column per month</span>
            </label>

            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">actuals_horizon</span>
                <input type="date" name="actuals_horizon" value="{{ $horizon }}"
                       class="w-full rounded border-slate-300 text-sm shadow-sm">
            </label>

            <label class="block">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">group_by</span>
                <select name="group_by" class="w-full rounded border-slate-300 text-sm shadow-sm">
                    <option value="tracker" @selected($options->groupByTracker)>tracker</option>
                    <option value="none" @selected(!$options->groupByTracker)>none</option>
                </select>
            </label>

            <div class="block col-span-2 lg:col-span-2">
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">options</span>
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    <label class="inline-flex items-center gap-1.5">
                        <input type="hidden" name="exclude_eoy_journals" value="0">
                        <input type="checkbox" name="exclude_eoy_journals" value="1" @checked($options->excludeEoyJournals)>
                        exclude_eoy_journals
                    </label>
                    <label class="inline-flex items-center gap-1.5">
                        <input type="hidden" name="with_total" value="0">
                        <input type="checkbox" name="with_total" value="1" @checked($options->withTotal)>
                        with_total
                    </label>
                    <label class="inline-flex items-center gap-1.5">
                        <input type="hidden" name="with_overdraft" value="0">
                        <input type="checkbox" name="with_overdraft" value="1" @checked($options->withOverdraft)>
                        with_overdraft
                    </label>
                    <label class="inline-flex items-center gap-1.5">
                        <input type="hidden" name="explain" value="0">
                        <input type="checkbox" name="explain" value="1" @checked(request()->boolean('explain'))>
                        explain
                    </label>
                </div>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2">
            <button type="submit"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                Run report
            </button>

            @if ($elapsedMs !== null)
                <span class="text-sm text-slate-600">
                    statement <strong class="text-slate-900">{{ number_format($elapsedMs, 1) }} ms</strong>
                    @if ($reportRequests !== null)
                        · {{ $reportRequests }} storage request(s)
                    @endif
                    @if ($serverMs !== null)
                        · page {{ number_format($serverMs, 0) }} ms
                    @endif
                </span>
            @endif

            <span class="text-xs text-slate-500">
                display=monthly · type=actualsForecast · basis={{ $options->basis }}
                @if ($sourceSummary['period_from'] !== '')
                    · period {{ $sourceSummary['period_from'] }} → {{ $sourceSummary['period_to'] }}
                @endif
            </span>
        </div>
    </form>

    @if ($error)
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-medium">Query failed</p>
            <p class="mt-1 font-mono text-xs whitespace-pre-wrap">{{ $error }}</p>
        </div>
    @endif

    @if (!empty($columns))
        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100">
                <tr>
                    <th class="sticky left-0 bg-slate-100 px-3 py-2 text-left font-medium text-slate-600">Row</th>
                    @foreach ($columns as $column)
                        <th class="px-3 py-2 text-right font-medium text-slate-600 whitespace-nowrap">
                            <div>{{ $column['month'] }}</div>
                            <span class="rounded px-1 py-0.5 text-[10px] font-normal {{ $columnBadge[$column['column_type']] ?? '' }}">
                                {{ $column['column_type'] }}
                            </span>
                        </th>
                    @endforeach
                </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">

                {{-- Income: the tracker blocks Figured nests under it --}}
                <tr class="bg-slate-50">
                    <td class="sticky left-0 bg-slate-50 px-3 py-1.5 font-semibold" colspan="{{ count($columns) + 1 }}">
                        Income
                        @if (!empty($trackerBlocks))
                            <span class="ml-1 text-xs font-normal text-slate-500">({{ count($trackerBlocks) }} tracker blocks)</span>
                        @endif
                    </td>
                </tr>
                @foreach ($trackerBlocks as $block)
                    <tr>
                        <td class="sticky left-0 bg-white px-3 py-1 pl-6 text-slate-700" colspan="{{ count($columns) + 1 }}">
                            {{ $block['tracker_name'] }}
                            <span class="ml-1 rounded bg-slate-100 px-1 py-0.5 text-[10px] text-slate-500">{{ $block['tracker_type'] }}</span>
                        </td>
                    </tr>
                    @foreach ($trackerDisplayRows as $definition)
                        <tr class="{{ $definition['isSubtotal'] ? 'font-medium' : 'text-slate-600' }}">
                            <td class="sticky left-0 bg-white px-3 py-1 pl-10 whitespace-nowrap">{{ $definition['label'] }}</td>
                            @foreach ($block['columns'] as $column)
                                @php $value = (float) (string) $column[$definition['field']]; @endphp
                                <td class="px-3 py-1 text-right tabular-nums whitespace-nowrap {{ $value < 0 ? 'text-red-700' : '' }}">
                                    {{ $money($value) }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach

                @foreach ($displayRows as $definition)
                    @php $indent = in_array($definition['kind'], ['section', 'rollup'], true) ? 'pl-6' : ''; @endphp
                    <tr class="{{ $definition['isSubtotal'] ? 'bg-slate-50 font-semibold' : '' }}">
                        <td class="sticky left-0 px-3 py-1.5 whitespace-nowrap {{ $indent }} {{ $definition['isSubtotal'] ? 'bg-slate-50' : 'bg-white' }}">
                            {{ $definition['label'] }}
                        </td>
                        @foreach ($columns as $column)
                            @php $raw = $column[$definition['field']]; $value = $raw === null ? null : (float) (string) $raw; @endphp
                            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap {{ $value !== null && $value < 0 ? 'text-red-700' : '' }}">
                                {{ $money($raw) }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Virtual journals the statement synthesised --}}
    @if (!empty($virtualJournalSummary))
        <details class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm" open>
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Virtual journals
                <span class="ml-2 font-normal text-slate-500">
                    @foreach ($virtualJournalSummary as $source => $summary)
                        {{ $source }} {{ number_format($summary['n']) }} ({{ $money($summary['net_dollars']) }}){{ $loop->last ? '' : ' · ' }}
                    @endforeach
                </span>
            </summary>
            <div class="border-t border-slate-200 px-5 py-4">
                <p class="mb-3 text-xs text-slate-500">
                    The three handlers Figured's cash flow fires at report time — 61% of the benchmarked run — as CTEs
                    over the same scan. <strong>milk_tracker</strong>: forecast milk income from production x payout per
                    tracker, paid the 20th of the following month. <strong>gst_payments_refunds</strong>: the predicted
                    two-monthly settlement per return period after the horizon, and each actual settlement moved from the
                    GST line to the payments line. <strong>overdraft</strong>: interest on the overdrawn position, a
                    recursive CTE, posted monthly.
                    @if (count($virtualJournals) >= $rowLimit)
                        <br>Showing the first {{ number_format($rowLimit) }}.
                    @endif
                </p>
                <div class="max-h-96 overflow-auto">
                    <table class="min-w-full text-xs">
                        <thead class="sticky top-0 bg-white">
                        <tr class="border-b border-slate-200 text-left text-slate-600">
                            <th class="py-1.5 pr-4 font-medium">Date</th>
                            <th class="py-1.5 pr-4 font-medium">Handler</th>
                            <th class="py-1.5 pr-4 font-medium">Account</th>
                            <th class="py-1.5 pr-4 font-medium">Section</th>
                            <th class="py-1.5 pr-4 font-medium">Tracker</th>
                            <th class="py-1.5 pr-4 text-right font-medium">Amount (stored sign)</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($virtualJournals as $row)
                            @php $dollars = (float) (string) $row['amount_dollars']; @endphp
                            <tr class="border-b border-slate-100">
                                <td class="py-1 pr-4 font-mono whitespace-nowrap">{{ $row['date'] }}</td>
                                <td class="py-1 pr-4 whitespace-nowrap">{{ $row['source'] }}</td>
                                <td class="py-1 pr-4 whitespace-nowrap">{{ $row['account_name'] }}</td>
                                <td class="py-1 pr-4 font-mono whitespace-nowrap">{{ $row['account_category'] }}</td>
                                <td class="py-1 pr-4 font-mono whitespace-nowrap text-slate-500">{{ $row['tracker_id'] ?? '—' }}</td>
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

    {{-- Storage requests --}}
    @if (!empty($storage))
        <details class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Storage
                <span class="ml-2 font-normal text-slate-500">
                    @foreach ($storage as $key => $value)
                        @if (is_scalar($value))
                            {{ $key }} {{ is_float($value) ? number_format($value, 1) : $value }}{{ $loop->last ? '' : ' · ' }}
                        @endif
                    @endforeach
                </span>
            </summary>
            <div class="border-t border-slate-200 px-5 py-4 text-xs text-slate-600">
                <pre class="overflow-x-auto">{{ json_encode($storage, JSON_PRETTY_PRINT) }}</pre>
            </div>
        </details>
    @endif

    {{-- Parquet files --}}
    @if (!empty($files))
        @php
            $inScope = array_values(array_filter($files, fn ($f) => $f['in_scope']));
            $inScopeBytes = array_sum(array_column($inScope, 'bytes'));
        @endphp
        <details class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Parquet files
                <span class="ml-2 font-normal text-slate-500">
                    {{ count($inScope) }} in scope ({{ number_format($inScopeBytes / 1024 / 1024, 1) }} MB)
                    · {{ count($files) - count($inScope) }} out of scope
                </span>
            </summary>
            <div class="border-t border-slate-200 px-5 py-4">
                <div class="max-h-96 overflow-auto">
                    <table class="min-w-full text-xs">
                        <thead class="sticky top-0 bg-white">
                        <tr class="border-b border-slate-200 text-left text-slate-600">
                            <th class="py-1.5 pr-4 font-medium">Scope</th>
                            <th class="py-1.5 pr-4 font-medium">Partition</th>
                            <th class="py-1.5 pr-4 font-medium">File</th>
                            <th class="py-1.5 pr-4 text-right font-medium">Size</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($files as $file)
                            <tr class="border-b border-slate-100 {{ $file['in_scope'] ? '' : 'text-slate-400' }}">
                                <td class="py-1 pr-4 whitespace-nowrap">{{ $file['in_scope'] ? 'read' : 'skippable' }}</td>
                                <td class="py-1 pr-4 font-mono whitespace-nowrap">{{ $file['partition'] ?? '—' }}</td>
                                <td class="py-1 pr-4 font-mono whitespace-nowrap">{{ \Illuminate\Support\Str::limit($file['name'], 34) }}</td>
                                <td class="py-1 pr-4 text-right tabular-nums whitespace-nowrap">{{ number_format($file['bytes'] / 1024, 1) }} KB</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </details>
    @endif

    {{-- Source transactions --}}
    @if ($sourceSummary['n'] > 0)
        <details class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Source journal lines
                <span class="ml-2 font-normal text-slate-500">
                    {{ number_format($sourceSummary['n']) }} in scope
                    · {{ number_format($sourceSummary['n_tracker_tagged']) }} tracker-tagged
                    · net {{ number_format($sourceSummary['net_dollars'], 2) }}
                </span>
            </summary>
            <div class="border-t border-slate-200 px-5 py-4">
                <p class="mb-3 text-xs text-slate-500">
                    The scan's predicate: farm, basis, the from/to period, actuals to the horizon and forecast after,
                    end-of-year tags dropped when <code class="rounded bg-slate-100 px-1">exclude_eoy_journals=1</code>.
                    Stored sign: revenue credit-negative, expense debit-positive.
                    @if ($sourceSummary['n'] > count($sourceRows))
                        Showing the first {{ number_format(count($sourceRows)) }}.
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
                            <th class="py-1.5 pr-4 font-medium">Tracker</th>
                            <th class="py-1.5 pr-4 font-medium">Tag</th>
                            <th class="py-1.5 pr-4 text-right font-medium">Dollars</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($sourceRows as $row)
                            @php $dollars = (float) (string) $row['amount_dollars']; @endphp
                            <tr class="border-b border-slate-100">
                                <td class="py-1 pr-4 font-mono whitespace-nowrap">{{ $row['date'] }}</td>
                                <td class="py-1 pr-4 whitespace-nowrap">
                                    <span class="rounded px-1.5 py-0.5 {{ $row['type'] === 'actuals' ? 'bg-sky-100 text-sky-800' : 'bg-violet-100 text-violet-800' }}">{{ $row['type'] }}</span>
                                </td>
                                <td class="py-1 pr-4 whitespace-nowrap">{{ $row['account_name'] }}</td>
                                <td class="py-1 pr-4 font-mono whitespace-nowrap">{{ $row['account_category'] }}</td>
                                <td class="py-1 pr-4 font-mono whitespace-nowrap text-slate-500">{{ $row['tracker_id'] ?? '—' }}</td>
                                <td class="py-1 pr-4 font-mono whitespace-nowrap text-slate-500">{{ $row['tag'] ?? '' }}</td>
                                <td class="py-1 pr-4 text-right tabular-nums whitespace-nowrap {{ $dollars < 0 ? 'text-red-700' : '' }}">{{ number_format($dollars, 2) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </details>
    @endif

    {{-- EXPLAIN ANALYZE --}}
    @if ($profile !== null)
        <details class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm" open>
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                EXPLAIN ANALYZE
                @if ($profile['ok'])
                    <span class="ml-2 font-normal text-slate-500">
                        total {{ number_format((float) $profile['total_seconds'], 3) }} s
                        · SQL {{ number_format((float) $profile['sql_seconds'], 3) }} s
                        · {{ $profile['http_gets'] ?? '?' }} GETs
                        · {{ $profile['bytes_in'] ?? '?' }} in
                    </span>
                @endif
            </summary>
            <div class="border-t border-slate-200 px-5 py-4">
                @if (!$profile['ok'])
                    <p class="text-xs text-red-700">{{ $profile['error'] }}</p>
                @else
                    <table class="mb-4 min-w-[24rem] text-xs">
                        <thead><tr class="border-b border-slate-200 text-left text-slate-600"><th class="py-1 pr-4">Operator</th><th class="py-1 text-right">Seconds</th></tr></thead>
                        <tbody>
                        @foreach (array_slice($profile['operators'], 0, 15) as $op)
                            <tr class="border-b border-slate-100"><td class="py-1 pr-4 font-mono">{{ $op['name'] }}</td><td class="py-1 text-right tabular-nums">{{ number_format($op['seconds'], 3) }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                    <details><summary class="cursor-pointer text-xs text-slate-500">raw plan</summary>
                        <pre class="mt-2 overflow-x-auto bg-slate-900 p-3 text-[10px] leading-tight text-slate-100">{{ $profile['raw'] }}</pre>
                    </details>
                @endif
            </div>
        </details>
    @endif

    {{-- The generated SQL --}}
    @if ($sql)
        <details class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                The statement
                <span class="ml-2 font-normal text-slate-500">{{ substr_count($sql, ' AS (') }} CTEs, one query</span>
            </summary>
            <pre class="overflow-x-auto border-t border-slate-200 bg-slate-900 p-4 text-xs leading-relaxed text-slate-100"><code>{{ $sql }}</code></pre>
        </details>
    @endif

    <footer class="mt-8 text-xs text-slate-500">
        Checked cell by cell on the oracle farm by
        <code class="rounded bg-slate-100 px-1 py-0.5">php artisan duckdb:cashflow-af:run</code>.
        Seed with <code class="rounded bg-slate-100 px-1 py-0.5">php artisan duckdb:cashflow-af:seed --lines=200000</code>.
    </footer>
</div>
</body>
</html>
