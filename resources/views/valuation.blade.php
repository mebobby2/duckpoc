<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Livestock valuation movement — duckpoc</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="mx-auto max-w-7xl px-6 py-8">

    @php
        $money = static function (?float $v, int $dp = 2): string {
            if ($v === null) { return '—'; }
            return $v < 0 ? '(' . number_format(abs($v), $dp) . ')' : number_format($v, $dp);
        };
        $months = collect($totals)->pluck('month')->all();
        $byTracker = collect($rows)->groupBy('tracker_id');
    @endphp

    <header class="mb-6">
        <h1 class="text-2xl font-semibold">Livestock valuation movement</h1>
        <p class="mt-1 text-sm text-slate-600">
            Phase 2 — Figured's <code class="rounded bg-slate-100 px-1">NonCashMovementGenerator</code>
            builds a valuation object per tracker per interval and subtracts the previous one.
            Here it is a running <code class="rounded bg-slate-100 px-1">SUM(...) OVER</code> for head count,
            multiplied by the per-head value, then <code class="rounded bg-slate-100 px-1">LAG</code> for the movement.
            <strong>Not a recurrence</strong> — nothing feeds back into its own input, unlike overdraft interest.
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
                        <option value="{{ $f['farm_id'] }}" @selected($f['farm_id'] === $farmId)>{{ $f['farm_id'] }} ({{ $f['trackers'] }} trackers)</option>
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
        <p class="mt-3 text-xs text-slate-500">
            Movements on or before the horizon are read as <strong>actuals</strong>, after it as
            <strong>forecast</strong> — Figured's <code>addSplitDateRangeToQuery</code>. A horizon
            outside the seeded split drops one side, which is correct and looks like missing months.
        </p>
    </form>

    @if ($error)
        <div class="mb-4 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800">
            <strong>Query failed.</strong>
            <pre class="mt-2 overflow-x-auto whitespace-pre-wrap text-xs">{{ $error }}</pre>
        </div>
    @else

        {{-- Correctness first: a fast wrong report is worthless --}}
        <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="rounded-lg border {{ $oracle['mismatched'] === 0 && $oracle['checked'] > 0 ? 'border-emerald-300 bg-emerald-50' : 'border-red-300 bg-red-50' }} p-4">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-600">Parity vs Figured's loop</div>
                @if ($oracle['checked'] === 0)
                    <div class="mt-1 text-lg font-semibold text-red-800">no rows</div>
                @elseif ($oracle['mismatched'] === 0)
                    <div class="mt-1 text-lg font-semibold text-emerald-800">{{ number_format($oracle['checked']) }}/{{ number_format($oracle['checked']) }} match</div>
                @else
                    <div class="mt-1 text-lg font-semibold text-red-800">{{ number_format($oracle['mismatched']) }} mismatched</div>
                @endif
                <div class="mt-1 text-xs text-slate-600">
                    PHP transliteration of <code>NonCashMovementGenerator</code>, run live in
                    {{ $oracle['ms'] === null ? '—' : number_format($oracle['ms'], 1) . ' ms' }}
                </div>
            </div>

            <div class="rounded-lg border {{ $conservation && $conservation['drift'] <= 0.01 ? 'border-emerald-300 bg-emerald-50' : 'border-amber-300 bg-amber-50' }} p-4">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-600">Conservation</div>
                @if ($conservation === null)
                    <div class="mt-1 text-lg font-semibold text-slate-700">n/a</div>
                @else
                    <div class="mt-1 text-lg font-semibold {{ $conservation['drift'] <= 0.01 ? 'text-emerald-800' : 'text-amber-800' }}">
                        drift {{ number_format($conservation['drift'], 4) }}
                    </div>
                    <div class="mt-1 text-xs text-slate-600">
                        {{ $money($conservation['summed']) }} of movements vs
                        {{ $money($conservation['closing'] - $conservation['opening']) }} closing − opening
                    </div>
                @endif
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-600">DuckDB query</div>
                <div class="mt-1 text-lg font-semibold">{{ $elapsedMs === null ? '—' : number_format($elapsedMs, 1) . ' ms' }}</div>
                <div class="mt-1 text-xs text-slate-600">
                    {{ number_format(count($rows)) }} rows · page {{ $serverMs === null ? '—' : number_format($serverMs, 0) . ' ms' }}
                </div>
            </div>
        </div>

        {{-- The point of the phase --}}
        <div class="mb-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-slate-800">Same logic, two engines</h2>
            <p class="mt-1 text-xs text-slate-600">
                Latency is not what this phase is worth — the PHP loop above is competitive, and through
                the MySQL connector this statement is slower. The value is that the rule is written once
                and runs wherever the data does: the app, the warehouse, a notebook. This panel runs the
                same statement on AlloyDB and compares every cell, live.
            </p>
            @if ($crossEngine === null)
                <p class="mt-3 rounded bg-slate-50 p-3 text-sm text-slate-600">
                    AlloyDB has no stock data for <strong>{{ $farmId }}</strong>, so there is nothing to
                    compare. Only a few farms were copied across. Reported as missing rather than as a pass.
                </p>
            @else
                <div class="mt-3 flex flex-wrap items-center gap-6 text-sm">
                    <div>
                        <span class="rounded px-2 py-1 text-sm font-semibold {{ $crossEngine['matched'] === count($rows) && $crossEngine['rows'] === count($rows) ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' }}">
                            {{ number_format($crossEngine['matched']) }}/{{ number_format(count($rows)) }} cells identical
                        </span>
                    </div>
                    <div class="text-slate-600">DuckDB {{ number_format($elapsedMs ?? 0, 1) }} ms · AlloyDB {{ number_format($crossEngine['ms'], 1) }} ms</div>
                    <div class="text-slate-600">
                        movement totals {{ $money($crossEngine['sum_duck']) }} vs {{ $money($crossEngine['sum_pg']) }}
                        (diff {{ number_format(abs($crossEngine['sum_duck'] - $crossEngine['sum_pg']), 6) }})
                    </div>
                </div>
            @endif
        </div>

        {{-- Planning-grid layout: months as columns --}}
        <div class="mb-4 overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100 text-xs uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="sticky left-0 z-10 bg-slate-100 px-4 py-2 text-left">Tracker</th>
                        @foreach ($months as $m)
                            <th class="px-3 py-2 text-right whitespace-nowrap">{{ $m }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($byTracker as $trackerId => $trackerRows)
                        @php $byMonth = $trackerRows->keyBy('month'); @endphp
                        <tr class="bg-slate-50">
                            <td class="sticky left-0 z-10 bg-slate-50 px-4 py-2 font-medium" colspan="{{ count($months) + 1 }}">
                                {{ $trackerRows->first()['tracker_name'] ?? $trackerId }}
                                <span class="ml-2 text-xs font-normal text-slate-500">{{ $trackerRows->first()['stock_type'] ?? '' }}</span>
                            </td>
                        </tr>
                        <tr>
                            <td class="sticky left-0 z-10 bg-white px-4 py-1.5 pl-8 text-slate-600">Closing head</td>
                            @foreach ($months as $m)
                                <td class="px-3 py-1.5 text-right tabular-nums text-slate-700">
                                    {{ isset($byMonth[$m]) ? number_format((int) (string) $byMonth[$m]['closing_head']) : '—' }}
                                </td>
                            @endforeach
                        </tr>
                        <tr>
                            <td class="sticky left-0 z-10 bg-white px-4 py-1.5 pl-8 text-slate-600">Per head</td>
                            @foreach ($months as $m)
                                <td class="px-3 py-1.5 text-right tabular-nums text-slate-500">
                                    {{ isset($byMonth[$m]) ? $money((float) (string) $byMonth[$m]['per_head_dollars']) : '—' }}
                                </td>
                            @endforeach
                        </tr>
                        <tr>
                            <td class="sticky left-0 z-10 bg-white px-4 py-1.5 pl-8 text-slate-600">Closing valuation</td>
                            @foreach ($months as $m)
                                <td class="px-3 py-1.5 text-right tabular-nums">
                                    {{ isset($byMonth[$m]) ? $money((float) (string) $byMonth[$m]['closing_value_dollars']) : '—' }}
                                </td>
                            @endforeach
                        </tr>
                        <tr class="font-medium">
                            <td class="sticky left-0 z-10 bg-white px-4 py-1.5 pl-8">Valuation movement</td>
                            @foreach ($months as $m)
                                @php $v = isset($byMonth[$m]) ? (float) (string) $byMonth[$m]['movement_dollars'] : null; @endphp
                                <td class="px-3 py-1.5 text-right tabular-nums {{ $v !== null && $v < 0 ? 'text-red-700' : '' }}">
                                    {{ $money($v) }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach

                    <tr class="border-t-2 border-slate-300 bg-slate-100 font-semibold">
                        <td class="sticky left-0 z-10 bg-slate-100 px-4 py-2">Farm total — movement</td>
                        @foreach ($totals as $t)
                            @php $v = (float) (string) $t['movement_dollars']; @endphp
                            <td class="px-3 py-2 text-right tabular-nums {{ $v < 0 ? 'text-red-700' : '' }}">{{ $money($v) }}</td>
                        @endforeach
                    </tr>
                    <tr class="bg-slate-100 text-slate-600">
                        <td class="sticky left-0 z-10 bg-slate-100 px-4 py-2">Farm total — closing valuation</td>
                        @foreach ($totals as $t)
                            <td class="px-3 py-2 text-right tabular-nums">{{ $money((float) (string) $t['closing_value_dollars']) }}</td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="mb-6 text-xs text-slate-500">
            The virtual journal Figured posts is the <strong>movement</strong> row — one line per tracker
            per month against the tracker's mapped movement account, contra to stock on hand. The closing
            valuation rows are shown because a movement with no visible base is impossible to check.
        </p>

        {{-- Diagnostics --}}

        <details class="mb-4 rounded-lg border border-amber-300 bg-amber-50 shadow-sm" open>
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-amber-900">
                Parquet files — none, and that is the finding
            </summary>
            <div class="border-t border-amber-200 px-5 py-4 text-sm text-amber-900">
                <p>
                    The other reports list the Parquet files their query touched. This one reads
                    <strong>zero</strong>. Every table it needs — <code>trackers</code>,
                    <code>tracker_stock_movements</code>, <code>valuation_rates</code> — is relational
                    dimension data, reached through the MySQL connector. The lake is not involved.
                </p>
                <p class="mt-2">
                    That is why the statement loses to PHP here and wins by 3.8× over DuckDB-native
                    storage: the window functions cost about 7 ms, and everything else is moving rows
                    between engines. A report whose inputs are entirely relational is the one shape
                    where pushing work into DuckDB does not pay.
                </p>
            </div>
        </details>

        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Source stock movements
                @if ($sourceSummary !== [])
                    <span class="ml-2 font-normal text-slate-500">
                        {{ number_format((int) (string) ($sourceSummary['movement_rows'] ?? 0)) }} rows ·
                        {{ number_format((int) (string) ($sourceSummary['trackers'] ?? 0)) }} trackers ·
                        {{ $sourceRowsMs === null ? '—' : number_format($sourceRowsMs, 1) . ' ms' }}
                    </span>
                @endif
            </summary>
            <div class="border-t border-slate-200 px-5 py-4">
                @if ($sourceSummary !== [])
                    <div class="mb-3 flex flex-wrap gap-4 text-xs text-slate-600">
                        <span>purchases {{ number_format((int) (string) ($sourceSummary['purchases'] ?? 0)) }}</span>
                        <span>births {{ number_format((int) (string) ($sourceSummary['births'] ?? 0)) }}</span>
                        <span>sales {{ number_format((int) (string) ($sourceSummary['sales'] ?? 0)) }}</span>
                        <span>deaths {{ number_format((int) (string) ($sourceSummary['deaths'] ?? 0)) }}</span>
                    </div>
                @endif
                <p class="mb-3 text-xs text-slate-500">
                    Only movements inside the period. The running head count above also reads every month
                    <em>before</em> it — opening stock is history, not a stored column — so these rows
                    explain the movement but not the whole balance.
                </p>
                <div class="max-h-96 overflow-auto">
                    <table class="min-w-full text-xs">
                        <thead class="sticky top-0 bg-slate-100 text-slate-600">
                            <tr>
                                <th class="px-3 py-2 text-left">Tracker</th>
                                <th class="px-3 py-2 text-left">Month</th>
                                <th class="px-3 py-2 text-left">Type</th>
                                <th class="px-3 py-2 text-right">Purchases</th>
                                <th class="px-3 py-2 text-right">Births</th>
                                <th class="px-3 py-2 text-right">Sales</th>
                                <th class="px-3 py-2 text-right">Deaths</th>
                                <th class="px-3 py-2 text-right">Net</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($sourceRows as $r)
                                <tr>
                                    <td class="px-3 py-1">{{ $r['tracker_name'] }}</td>
                                    <td class="px-3 py-1 tabular-nums">{{ substr((string) $r['month'], 0, 10) }}</td>
                                    <td class="px-3 py-1">
                                        <span class="rounded px-1.5 py-0.5 {{ (string) $r['type'] === 'actuals' ? 'bg-slate-200 text-slate-700' : 'bg-blue-100 text-blue-800' }}">{{ $r['type'] }}</span>
                                    </td>
                                    <td class="px-3 py-1 text-right tabular-nums">{{ number_format((int) (string) $r['purchases']) }}</td>
                                    <td class="px-3 py-1 text-right tabular-nums">{{ number_format((int) (string) $r['births']) }}</td>
                                    <td class="px-3 py-1 text-right tabular-nums">{{ number_format((int) (string) $r['sales']) }}</td>
                                    <td class="px-3 py-1 text-right tabular-nums">{{ number_format((int) (string) $r['deaths']) }}</td>
                                    <td class="px-3 py-1 text-right tabular-nums font-medium">{{ number_format((int) (string) $r['net_movement']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if (count($sourceRows) >= $sourceRowLimit)
                    <p class="mt-2 text-xs text-slate-500">Capped at {{ number_format($sourceRowLimit) }} rows.</p>
                @endif
            </div>
        </details>

        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                The two statements, side by side
                <span class="ml-2 font-normal text-slate-500">4 hunks differ out of 66 lines, none in the window functions</span>
            </summary>
            <div class="grid grid-cols-1 gap-4 border-t border-slate-200 px-5 py-4 lg:grid-cols-2">
                <div>
                    <div class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-600">DuckDB</div>
                    <pre class="max-h-96 overflow-auto rounded bg-slate-900 p-3 text-xs text-slate-100">{{ $sql }}</pre>
                </div>
                <div>
                    <div class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-600">AlloyDB / Postgres</div>
                    <pre class="max-h-96 overflow-auto rounded bg-slate-900 p-3 text-xs text-slate-100">{{ $pgSql }}</pre>
                </div>
            </div>
            <div class="border-t border-slate-200 px-5 py-4 text-xs text-slate-600">
                Differences: cast syntax (<code>CAST(m AS DATE)</code> / <code>m::DATE</code>), the month
                spine (<code>generate_series ... AS g(m)</code> / <code>INTERVAL '1 month'</code>), the
                DuckDB-only pushdown subquery, and the month label (<code>strftime</code> /
                <code>to_char</code>). BigQuery needs
                <code>UNNEST(GENERATE_DATE_ARRAY(...))</code> and <code>FORMAT_DATE</code> —
                <strong>asserted from the dialect, not yet run</strong>.
            </div>
        </details>

        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Query profile
                <span class="ml-2 font-normal text-slate-500">{{ $profile === null ? 'add ?explain=1' : 'EXPLAIN ANALYZE' }}</span>
            </summary>
            <div class="border-t border-slate-200 px-5 py-4">
                @if ($profile === null)
                    <p class="text-sm text-slate-600">
                        Append <code class="rounded bg-slate-100 px-1">&amp;explain=1</code> to the URL. Off by
                        default because it runs the statement a second time.
                    </p>
                @else
                    <pre class="max-h-96 overflow-auto rounded bg-slate-900 p-3 text-xs text-slate-100">{{ is_string($profile) ? $profile : json_encode($profile, JSON_PRETTY_PRINT) }}</pre>
                @endif
            </div>
        </details>

        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                DuckDB trace
                <span class="ml-2 font-normal text-slate-500">{{ $trace === null ? 'add ?trace=1' : 'captured' }}</span>
            </summary>
            <div class="border-t border-slate-200 px-5 py-4">
                @if ($trace === null)
                    <p class="text-sm text-slate-600">
                        Append <code class="rounded bg-slate-100 px-1">&amp;trace=1</code>. The log is
                        per-process, so it is armed immediately before the query and read straight after.
                    </p>
                @else
                    <pre class="max-h-96 overflow-auto rounded bg-slate-900 p-3 text-xs text-slate-100">{{ is_string($trace) ? $trace : json_encode($trace, JSON_PRETTY_PRINT) }}</pre>
                @endif
            </div>
        </details>

    @endif
</div>
</body>
</html>
