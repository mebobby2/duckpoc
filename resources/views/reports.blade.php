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

        <a href="{{ route('cashflow-actuals-plus-forecast') }}"
           class="block rounded-lg border border-emerald-300 bg-white p-5 shadow-sm ring-1 ring-emerald-200 hover:border-emerald-400">
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="text-lg font-semibold">Cash Flow — actuals + forecast, the whole request</h2>
                <span class="rounded bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">
                    one statement, oracle-checked
                </span>
            </div>
            <p class="mt-2 text-sm text-slate-600">
                Figured's <code class="rounded bg-slate-100 px-1">/reports/data/cash_flow?type=actualsForecast</code>
                as it was benchmarked: a financial-year period, actuals to a horizon and forecast after, per-tracker
                income blocks, EOY journals excluded, a Total column &mdash; and the three virtual-journal handlers
                (milk income, GST settlements, overdraft interest) that were 61% of the benchmarked 16 s, all as CTEs
                inside the same statement.
            </p>
            <p class="mt-2 text-xs text-slate-500">
                Exercises: a 13-milk-tracker NZ dairy farm with a May balance date &middot; <code class="rounded bg-slate-100 px-1">WITH RECURSIVE</code>
                for the interest recurrence &middot; every Figured option compiled into the SQL text &middot; MinIO
            </p>
        </a>

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

        <a href="{{ route('alloydb-gross-margin') }}"
           class="block rounded-lg border border-sky-300 bg-white p-5 shadow-sm ring-1 ring-sky-200 hover:border-sky-400">
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="text-lg font-semibold">Gross Margin — AlloyDB</h2>
                <span class="rounded bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800">
                    one database
                </span>
            </div>
            <p class="mt-2 text-sm text-slate-600">
                The same report, same SQL structure, served from AlloyDB — PostgreSQL 17 with an
                in-memory columnar engine. Journals, accounts, trackers, milk production and stock
                movements all live in one database, so the report is an ordinary join and there is
                no lake, no catalog and no maintenance to schedule.
            </p>
            <p class="mt-2 text-xs text-slate-500">
                Exercises: whether one relational database can serve both the app and its reports ·
                columnar engine capacity as the scaling limit · the same GROUPING SETS hierarchy
                running unmodified on a different engine
            </p>
        </a>

        <a href="{{ route('mongo-gross-margin') }}"
           class="block rounded-lg border border-slate-300 bg-slate-50 p-5 shadow-sm hover:border-slate-400">
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="text-lg font-semibold">Gross Margin V2 — MongoDB baseline</h2>
                <span class="rounded bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-800">
                    the current stack
                </span>
            </div>
            <p class="mt-2 text-sm text-slate-600">
                The same report on the topology Figured runs today: journals in MongoDB, dimensions
                in MySQL, and PHP joining them because no query can span the two. Not a fourth
                candidate &mdash; the baseline the other three are measured against.
            </p>
            <p class="mt-2 text-xs text-slate-500">
                Exercises: the Mongo / MySQL / PHP time split · 1&ndash;4 bucketed aggregations ·
                the roll-up, stock chain and per-unit margins rebuilt as PHP loops
            </p>
        </a>

        <a href="{{ route('overdraft') }}"
           class="block rounded-lg border border-slate-200 bg-white p-5 shadow-sm hover:border-slate-400">
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="text-lg font-semibold">Overdraft interest</h2>
                <span class="rounded bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">
                    parity with Figured
                </span>
            </div>
            <p class="mt-2 text-sm text-slate-600">
                Interest charged on the overdrawn cash position, month by month, posted per the
                repayment term. Configurable rate and term per farm.
            </p>
        </a>

        <a href="{{ route('alloydb-overdraft') }}"
           class="block rounded-lg border border-slate-200 bg-white p-5 shadow-sm hover:border-slate-400">
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="text-lg font-semibold">Overdraft interest — AlloyDB</h2>
                <span class="rounded bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">
                    parity with Figured
                </span>
            </div>
            <p class="mt-2 text-sm text-slate-600">
                The same recursive CTE on PostgreSQL 17. <code class="rounded bg-slate-100 px-1">WITH
                RECURSIVE</code>, <code class="rounded bg-slate-100 px-1">LATERAL</code> and window
                frames ported unchanged &mdash; only date functions and parameter syntax differed.
            </p>
            <p class="mt-2 text-xs text-slate-500">
                Exercises: the same recurrence on a second engine &middot; columnar coverage on a
                report with no selective predicate to prune with
            </p>
        </a>

        <a href="{{ route('valuation') }}"
           class="block rounded-lg border border-slate-200 bg-white p-5 shadow-sm hover:border-slate-400">
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="text-lg font-semibold">Livestock valuation movement</h2>
                <span class="rounded bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">
                    parity with Figured
                </span>
            </div>
            <p class="mt-2 text-sm text-slate-600">
                Figured builds a valuation object per tracker per interval and subtracts the previous
                one. Here: a running <code class="rounded bg-slate-100 px-1">SUM(...) OVER</code> for
                head count &times; per-head value, then
                <code class="rounded bg-slate-100 px-1">LAG</code> for the movement.
            </p>
            <p class="mt-2 text-xs text-slate-500">
                Exercises: one rule running unchanged on DuckDB and AlloyDB &middot; the report shape
                where the lake is not involved at all &middot; the actuals/forecast horizon split
            </p>
        </a>
    </div>

    <section class="mt-10 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-sm font-semibold">Not built yet</h2>
        <ul class="mt-2 space-y-1 text-sm text-slate-600">
            <li>
                <span class="font-medium text-slate-900">EOY tax valuation</span> — the statutory
                year-end schemes (national standard cost, herd scheme, AusTax). Deliberately out of
                scope: they are a once-a-year user-completed workflow, not a report-time calculation.
                The management valuation that <em>is</em> report-time is built, above.
            </li>
            <li>
                <span class="font-medium text-slate-900">BigQuery</span> — the portability claim is
                tested across DuckDB and AlloyDB but only asserted for BigQuery, from the dialect.
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
