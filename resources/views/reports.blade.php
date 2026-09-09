<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>duckpoc — reports</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="mx-auto max-w-4xl px-6 py-10">

    <header class="mb-8">
        <h1 class="text-2xl font-semibold">duckpoc</h1>
        <p class="mt-1 text-sm text-slate-600">
            Figured reports rebuilt as single DuckDB queries over DuckLake/Parquet in GCS,
            with dimension data in MySQL.
        </p>
    </header>

    <div class="space-y-4">

        <a href="{{ route('cashflow') }}"
           class="block rounded-lg border border-slate-200 bg-white p-5 shadow-sm hover:border-slate-400">
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="text-lg font-semibold">Cash Flow</h2>
                <span class="rounded bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">
                    parity 84/84
                </span>
            </div>
            <p class="mt-2 text-sm text-slate-600">
                The whole report as one query — sections, the chained calculation rows, and the
                opening/closing running balance as window functions. Output matches Figured's
                real report cell for cell.
            </p>
            <p class="mt-2 text-xs text-slate-500">
                Exercises: actuals + forecast across a movable horizon · partition pruning · 880K-row volume
            </p>
        </a>

        <a href="{{ route('tracker-cashflow') }}"
           class="block rounded-lg border border-slate-200 bg-white p-5 shadow-sm hover:border-slate-400">
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="text-lg font-semibold">Cash Flow — per-tracker sections</h2>
                <span class="rounded bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">
                    50 trackers ≈ 1 tracker
                </span>
            </div>
            <p class="mt-2 text-sm text-slate-600">
                The same report with income and direct costs broken out per livestock tracker.
                Figured resolves these with a query per tracker; here they all come out of one
                grouped scan, so the generated SQL is identical for 1 tracker and for 50.
            </p>
            <p class="mt-2 text-xs text-slate-500">
                Exercises: per-tracker sections · tracker count as a GROUP BY cardinality rather than a query multiplier
            </p>
        </a>


        <a href="{{ route('gross-margin') }}"
           class="block rounded-lg border border-slate-200 bg-white p-5 shadow-sm hover:border-slate-400">
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="text-lg font-semibold">Gross Margin — per operating entity</h2>
                <span class="rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                    the slow one in Figured
                </span>
            </div>
            <p class="mt-2 text-sm text-slate-600">
                Income, direct costs <em>and stock quantities</em> per tracker, with margin expressed per
                head. This is the report Figured's ex-CTO named as the real bottleneck — it needs a
                per-tracker running stock balance, which Figured computes with a query per tracker,
                twice per report.
            </p>
            <p class="mt-2 text-xs text-slate-500">
                Exercises: per-tracker quantities · stateful opening → movements → closing as one window
                function · federated join across GCS journals and MySQL stock movements
            </p>
        </a>

        <a href="{{ route('gross-margin-v2') }}"
           class="block rounded-lg border border-slate-300 bg-white p-5 shadow-sm ring-1 ring-slate-200 hover:border-slate-400">
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="text-lg font-semibold">Gross Margin V2 — mixed enterprise</h2>
                <span class="rounded bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">
                    milk + livestock, 1 query
                </span>
            </div>
            <p class="mt-2 text-sm text-slate-600">
                The real report's shape: sections nested under Income and Direct Costs, mixing milk and
                livestock enterprises on one farm, with a Gross Margin line and Actual/Forecast per column.
                Figured needs two separate reports for this — milk and livestock quantities come from
                different services and the two structure builders exclude each other.
            </p>
            <p class="mt-2 text-xs text-slate-500">
                Exercises: 4 aggregation levels from one scan via GROUPING SETS · hierarchy as data, not
                builder branching · two different quantity shapes (milk as a flow, livestock as a running
                balance) in one statement
            </p>
        </a>
    </div>

    <section class="mt-10 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-sm font-semibold">Not built yet</h2>
        <ul class="mt-2 space-y-1 text-sm text-slate-600">
            <li>
                <span class="font-medium text-slate-900">Livestock valuation</span> — stock is now
                tracked in <em>head</em>; valuing it in dollars (national standard cost, market value)
                is the remaining half.
            </li>
            <li>
                <span class="font-medium text-slate-900">Overdraft interest</span> — a virtual
                journal computed against the Cash Flow output.
            </li>
        </ul>
    </section>

    <p class="mt-8 text-xs text-slate-500">
        Empty report? Seed it first —
        <code class="rounded bg-slate-100 px-1">php artisan duckdb:cashflow:seed</code> or
        <code class="rounded bg-slate-100 px-1">php artisan duckdb:tracker:seed</code>.
        See the README for the full command list and measured results.
    </p>

</div>
</body>
</html>
