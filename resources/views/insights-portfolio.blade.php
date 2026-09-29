<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Portfolio Modelling — Insights on AlloyDB</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        .gain { color: #15803d; }
        .loss { color: #b91c1c; }
        .num { font-variant-numeric: tabular-nums; }
    </style>
</head>
<body class="bg-slate-100 text-slate-800">
@php
    $isAverage = $summary === 'average';
    $hasAssumptions = $assumptions !== [];
    $money = static function (?float $v): string {
        if ($v === null) { return ''; }
        if (abs($v) < 0.5) { return '0'; }
        return $v < 0 ? '(' . number_format(abs($v), 0) . ')' : number_format($v, 0);
    };
    $cellValue = static function (?array $cell, string $field) use ($isAverage): ?float {
        if ($cell === null) { return null; }
        return $isAverage ? $cell[$field] / max(1, $cell['farms']) : $cell[$field];
    };
    // FIP's variance colours: a rise is a gain on income and cash, a loss on a cost.
    $varianceClass = static function (?float $v, \App\Services\Insights\PortfolioLine $line): string {
        if ($v === null || abs($v) < 0.5) { return 'text-slate-400'; }
        $isCost = in_array($line, [\App\Services\Insights\PortfolioLine::Fertiliser, \App\Services\Insights\PortfolioLine::OtherOperatingExpenses, \App\Services\Insights\PortfolioLine::TotalOperatingExpenses], true);
        return ($v > 0) !== $isCost ? 'gain' : 'loss';
    };
    $query = static fn (array $overrides = []): string => http_build_query(array_filter(array_merge([
        'practice' => $practiceId,
        'basis' => $basis->value,
        'summary' => $summary,
        'line' => $selectedLine->value,
        'season' => $selectedSeason,
        'sort' => $sort,
        'a' => $specs,
    ], $overrides), static fn ($v) => $v !== null && $v !== []));
    $fy = static fn (int $season): string => 'FY' . $season;
    $totalRows = [\App\Services\Insights\PortfolioLine::TotalIncome, \App\Services\Insights\PortfolioLine::TotalOperatingExpenses, \App\Services\Insights\PortfolioLine::OperatingSurplus, \App\Services\Insights\PortfolioLine::ClosingCash, \App\Services\Insights\PortfolioLine::NetProfit];
@endphp

{{-- Page header, after FIP's MainContentHeader: icon + title, the summary strip, and the buttons. --}}
<header class="sticky top-0 z-10 border-b border-slate-200 bg-white shadow-sm">
    <div class="mx-auto flex max-w-[110rem] items-center justify-between px-6 pt-5">
        <div class="flex items-center gap-3">
            <svg class="h-6 w-6 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 6-6"/></svg>
            <h1 class="text-xl font-semibold text-slate-900">Portfolio Modelling</h1>
            <form method="GET" class="ml-4">
                <input type="hidden" name="basis" value="{{ $basis->value }}">
                <select name="practice" onchange="this.form.submit()" class="rounded border border-slate-300 bg-white px-2 py-1 text-sm">
                    @foreach ($practices as $p)
                        <option value="{{ $p['id'] }}" @selected($p['id'] === $practiceId)>{{ $p['name'] }} ({{ $p['farms'] }} farms)</option>
                    @endforeach
                </select>
            </form>
        </div>
        <div class="flex items-center gap-2">
            <a href="?{{ $query(['export' => 'csv']) }}" class="rounded border border-slate-300 bg-white px-3 py-1.5 text-sm hover:bg-slate-50">Download CSV</a>
            <a href="{{ route('reports') }}" class="rounded px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100">All reports</a>
        </div>
    </div>
    <div class="mx-auto flex max-w-[110rem] flex-wrap items-center gap-x-6 gap-y-2 px-6 pb-4 pt-3 text-sm text-slate-600">
        <span><strong class="text-slate-900">{{ number_format($farmCount) }}</strong> farms</span>
        <span>Actuals + Forecast · actuals to {{ $horizon }}</span>
        @if ($seasons !== [])
            <span>{{ $fy($seasons[0]) }} – {{ $fy(end($seasons)) }}</span>
        @endif
        <span class="inline-flex overflow-hidden rounded border border-slate-300">
            <a href="?{{ $query(['basis' => 'cash']) }}" class="px-3 py-1 {{ $basis->value === 'cash' ? 'bg-slate-800 text-white' : 'bg-white hover:bg-slate-50' }}">Cash</a>
            <a href="?{{ $query(['basis' => 'accrual']) }}" class="px-3 py-1 {{ $basis->value === 'accrual' ? 'bg-slate-800 text-white' : 'bg-white hover:bg-slate-50' }}">Accrual</a>
        </span>
        <span class="ml-auto inline-flex overflow-hidden rounded border border-slate-300">
            <a href="?{{ $query(['summary' => 'average']) }}" class="px-3 py-1 {{ $isAverage ? 'bg-slate-800 text-white' : 'bg-white hover:bg-slate-50' }}">Average per farm</a>
            <a href="?{{ $query(['summary' => 'total']) }}" class="px-3 py-1 {{ $isAverage ? 'bg-white hover:bg-slate-50' : 'bg-slate-800 text-white' }}">Total</a>
        </span>
    </div>
</header>

<main class="mx-auto max-w-[110rem] px-6 py-6">
    @if ($error)
        <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ $error }}</div>
    @endif

    <div class="flex flex-col gap-6 lg:flex-row">
        {{-- Chart, two thirds, like FIP's visual-chart. --}}
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm lg:w-2/3">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-semibold text-slate-900">{{ $selectedLine->label() }} <span class="font-normal text-slate-500">· {{ $isAverage ? 'average per farm' : 'portfolio total' }}</span></h2>
                <form method="GET">
                    @foreach (['practice' => $practiceId, 'basis' => $basis->value, 'summary' => $summary, 'season' => $selectedSeason, 'sort' => $sort] as $k => $v)
                        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                    @endforeach
                    @foreach ($specs as $spec)
                        <input type="hidden" name="a[]" value="{{ $spec }}">
                    @endforeach
                    <select name="line" onchange="this.form.submit()" class="rounded border border-slate-300 px-2 py-1 text-sm">
                        @foreach ($lines as $line)
                            <option value="{{ $line->value }}" @selected($line === $selectedLine)>{{ $line->label() }}</option>
                        @endforeach
                    </select>
                </form>
            </div>
            <div class="h-72"><canvas id="lineChart"></canvas></div>
        </section>

        {{-- Assumptions card, one third, after FIP's AssumptionsCard. --}}
        <section class="rounded-lg border border-slate-200 bg-white shadow-sm lg:w-1/3">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                <span class="font-semibold text-slate-900">Assumptions @if ($hasAssumptions)<span class="font-light text-slate-500">{{ count($assumptions) }}</span>@endif</span>
                @if ($hasAssumptions)
                    <a href="?{{ $query(['a' => null]) }}" class="text-sm text-slate-500 hover:text-slate-800">Clear all</a>
                @endif
            </div>
            <div class="px-5 py-4">
                @if ($hasAssumptions)
                    <div class="mb-4 flex flex-wrap gap-2">
                        @foreach ($assumptions as $i => $a)
                            <span class="inline-flex items-center gap-2 rounded-md bg-slate-700 px-2.5 py-1.5 text-sm font-light text-white">
                                {{ $a->line->label() }} {{ $fy($a->season) }}: {{ $a->percent > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($a->percent, 2), '0'), '.') }}%
                                <a href="?{{ $query(['a' => array_values(array_diff_key($specs, [$i => true]))]) }}" class="text-slate-300 hover:text-white" aria-label="Remove">✕</a>
                            </span>
                        @endforeach
                    </div>
                @else
                    <div class="mb-4 text-center">
                        <div class="mx-auto mb-2 flex h-14 w-14 items-center justify-center rounded-full bg-slate-200 text-2xl text-slate-500">≈</div>
                        <div class="font-semibold">No Assumptions set</div>
                        <p class="text-sm text-slate-500">What-if assumptions you add will be shown here.</p>
                        <a href="?{{ $query(['a' => ['milk_income:2027:-5', 'fertiliser:2027:10', 'milk_income:2028:3']]) }}" class="mt-2 inline-block text-sm text-emerald-700 underline">Try an example</a>
                    </div>
                @endif

                <form method="GET" class="grid grid-cols-6 items-end gap-2 border-t border-slate-100 pt-4">
                    @foreach (['practice' => $practiceId, 'basis' => $basis->value, 'summary' => $summary, 'line' => $selectedLine->value, 'season' => $selectedSeason, 'sort' => $sort] as $k => $v)
                        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                    @endforeach
                    @foreach ($specs as $spec)
                        <input type="hidden" name="a[]" value="{{ $spec }}">
                    @endforeach
                    <label class="col-span-3 text-xs text-slate-500">Line
                        <select name="new_line" class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm text-slate-800">
                            @foreach ($lines as $line)
                                @if ($line->isAssumable())
                                    <option value="{{ $line->value }}">{{ $line->label() }}</option>
                                @endif
                            @endforeach
                        </select>
                    </label>
                    <label class="col-span-2 text-xs text-slate-500">Season
                        <select name="new_season" class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm text-slate-800">
                            @foreach ($seasons as $season)
                                <option value="{{ $season }}" @selected($season === 2027)>{{ $fy($season) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="col-span-1 text-xs text-slate-500">%
                        <input name="new_percent" type="number" step="0.1" value="-5" class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm text-slate-800">
                    </label>
                    <button class="col-span-6 mt-1 rounded border border-amber-400 bg-amber-50 px-3 py-1.5 text-sm text-amber-800 hover:bg-amber-100">+ Add Assumption</button>
                </form>
            </div>
        </section>
    </div>

    {{-- The predict table: a line per row, Baseline per season, Modelled and Variance once there are assumptions. --}}
    <section class="mt-6 overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="bg-slate-50 text-slate-600">
                    <th class="sticky left-0 z-[1] bg-slate-50 px-4 py-2 text-left font-semibold">Period</th>
                    @foreach ($seasons as $season)
                        <th colspan="{{ $hasAssumptions ? 3 : 1 }}" class="border-l border-slate-200 px-4 py-2 text-center font-semibold {{ $season === $selectedSeason ? 'text-slate-900' : '' }}">{{ $fy($season) }}</th>
                    @endforeach
                </tr>
                <tr class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <th class="sticky left-0 z-[1] bg-slate-50 px-4 py-2 text-left font-medium">{{ $isAverage ? 'Average' : 'Total' }}</th>
                    @foreach ($seasons as $season)
                        <th class="border-l border-slate-200 px-4 py-2 text-right font-medium">Baseline</th>
                        @if ($hasAssumptions)
                            <th class="px-4 py-2 text-right font-medium">Modelled</th>
                            <th class="px-4 py-2 text-right font-medium">Variance</th>
                        @endif
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    @php $isTotal = in_array($line, $totalRows, true); @endphp
                    <tr class="border-b border-slate-100 {{ $isTotal ? 'bg-slate-50/60 font-semibold' : '' }} {{ $line === $selectedLine ? 'outline outline-1 -outline-offset-1 outline-emerald-300' : '' }}">
                        <td class="sticky left-0 z-[1] whitespace-nowrap px-4 py-2 {{ $isTotal ? 'bg-slate-50' : 'bg-white' }}">
                            <a href="?{{ $query(['line' => $line->value]) }}" class="hover:underline">{{ $line->label() }}</a>
                        </td>
                        @foreach ($seasons as $season)
                            @php
                                $cell = $totals[$line->value][$season] ?? null;
                                $variance = $cellValue($cell, 'variance');
                            @endphp
                            <td class="num border-l border-slate-100 px-4 py-2 text-right">{{ $money($cellValue($cell, 'original')) }}</td>
                            @if ($hasAssumptions)
                                <td class="num px-4 py-2 text-right">{{ $money($cellValue($cell, 'modelled')) }}</td>
                                <td class="num px-4 py-2 text-right {{ $varianceClass($variance, $line) }}">{{ $money($variance) }}</td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    {{-- Farm breakdown, after FIP's FarmBreakdown: every farm for the selected line and season. --}}
    <section class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-3">
            <h2 class="font-semibold text-slate-900">Farm breakdown <span class="font-normal text-slate-500">· {{ $selectedLine->label() }}</span></h2>
            <div class="flex flex-wrap gap-1 text-sm">
                <span class="mr-1 self-center text-slate-500">Period</span>
                @foreach ($seasons as $season)
                    <a href="?{{ $query(['season' => $season]) }}" class="rounded px-2.5 py-1 {{ $season === $selectedSeason ? 'bg-slate-800 text-white' : 'bg-slate-100 hover:bg-slate-200' }}">{{ $fy($season) }}</a>
                @endforeach
            </div>
        </div>
        <div class="max-h-[32rem] overflow-y-auto">
            <table class="min-w-full text-sm">
                <thead class="sticky top-0 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        @foreach (['farm' => 'Farm', 'region' => 'Region', 'type' => 'Type', 'original' => 'Baseline', 'modelled' => 'Modelled', 'variance' => 'Variance'] as $key => $label)
                            @php $sortable = in_array($key, ['farm', 'original', 'modelled', 'variance'], true); @endphp
                            @if ($key !== 'modelled' && $key !== 'variance' || $hasAssumptions)
                                <th class="px-4 py-2 font-medium {{ in_array($key, ['original', 'modelled', 'variance'], true) ? 'text-right' : 'text-left' }}">
                                    @if ($sortable)
                                        <a href="?{{ $query(['sort' => $key]) }}" class="{{ $sort === $key ? 'text-slate-900' : '' }} hover:underline">{{ $label }}</a>
                                    @else
                                        {{ $label }}
                                    @endif
                                </th>
                            @endif
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($breakdown as $r)
                        <tr class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-4 py-1.5">{{ $r['farm']['name'] ?? $r['farm_id'] }}</td>
                            <td class="px-4 py-1.5 text-slate-600">{{ $r['farm']['region'] ?? '' }}</td>
                            <td class="px-4 py-1.5 text-slate-600">{{ $r['farm']['type'] ?? '' }}</td>
                            <td class="num px-4 py-1.5 text-right">{{ $money($r['original']) }}</td>
                            @if ($hasAssumptions)
                                <td class="num px-4 py-1.5 text-right">{{ $money($r['modelled']) }}</td>
                                <td class="num px-4 py-1.5 text-right {{ $varianceClass($r['variance'], $selectedLine) }}">{{ $money($r['variance']) }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <footer class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm text-emerald-900">
        Computed live from {{ number_format($journalLines) }} raw journal lines in AlloyDB, no synced copy:
        one statement for the whole portfolio in <strong>{{ $elapsedMs === null ? '–' : number_format($elapsedMs, 0) }} ms</strong>.
        Same report logic as the single-farm {{ $basis->value === 'cash' ? 'cash flow' : 'profit and loss' }} (<code>ReportLinesSqlBuilder</code>).
    </footer>
</main>

<script>
    (() => {
        const seasons = @json(array_map($fy, $seasons));
        const baseline = @json(array_map(fn (int $s) => $cellValue($totals[$selectedLine->value][$s] ?? null, 'original'), $seasons));
        const modelled = @json(array_map(fn (int $s) => $cellValue($totals[$selectedLine->value][$s] ?? null, 'modelled'), $seasons));
        const datasets = [{ label: 'Baseline', data: baseline, backgroundColor: '#94a3b8', borderRadius: 3 }];
        if (@json($hasAssumptions)) {
            datasets.push({ label: 'Modelled', data: modelled, backgroundColor: '#047857', borderRadius: 3 });
        }
        new Chart(document.getElementById('lineChart'), {
            type: 'bar',
            data: { labels: seasons, datasets },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: (c) => `${c.dataset.label}: $${Math.round(c.parsed.y).toLocaleString('en-NZ')}` } },
                },
                scales: { y: { ticks: { callback: (v) => '$' + Number(v).toLocaleString('en-NZ', { notation: 'compact' }) } } },
            },
        });
    })();
</script>
</body>
</html>
