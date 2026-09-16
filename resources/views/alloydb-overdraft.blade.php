<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Overdraft interest — AlloyDB</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="mx-auto max-w-6xl px-6 py-8">

    @php
        $money = static function (?float $v, int $dp = 2): string {
            if ($v === null) { return '—'; }
            return $v < 0 ? '(' . number_format(abs($v), $dp) . ')' : number_format($v, $dp);
        };
    @endphp

    <header class="mb-6">
        <h1 class="text-2xl font-semibold">Overdraft interest</h1>
        <p class="mt-1 text-sm text-slate-600">
            Phase 3 on <strong>AlloyDB</strong> — the same recursive CTE, ported to PostgreSQL 17.
            Interest is charged on a balance that <strong>excludes interest</strong>, so the running
            total of what has already accrued has to be subtracted to find the true overdrawn
            position. Month N's interest raises month N+1's charge base.
        </p>
        <p class="mt-2 text-sm text-slate-600">
            Everything lives in one database — journals, accounts, farms and the overdraft settings —
            so there is no federated join and no lake. <code class="rounded bg-slate-100 px-1">WITH
            RECURSIVE</code>, <code class="rounded bg-slate-100 px-1">LATERAL</code> and window
            frames ported unchanged; only the date functions and parameter syntax differed.
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
                        <option value="{{ $f['farm_id'] }}" @selected($f['farm_id'] === $farmId)>{{ $f['farm_id'] }}</option>
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
    </form>

    {{-- settings write to the overdrafts table, which is where the SQL reads them --}}
    <form method="POST" action="{{ route('alloydb-overdraft.save') }}" class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-5 shadow-sm">
        @csrf
        <input type="hidden" name="farm_id" value="{{ $farmId }}">
        <input type="hidden" name="period_from" value="{{ $periodFrom }}">
        <input type="hidden" name="period_to" value="{{ $periodTo }}">
        <input type="hidden" name="horizon" value="{{ $horizon }}">
        <p class="mb-3 text-xs text-slate-600">
            The rate and term live in the <code class="rounded bg-white px-1">overdrafts</code> table —
            the SQL reads them there rather than taking them as parameters, so changing them is a write.
        </p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-700">Annual rate %</span>
                <input type="number" step="0.01" min="0" max="100" name="rate"
                       value="{{ $config ? number_format($config['rate'] / 10000, 2, '.', '') : '5.00' }}"
                       class="w-full rounded border-slate-300 text-sm">
            </label>
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-700">Repayment term</span>
                <select name="payment_term" class="w-full rounded border-slate-300 text-sm">
                    @foreach ($terms as $t)
                        <option value="{{ $t }}" @selected($config && $config['payment_term'] === $t)>{{ $t }}</option>
                    @endforeach
                </select>
            </label>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
                    Save &amp; rerun
                </button>
            </div>
        </div>
    </form>

    @if ($error !== null)
        <div class="mb-6 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800">
            <strong class="font-semibold">Query failed:</strong> {{ $error }}
        </div>
    @endif

    @if ($elapsedMs !== null)
        @php
            $totalAccrued = 0.0; $totalPosted = 0.0;
            foreach ($rows as $r) {
                $totalAccrued += (float) (string) $r['interest_accrued'];
                if ($r['interest_posted'] !== null) { $totalPosted += (float) (string) $r['interest_posted']; }
            }
            $conserved = abs($totalAccrued - $totalPosted) < 0.005;
        @endphp

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Query time</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($elapsedMs, 0) }} ms</p>
                <p class="mt-1 text-xs text-slate-500">1 statement, recursive CTE</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Interest accrued</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $money($totalAccrued) }}</p>
                <p class="mt-1 text-xs text-slate-500">every month, whatever the term</p>
            </div>
            <div class="rounded-lg border {{ $conserved ? 'border-slate-200 bg-white' : 'border-red-300 bg-red-50' }} p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide {{ $conserved ? 'text-slate-500' : 'text-red-700' }}">Interest posted</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $money($totalPosted) }}</p>
                <p class="mt-1 text-xs {{ $conserved ? 'text-slate-500' : 'text-red-700' }}">
                    {{ $conserved ? 'conserved — nothing dropped' : 'LOST ' . $money($totalAccrued - $totalPosted) }}
                </p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Page load</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums" data-page-ms>&mdash;</p>
                <p class="mt-1 text-xs text-slate-500">
                    @if ($serverMs !== null) server {{ number_format($serverMs, 0) }} ms @endif
                </p>
            </div>
        </div>
    @endif

    @if (!empty($rows))
        @php
            // Rows are line items and columns are months, the way the Planning
            // Grid shows it. The report is read across a month, not down one.
            $cell = static function (?float $v, bool $blankZero = false) use ($money): string {
                if ($v === null || ($blankZero && abs($v) < 0.005)) { return '—'; }
                return $money($v);
            };
        @endphp

        <div class="mb-4 overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead>
                <tr class="bg-slate-100">
                    <th class="sticky left-0 z-10 bg-slate-100 px-3 py-2 text-left font-medium text-slate-600">Row</th>
                    @foreach ($rows as $r)
                        <th class="px-3 py-2 text-right font-medium text-slate-700 whitespace-nowrap">{{ $r['month'] }}</th>
                    @endforeach
                    <th class="px-3 py-2 text-right font-medium text-slate-700 border-l-2 border-slate-300">Total</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">

                <tr>
                    <td class="sticky left-0 bg-white px-3 py-1.5 whitespace-nowrap">Opening Balance</td>
                    @foreach ($rows as $r)
                        @php $v = (float) (string) $r['opening_balance']; @endphp
                        <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap {{ $v < 0 ? 'text-red-700' : '' }}">{{ $cell($v) }}</td>
                    @endforeach
                    <td class="px-3 py-1.5 text-right tabular-nums border-l-2 border-slate-300 text-slate-400">—</td>
                </tr>

                <tr>
                    <td class="sticky left-0 bg-white px-3 py-1.5 whitespace-nowrap">Net Cash Movement</td>
                    @php $sumMove = 0.0; @endphp
                    @foreach ($rows as $r)
                        @php $v = (float) (string) $r['net_cash_movement']; $sumMove += $v; @endphp
                        <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap {{ $v < 0 ? 'text-red-700' : '' }}">{{ $cell($v, true) }}</td>
                    @endforeach
                    <td class="px-3 py-1.5 text-right tabular-nums border-l-2 border-slate-300 {{ $sumMove < 0 ? 'text-red-700' : '' }}">{{ $cell($sumMove) }}</td>
                </tr>

                <tr class="bg-amber-50/60">
                    <td class="sticky left-0 bg-amber-50/60 px-3 py-1.5 whitespace-nowrap font-medium">Interest &middot; Overdraft</td>
                    @php $sumInt = 0.0; @endphp
                    @foreach ($rows as $r)
                        @php
                            $v = $r['interest_posted'] === null ? null : (float) (string) $r['interest_posted'];
                            $sumInt += $v ?? 0.0;
                        @endphp
                        <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap font-medium">{{ $cell($v) }}</td>
                    @endforeach
                    <td class="px-3 py-1.5 text-right tabular-nums border-l-2 border-slate-300 font-medium">{{ $cell($sumInt) }}</td>
                </tr>

                <tr class="text-slate-600">
                    <td class="sticky left-0 bg-white px-3 py-1.5 whitespace-nowrap">
                        Accrued, not yet charged
                        <span class="ml-1 text-xs text-slate-400">liability</span>
                    </td>
                    @foreach ($rows as $r)
                        @php $v = (float) (string) $r['accrued_not_charged']; @endphp
                        <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap {{ abs($v) < 0.005 ? 'text-slate-300' : 'text-amber-700' }}">{{ $cell($v) }}</td>
                    @endforeach
                    <td class="border-l-2 border-slate-300"></td>
                </tr>

                <tr class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                    <td class="sticky left-0 bg-slate-50 px-3 py-1.5 whitespace-nowrap">Closing Balance</td>
                    @foreach ($rows as $r)
                        @php $v = (float) (string) $r['closing_balance']; @endphp
                        <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap {{ $v < 0 ? 'text-red-700' : '' }}">{{ $cell($v) }}</td>
                    @endforeach
                    <td class="px-3 py-1.5 text-right tabular-nums border-l-2 border-slate-300"></td>
                </tr>

                <tr><td colspan="{{ count($rows) + 2 }}" class="bg-slate-100 px-3 py-1 text-xs font-medium uppercase tracking-wide text-slate-500">How the charge is derived</td></tr>

                <tr class="text-slate-600">
                    <td class="sticky left-0 bg-white px-3 py-1.5 whitespace-nowrap">Closing before interest</td>
                    @foreach ($rows as $r)
                        <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap">{{ $cell((float) (string) $r['closing_before_interest']) }}</td>
                    @endforeach
                    <td class="border-l-2 border-slate-300"></td>
                </tr>

                <tr class="text-slate-600">
                    <td class="sticky left-0 bg-white px-3 py-1.5 whitespace-nowrap">Principal charged</td>
                    @foreach ($rows as $r)
                        <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap">{{ $cell((float) (string) $r['principal']) }}</td>
                    @endforeach
                    <td class="border-l-2 border-slate-300"></td>
                </tr>

                <tr class="text-slate-600">
                    <td class="sticky left-0 bg-white px-3 py-1.5 whitespace-nowrap">Interest accrued</td>
                    @php $sumAcc = 0.0; @endphp
                    @foreach ($rows as $r)
                        @php $v = (float) (string) $r['interest_accrued']; $sumAcc += $v; @endphp
                        <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap">{{ $cell($v, true) }}</td>
                    @endforeach
                    <td class="px-3 py-1.5 text-right tabular-nums border-l-2 border-slate-300">{{ $cell($sumAcc) }}</td>
                </tr>

                @if ($isOracleFarm)
                    <tr class="text-slate-500">
                        <td class="sticky left-0 bg-white px-3 py-1.5 whitespace-nowrap">Figured oracle</td>
                        @foreach ($rows as $i => $r)
                            @php
                                $exp = $oracle[$i] ?? null;
                                $ok = $exp !== null && (int) round(((float) (string) $r['interest_accrued']) * 10000) === $exp;
                            @endphp
                            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap {{ $exp === null ? '' : ($ok ? 'text-emerald-700' : 'bg-red-100 text-red-800') }}">
                                {{ $exp === null ? '—' : number_format($exp / 10000, 4) }}
                            </td>
                        @endforeach
                        <td class="border-l-2 border-slate-300"></td>
                    </tr>
                @endif

                </tbody>
            </table>
        </div>

        <div class="mb-6 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600 shadow-sm">
            <span class="font-medium text-slate-900">Reading it:</span>
            <strong>Closing Balance</strong> includes the interest, and each month's
            <strong>Opening Balance</strong> is the previous month's closing — so the charge feeds
            back into the position it was computed from, which is what Figured's virtual journals do.
            <br><br>
            The three rows under <em>How the charge is derived</em> are the working.
            <strong>Closing before interest</strong> is the raw cash position and on the oracle farm
            it never moves. <strong>Principal charged</strong> is that figure minus every dollar of
            interest accrued so far, and it climbs. The interest is charged on the second, which is
            why the charge grows when nothing in the data does — and why the query needs
            <code class="rounded bg-slate-100 px-1">WITH RECURSIVE</code> rather than a window
            function.
            <br><br>
            <strong>Interest accrued</strong> happens every month; <strong>Interest &middot;
            Overdraft</strong> is what is actually posted, which depends on the repayment term.
            Change the term above and the accrued row stays identical while the posted row moves.
            <br><br>
            <span class="font-medium text-slate-900">If the balance looks frozen, check the term.</span>
            On anything but monthly, no cash moves between repayment months, so the closing balance
            is flat by design — it is a <em>cash</em> position and nothing has been paid. The debt is
            still growing, and <strong>Accrued, not yet charged</strong> is where it shows: it climbs
            every month and resets to zero when the bucket is charged. On an annual term that is
            eleven flat months and one step, which is also exactly what Figured does.
        </div>
    @endif

    @if ($elapsedMs !== null)
        @php
            $bytes = static function (?int $b): string {
                if (!$b) { return '0 B'; }
                $u = ['B','KB','MB','GB','TB']; $i = (int) floor(log($b, 1024)); $i = min($i, 4);
                return number_format($b / (1024 ** $i), $i === 0 ? 0 : 1) . ' ' . $u[$i];
            };
        @endphp

        {{-- ---------- source transactions ---------- --}}
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm" open>
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Source transactions
                <span class="ml-2 font-normal text-slate-500">
                    {{ number_format((int) ($sourceSummary['n'] ?? 0)) }} lines ·
                    net {{ $money((float) ($sourceSummary['net_cash'] ?? 0)) }}
                    @if ($sourceRowsMs !== null) · {{ number_format($sourceRowsMs, 0) }} ms @endif
                </span>
            </summary>
            <div class="border-t border-slate-200 px-5 py-4">
                <p class="mb-3 text-xs text-slate-600">
                    The journal lines behind the closing balance, read with the report's own scope
                    predicate. <strong>Cash effect</strong> is the negation the balance applies:
                    revenue is stored as a credit and expense as a debit, so one negation turns both
                    into cash movement. Getting that sign wrong inverts the balance and the interest
                    disappears — which is exactly how it was caught here.
                </p>

                @if (empty($sourceRows))
                    <p class="text-sm text-slate-500">No lines in scope for this period.</p>
                @else
                    <div class="mb-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
                        <div>
                            <p class="text-slate-500">Lines</p>
                            <p class="font-semibold tabular-nums">{{ number_format((int) ($sourceSummary['n'] ?? 0)) }}</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Accounts</p>
                            <p class="font-semibold tabular-nums">{{ number_format((int) ($sourceSummary['n_accounts'] ?? 0)) }}</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Span</p>
                            <p class="font-semibold tabular-nums">{{ $sourceSummary['first_date'] ?? '—' }} … {{ $sourceSummary['last_date'] ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Net cash</p>
                            <p class="font-semibold tabular-nums">{{ $money((float) ($sourceSummary['net_cash'] ?? 0)) }}</p>
                        </div>
                    </div>
                    <div class="max-h-96 overflow-auto rounded border border-slate-200">
                        <table class="min-w-full text-xs">
                            <thead class="sticky top-0 bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-2 py-1 text-left font-medium">Date</th>
                                <th class="px-2 py-1 text-left font-medium">Type</th>
                                <th class="px-2 py-1 text-left font-medium">Account</th>
                                <th class="px-2 py-1 text-left font-medium">Class</th>
                                <th class="px-2 py-1 text-right font-medium">Amount $</th>
                                <th class="px-2 py-1 text-right font-medium">Cash effect $</th>
                                <th class="px-2 py-1 text-left font-medium">Line</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            @foreach ($sourceRows as $r)
                                <tr>
                                    <td class="px-2 py-1 whitespace-nowrap tabular-nums">{{ $r['date'] }}</td>
                                    <td class="px-2 py-1">
                                        <span class="rounded px-1.5 py-0.5 {{ $r['type'] === 'actuals' ? 'bg-slate-100 text-slate-700' : 'bg-violet-100 text-violet-800' }}">{{ $r['type'] }}</span>
                                    </td>
                                    <td class="px-2 py-1 whitespace-nowrap">{{ $r['account_name'] }}</td>
                                    <td class="px-2 py-1 text-slate-500">{{ $r['account_class'] }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums">{{ number_format((float) (string) $r['amount_dollars'], 2) }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums {{ (float) (string) $r['cash_effect'] < 0 ? 'text-red-700' : 'text-emerald-700' }}">
                                        {{ number_format((float) (string) $r['cash_effect'], 2) }}
                                    </td>
                                    <td class="px-2 py-1 font-mono text-slate-400 whitespace-nowrap">{{ $r['line_id'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if (count($sourceRows) >= $sourceRowLimit)
                        <p class="mt-2 text-xs text-slate-500">First {{ number_format($sourceRowLimit) }} by date.</p>
                    @endif
                @endif
            </div>
        </details>

        {{-- ---------- columnar engine ---------- --}}
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Columnar engine
                <span class="ml-2 font-normal text-slate-500">
                    @if (($columnar['error'] ?? null) !== null)
                        unavailable
                    @else
                        {{ count($columnar['columns'] ?? []) }} column(s) ·
                        {{ $bytes((int) ($columnar['used_bytes'] ?? 0)) }} of {{ $columnar['budget_mb'] ?? 0 }} MB
                        @if (($columnar['coverage'] ?? null) !== null)
                            · {{ number_format($columnar['coverage'] * 100, 1) }}% resident
                        @endif
                    @endif
                </span>
            </summary>
            <div class="border-t border-slate-200 px-5 py-4">
                @if (($columnar['error'] ?? null) !== null)
                    <p class="text-sm text-red-700">{{ $columnar['error'] }}</p>
                @else
                    <p class="mb-3 text-xs text-slate-600">
                        The column store is <strong>memory-resident with a fixed budget</strong>, so
                        capacity rather than disk is what limits it. A query whose columns no longer
                        fit falls back to scanning the row store — same answers, far slower, no
                        error. Read <strong>coverage</strong>, not the budget percentage: a store at
                        88% of its budget can still hold under a third of the table.
                    </p>

                    @if (($columnar['coverage'] ?? null) !== null && $columnar['coverage'] < 0.999)
                        <p class="mb-3 rounded border-l-4 border-amber-400 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                            <strong>Only {{ number_format($columnar['coverage'] * 100, 1) }}% of the
                            table is in memory.</strong> The rest is read from the heap on every
                            query. This report reads the whole cash position rather than a subset of
                            accounts, so it has no selective predicate to fall back on.
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
                                    <th class="px-2 py-1 text-left font-medium">Status</th>
                                    <th class="px-2 py-1 text-right font-medium">In memory</th>
                                    <th class="px-2 py-1 text-right font-medium">Times accessed</th>
                                </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                @foreach ($columnar['columns'] as $c)
                                    <tr>
                                        <td class="px-2 py-1 font-mono">{{ $c['column_name'] ?? '?' }}</td>
                                        <td class="px-2 py-1">
                                            <span class="rounded px-1.5 py-0.5 {{ ($c['status'] ?? '') === 'Usable' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ $c['status'] ?? '?' }}</span>
                                        </td>
                                        <td class="px-2 py-1 text-right tabular-nums">{{ $c['in_memory'] ?? '' }}</td>
                                        <td class="px-2 py-1 text-right tabular-nums text-slate-500">{{ number_format((int) ($c['num_times_accessed'] ?? 0)) }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-3 text-xs text-slate-500">
                            Table on disk: {{ $bytes((int) ($columnar['table_bytes'] ?? 0)) }} ·
                            planner row estimate: {{ number_format((int) ($columnar['row_estimate'] ?? 0)) }} ·
                            {{ number_format((int) ($columnar['blocks_in_store'] ?? 0)) }} of
                            {{ number_format((int) ($columnar['blocks_total'] ?? 0)) }} blocks held
                        </p>
                    @endif
                @endif
            </div>
        </details>

        {{-- ---------- query plan ---------- --}}
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-700">
                Query plan
                @if ($plan === null)
                    <span class="ml-2 font-normal text-slate-500">not captured</span>
                @elseif (($plan['error'] ?? null) !== null)
                    <span class="ml-2 rounded bg-red-100 px-1.5 py-0.5 text-xs font-normal text-red-800">failed</span>
                @elseif ($plan['columnar_scan'])
                    <span class="ml-2 rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-normal text-emerald-800">columnar scan used</span>
                @else
                    <span class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-normal text-amber-800">row-store scan</span>
                @endif
            </summary>
            <div class="border-t border-slate-200 px-5 py-4">
                @if ($plan === null)
                    <p class="text-sm text-slate-600">
                        <a href="{{ request()->fullUrlWithQuery(['explain' => 1]) }}" class="text-blue-700 underline">Explain this query</a>
                        — reruns it under <code class="rounded bg-slate-100 px-1">EXPLAIN (ANALYZE, BUFFERS)</code>,
                        so it costs a second execution.
                    </p>
                @elseif (($plan['error'] ?? null) !== null)
                    <p class="text-sm text-red-700">{{ $plan['error'] }}</p>
                @else
                    <div class="mb-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-5">
                        <div><p class="text-slate-500">Execution</p><p class="font-semibold tabular-nums">{{ number_format((float) ($plan['execution_ms'] ?? 0), 1) }} ms</p></div>
                        <div><p class="text-slate-500">Planning</p><p class="font-semibold tabular-nums">{{ number_format((float) ($plan['planning_ms'] ?? 0), 1) }} ms</p></div>
                        <div><p class="text-slate-500">Buffer hits</p><p class="font-semibold tabular-nums">{{ number_format((int) ($plan['shared_hit'] ?? 0)) }}</p></div>
                        <div><p class="text-slate-500">Buffer reads</p><p class="font-semibold tabular-nums {{ ($plan['shared_read'] ?? 0) > 0 ? 'text-amber-700' : '' }}">{{ number_format((int) ($plan['shared_read'] ?? 0)) }}</p></div>
                        <div><p class="text-slate-500">Temp written</p><p class="font-semibold tabular-nums {{ ($plan['temp_written'] ?? 0) > 0 ? 'text-red-700' : '' }}">{{ number_format((int) ($plan['temp_written'] ?? 0)) }}</p></div>
                    </div>
                    <p class="mb-3 text-xs text-slate-600">
                        <strong>Temp written</strong> above zero means the query spilled —
                        a recursive CTE materialises its working table, so a very long period is
                        where that would first show up. <strong>Buffer reads</strong> above zero
                        means pages came from storage rather than <code class="rounded bg-slate-100 px-1">shared_buffers</code>.
                    </p>
                    @if (!empty($plan['columnar_nodes']))
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
    @endif

    @if ($sql !== null)
        <details class="mb-4 rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium">
                SQL
                <span class="ml-2 font-normal text-slate-500">one statement, WITH RECURSIVE</span>
            </summary>
            <div class="border-t border-slate-200 px-4 py-3">
                <pre class="overflow-x-auto rounded bg-slate-900 p-3 text-xs leading-relaxed text-slate-100">{{ $sql }}</pre>
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
            if (document.readyState === 'complete') { write(); } else { window.addEventListener('load', write); }
        })();
    </script>
</div>
</body>
</html>
