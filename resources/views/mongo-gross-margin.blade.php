<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gross Margin — MongoDB baseline</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="mx-auto max-w-[110rem] px-6 py-8">

    @php
        $money = static function (?float $v): string {
            if ($v === null || abs($v) < 0.005) { return '-'; }
            return $v < 0 ? '(' . number_format(abs($v), 0) . ')' : number_format($v, 0);
        };
        $bytes = static function (?int $b): string {
            if ($b === null || $b <= 0) { return '0 B'; }
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $i = (int) floor(log($b, 1024));
            $i = min($i, count($units) - 1);
            return number_format($b / (1024 ** $i), $i === 0 ? 0 : 1) . ' ' . $units[$i];
        };
    @endphp

    <header class="mb-6">
        <h1 class="text-2xl font-semibold">Gross Margin — the current stack</h1>
        <p class="mt-1 text-sm text-slate-600">
            The same report as every other page here, assembled the way Figured assembles it today:
            <strong>journals in MongoDB</strong>, <strong>dimensions in MySQL</strong>, and
            <strong>PHP in between</strong> because no query can span the two.
        </p>
        <p class="mt-2 text-sm text-slate-600">
            This is the baseline, not a fourth candidate. It exists so the DuckDB and AlloyDB
            numbers have something to be measured against.
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
            <strong class="font-semibold">Report failed:</strong> {{ $error }}
        </div>
    @endif

    @if ($farm !== null && $timings !== null)
        @php
            $total = max(0.001, $timings['total_ms']);
            $share = static fn (float $ms): float => ($ms / $total) * 100;
            $mongoPct = $share($timings['mongo_ms']);
            $mysqlPct = $share($timings['mysql_ms']);
            $phpPct = $share($timings['php_ms']);
            $engineDominant = $mongoPct >= 50.0;
        @endphp

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Enterprise types</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ count($trackerBreakdown) }}</p>
                <p class="mt-1 text-xs text-slate-500">
                    @foreach ($trackerBreakdown as $b){{ $b['tracker_type'] }} ({{ $b['n'] }}){{ !$loop->last ? ', ' : '' }}@endforeach
                </p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Report time</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($timings['total_ms'], 0) }} ms</p>
                <p class="mt-1 text-xs text-slate-500">
                    {{ count($queries) }} Mongo {{ Str::plural('query', count($queries)) }} + MySQL + PHP
                </p>
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
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Lines processed</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">
                    @if ($linesProcessed !== null)
                        {{ number_format($linesProcessed) }}
                    @else
                        &mdash;
                    @endif
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    journal lines aggregated by Mongo
                    @if ($summaryMs !== null)
                        · {{ $sourceSummary['n_groups'] }} groups · {{ $sourceSummary['n_trackers'] }} trackers
                    @endif
                </p>
            </div>
        </div>

        {{-- ---------- the number this page exists for ---------- --}}
        <div class="mb-8 rounded-lg border-2 {{ $engineDominant ? 'border-amber-300 bg-amber-50' : 'border-blue-300 bg-blue-50' }} p-5 shadow-sm">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-700">Where the time went</h2>

            <div class="mt-3 flex h-8 w-full overflow-hidden rounded">
                <div class="flex items-center justify-center bg-emerald-600 text-xs font-medium text-white"
                     style="width: {{ max(1, $mongoPct) }}%" title="MongoDB">
                    @if ($mongoPct >= 8){{ number_format($mongoPct, 0) }}%@endif
                </div>
                <div class="flex items-center justify-center bg-sky-600 text-xs font-medium text-white"
                     style="width: {{ max(1, $mysqlPct) }}%" title="MySQL">
                    @if ($mysqlPct >= 8){{ number_format($mysqlPct, 0) }}%@endif
                </div>
                <div class="flex items-center justify-center bg-slate-700 text-xs font-medium text-white"
                     style="width: {{ max(1, $phpPct) }}%" title="PHP">
                    @if ($phpPct >= 8){{ number_format($phpPct, 0) }}%@endif
                </div>
            </div>

            <div class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                <div>
                    <span class="inline-block h-2 w-2 rounded-full bg-emerald-600"></span>
                    <span class="font-medium">MongoDB</span>
                    <span class="tabular-nums">{{ number_format($timings['mongo_ms'], 1) }} ms</span>
                    <span class="text-slate-500">({{ number_format($mongoPct, 1) }}%)</span>
                    <p class="mt-0.5 text-xs text-slate-600">grouped sums of journal lines</p>
                </div>
                <div>
                    <span class="inline-block h-2 w-2 rounded-full bg-sky-600"></span>
                    <span class="font-medium">MySQL</span>
                    <span class="tabular-nums">{{ number_format($timings['mysql_ms'], 1) }} ms</span>
                    <span class="text-slate-500">({{ number_format($mysqlPct, 1) }}%)</span>
                    <p class="mt-0.5 text-xs text-slate-600">accounts, trackers, milk, stock movements</p>
                </div>
                <div>
                    <span class="inline-block h-2 w-2 rounded-full bg-slate-700"></span>
                    <span class="font-medium">PHP</span>
                    <span class="tabular-nums">{{ number_format($timings['php_ms'], 1) }} ms</span>
                    <span class="text-slate-500">({{ number_format($phpPct, 1) }}%)</span>
                    <p class="mt-0.5 text-xs text-slate-600">join, roll-up, stock chain, per-unit, sort</p>
                </div>
            </div>

            <p class="mt-4 text-xs text-slate-700">
                @if ($engineDominant)
                    <strong>Mongo dominates this run.</strong> At this volume the engine is the
                    constraint, so a faster engine would move the total.
                @else
                    <strong>Mongo is the minority of this run.</strong> Most of the time is spent
                    outside the database, doing work that exists only because facts and dimensions
                    are in different stores. A faster journal engine would not move the total much —
                    which is the finding, not a measurement error.
                @endif
            </p>
        </div>

        <div class="mb-8 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600 shadow-sm">
            <span class="font-medium text-slate-900">Two stores, no join:</span>
            Mongo returns <code class="rounded bg-slate-100 px-1">(account_id, month) → sum</code>
            and nothing else — no names, no ordering, no structure. The account list it filters on
            had to be read out of MySQL first and passed in as a literal
            <code class="rounded bg-slate-100 px-1">$in</code>. The three levels of the hierarchy,
            the gross margin line, the livestock running balance and the per-unit margins are all
            rebuilt in PHP afterwards, because the data they need is in the other database.
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
        {{-- ---------- the queries Mongo was asked ---------- --}}
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm" open>
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium">
                MongoDB queries
                <span class="ml-2 font-normal text-slate-500">
                    {{ count($queries) }} of a possible 4
                </span>
            </summary>
            <div class="border-t border-slate-200 px-4 py-3">
                <p class="mb-3 text-xs text-slate-600">
                    Figured partitions the period by budget type and issues one aggregation per
                    non-empty bucket — at most four for a whole report, and
                    <strong>independent of tracker count</strong>. A period straddling the horizon
                    costs two; one entirely in the past costs one. This is the part of the current
                    stack that already scales well, and it is worth being clear about that.
                </p>

                @if (empty($queries))
                    <p class="text-sm text-slate-500">No buckets in scope for this period.</p>
                @else
                    <table class="min-w-full text-xs">
                        <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-2 py-1 text-left font-medium">Budget type</th>
                            <th class="px-2 py-1 text-right font-medium">Groups returned</th>
                            <th class="px-2 py-1 text-right font-medium">Time</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        @foreach ($queries as $q)
                            <tr>
                                <td class="px-2 py-1">
                                    <span class="rounded px-1.5 py-0.5
                                                 {{ $q['bucket'] === 'actuals' ? 'bg-slate-100 text-slate-700' : 'bg-violet-100 text-violet-800' }}">
                                        {{ $q['bucket'] }}
                                    </span>
                                </td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ number_format($q['groups']) }}</td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ number_format($q['ms'], 1) }} ms</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif

                @if ($pipeline !== null)
                    <h3 class="mt-4 mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        The pipeline, as sent
                    </h3>
                    <p class="mb-2 text-xs text-slate-600">
                        Two stages. No <code class="rounded bg-slate-100 px-1">$lookup</code>, because
                        the accounts table is in another database entirely; no
                        <code class="rounded bg-slate-100 px-1">$setWindowFields</code>, because the
                        stock movements it would run over are in that same other database. Mongo is
                        being used as a sum-by-key engine, which is all this topology lets it be.
                    </p>
                    <pre class="overflow-x-auto rounded bg-slate-900 p-3 text-xs leading-relaxed text-slate-100">{{ $pipeline }}</pre>
                @endif

                <h3 class="mt-4 mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    What PHP does with the result
                </h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-2 py-1 text-left font-medium">Step</th>
                            <th class="px-2 py-1 text-left font-medium">DuckDB / AlloyDB</th>
                            <th class="px-2 py-1 text-left font-medium">Here</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        <tr>
                            <td class="px-2 py-1">Attach account names</td>
                            <td class="px-2 py-1 font-mono text-slate-500">JOIN account_scope</td>
                            <td class="px-2 py-1 font-mono">classify()</td>
                        </tr>
                        <tr>
                            <td class="px-2 py-1">Account / group / section subtotals</td>
                            <td class="px-2 py-1 font-mono text-slate-500">GROUPING SETS</td>
                            <td class="px-2 py-1 font-mono">rollUp()</td>
                        </tr>
                        <tr>
                            <td class="px-2 py-1">Gross margin line</td>
                            <td class="px-2 py-1 font-mono text-slate-500">derived CTE</td>
                            <td class="px-2 py-1 font-mono">marginRows()</td>
                        </tr>
                        <tr>
                            <td class="px-2 py-1">Livestock running balance</td>
                            <td class="px-2 py-1 font-mono text-slate-500">SUM(...) OVER (...)</td>
                            <td class="px-2 py-1 font-mono">stockUnits()</td>
                        </tr>
                        <tr>
                            <td class="px-2 py-1">Per-unit margin</td>
                            <td class="px-2 py-1 font-mono text-slate-500">projection</td>
                            <td class="px-2 py-1 font-mono">emit()</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </details>

        {{-- ---------- transaction lines ---------- --}}
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm" open>
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium">
                Transaction lines used
                <span class="ml-2 font-normal text-slate-500">
                    @if ($linesProcessed !== null)
                        {{ number_format($linesProcessed) }} lines
                    @endif
                    @if ($summaryMs !== null)
                        · net {{ $money($sourceSummary['net_dollars']) }}
                        · breakdown in {{ number_format($summaryMs, 0) }} ms
                    @else
                        · breakdown skipped
                    @endif
                </span>
            </summary>
            <div class="border-t border-slate-200 px-4 py-3">
                @if ($summaryMs === null)
                    <p class="text-sm text-slate-600">
                        @if ($linesProcessed !== null)
                            The report aggregated
                            <strong class="tabular-nums">{{ number_format($linesProcessed) }}</strong>
                            journal lines &mdash; counted inside the report's own aggregation, so it is
                            exact and costs nothing.
                        @endif
                    </p>
                    <p class="mt-2 text-sm text-slate-600">
                        The per-account breakdown below is skipped: it is a second pass over the same
                        documents, and the report took over {{ number_format($diagnosticsBudgetMs, 0) }} ms.
                        <a href="{{ request()->fullUrlWithQuery(['force_diagnostics' => 1]) }}"
                           class="text-blue-700 underline">Run it anyway</a>.
                    </p>
                @else
                    <p class="mb-3 text-xs text-slate-600">
                        Every journal line the report consumed, counted with the report's own scope
                        predicate — same farm, same basis, same period, same horizon rule. The
                        <strong>Actual/Forecast split</strong> is the column to read: a period
                        straddling the horizon should show both.
                    </p>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-2 py-1 text-left font-medium">Account</th>
                                <th class="px-2 py-1 text-left font-medium">Group</th>
                                <th class="px-2 py-1 text-right font-medium">Actuals</th>
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
                                    <td class="px-2 py-1 text-right tabular-nums">{{ number_format($b['n_actuals']) }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums text-violet-700">{{ number_format($b['n_forecast']) }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums font-medium">{{ number_format($b['n']) }}</td>
                                    <td class="px-2 py-1 tabular-nums text-slate-500">{{ $b['first_date'] }}</td>
                                    <td class="px-2 py-1 tabular-nums text-slate-500">{{ $b['last_date'] }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums">{{ $money($b['net_dollars']) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot class="border-t-2 border-slate-300 bg-slate-50 font-medium">
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
                            over the {{ number_format($sourceListingMaxRows) }} limit.
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

        {{-- ---------- storage ---------- --}}
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm" open>
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium">
                Collection &amp; cache
                <span class="ml-2 font-normal text-slate-500">
                    @if (($collection['error'] ?? null) === null)
                        {{ number_format($collection['documents'] ?? 0) }} documents ·
                        {{ $bytes($collection['storage_size'] ?? 0) }} on disk
                    @else
                        unavailable
                    @endif
                </span>
            </summary>
            <div class="border-t border-slate-200 px-4 py-3">
                @if (($collection['error'] ?? null) !== null)
                    <p class="text-sm text-red-700">{{ $collection['error'] }}</p>
                @else
                    <div class="grid grid-cols-2 gap-3 text-xs sm:grid-cols-5">
                        <div>
                            <p class="text-slate-500">Documents</p>
                            <p class="font-semibold tabular-nums">{{ number_format($collection['documents'] ?? 0) }}</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Uncompressed</p>
                            <p class="font-semibold tabular-nums">{{ $bytes($collection['size'] ?? 0) }}</p>
                        </div>
                        <div>
                            <p class="text-slate-500">On disk</p>
                            <p class="font-semibold tabular-nums">{{ $bytes($collection['storage_size'] ?? 0) }}</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Indexes</p>
                            <p class="font-semibold tabular-nums">{{ $bytes($collection['index_size'] ?? 0) }}</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Avg document</p>
                            <p class="font-semibold tabular-nums">{{ number_format($collection['avg_doc_size'] ?? 0) }} B</p>
                        </div>
                    </div>

                    <p class="mt-3 text-xs text-slate-600">
                        <strong>Average document size is the number to compare</strong> against the
                        lake's bytes-per-row and AlloyDB's column-store bytes-per-row. Mongo repeats
                        every field name in every document, so a journal line costs far more here
                        than the same line costs as a Parquet row.
                    </p>
                @endif

                @if (($server['error'] ?? null) === null && ($server['cache_max_bytes'] ?? null))
                    @php
                        $cachePct = ($server['cache_bytes'] ?? 0) / max(1, $server['cache_max_bytes']) * 100;
                    @endphp
                    <p class="mt-3 text-xs text-slate-600">
                        MongoDB {{ $server['version'] }} · WiredTiger cache
                        {{ $bytes($server['cache_bytes']) }} of {{ $bytes($server['cache_max_bytes']) }}
                        ({{ number_format($cachePct, 1) }}%).
                        A working set larger than this cache is the Mongo equivalent of AlloyDB's
                        column store overflowing — the same query, silently reading from disk.
                    </p>
                @elseif (($server['error'] ?? null) !== null)
                    <p class="mt-3 text-xs text-red-700">{{ $server['error'] }}</p>
                @endif
            </div>
        </details>

        {{-- ---------- explain ---------- --}}
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium">
                Query plan
                @if ($plan === null)
                    <span class="ml-2 font-normal text-slate-500">not captured</span>
                @elseif (($plan['error'] ?? null) !== null)
                    <span class="ml-2 rounded bg-red-100 px-1.5 py-0.5 text-xs font-normal text-red-800">failed</span>
                @elseif (($plan['index'] ?? null) !== null)
                    <span class="ml-2 rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-normal text-emerald-800">
                        index {{ $plan['index'] }}
                    </span>
                @else
                    <span class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-normal text-amber-800">
                        collection scan
                    </span>
                @endif
            </summary>
            <div class="border-t border-slate-200 px-4 py-3">
                @if ($plan === null)
                    <p class="text-sm text-slate-600">
                        <a href="{{ request()->fullUrlWithQuery(['explain' => 1]) }}"
                           class="text-blue-700 underline">Explain this query</a>
                        &mdash; runs the actuals bucket again under
                        <code class="rounded bg-slate-100 px-1">executionStats</code>,
                        so it costs a second execution.
                    </p>
                @elseif (($plan['error'] ?? null) !== null)
                    <p class="text-sm text-red-700">{{ $plan['error'] }}</p>
                @else
                    <div class="mb-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
                        <div>
                            <p class="text-slate-500">Execution</p>
                            <p class="font-semibold tabular-nums">{{ number_format($plan['ms'] ?? 0) }} ms</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Index keys examined</p>
                            <p class="font-semibold tabular-nums">{{ number_format($plan['keys_examined'] ?? 0) }}</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Documents examined</p>
                            <p class="font-semibold tabular-nums">{{ number_format($plan['docs_examined'] ?? 0) }}</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Index</p>
                            <p class="font-semibold">{{ $plan['index'] ?? 'none' }}</p>
                        </div>
                    </div>

                    <p class="mb-3 text-xs text-slate-600">
                        <strong>Documents examined</strong> is the figure that matters. Mongo has to
                        materialise and decode every document it aggregates — there is no columnar
                        path that reads only the two fields this pipeline sums. That is the
                        structural difference from both other engines, and it does not go away with
                        a better index.
                    </p>

                    @if (($plan['stage'] ?? null) !== null)
                        <pre class="overflow-x-auto rounded bg-slate-900 p-3 text-xs leading-relaxed text-slate-100">{{ $plan['stage'] }}</pre>
                    @endif
                @endif
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
