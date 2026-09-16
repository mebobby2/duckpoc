<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Overdraft interest — duckpoc</title>
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
            Phase 3 — the first report here that a window function cannot express. Interest is
            charged on a balance that <strong>excludes interest</strong>, so the running total of
            what has already accrued has to be subtracted to find the true overdrawn position.
            Month N's interest raises month N+1's charge base.
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
    <form method="POST" action="{{ route('overdraft.save') }}" class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-5 shadow-sm">
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
        <div class="mb-8 overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100 text-slate-600">
                <tr>
                    <th class="px-3 py-2 text-left font-medium">Month</th>
                    <th class="px-3 py-2 text-right font-medium">Closing balance<br><span class="text-xs font-normal">before interest</span></th>
                    <th class="px-3 py-2 text-right font-medium">Interest accrued</th>
                    <th class="px-3 py-2 text-right font-medium">Interest posted</th>
                    @if ($isOracleFarm)
                        <th class="px-3 py-2 text-right font-medium">Figured<br><span class="text-xs font-normal">accrual oracle</span></th>
                        <th class="px-3 py-2 text-center font-medium">Parity</th>
                    @endif
                </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @foreach ($rows as $i => $r)
                    @php
                        $closing = (float) (string) $r['closing_before_interest'];
                        $accrued = (float) (string) $r['interest_accrued'];
                        $posted = $r['interest_posted'] === null ? null : (float) (string) $r['interest_posted'];
                        $expected = $oracle[$i] ?? null;
                        $matches = $expected !== null && (int) round($accrued * 10000) === $expected;
                    @endphp
                    <tr class="{{ $posted !== null ? 'bg-blue-50/40' : '' }}">
                        <td class="px-3 py-1.5 whitespace-nowrap">{{ $r['month'] }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums {{ $closing < 0 ? 'text-red-700' : '' }}">{{ $money($closing) }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ $money($accrued, 4) }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums font-medium">{{ $posted === null ? '—' : $money($posted, 4) }}</td>
                        @if ($isOracleFarm)
                            <td class="px-3 py-1.5 text-right tabular-nums text-slate-500">
                                {{ $expected === null ? '—' : number_format($expected / 10000, 4) }}
                            </td>
                            <td class="px-3 py-1.5 text-center">
                                @if ($expected === null)
                                    <span class="text-slate-400">—</span>
                                @elseif ($matches)
                                    <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-800">match</span>
                                @else
                                    <span class="rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">differs</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="mb-6 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600 shadow-sm">
            <span class="font-medium text-slate-900">What to look for:</span>
            the closing balance is flat on the oracle farm — one expense, nothing afterwards — yet the
            interest rises every month. Nothing else in the data is changing, so that growth is
            interest compounding on itself. Shaded rows are posting months; change the term above and
            the accrual stays identical while the posting months move, and the totals must still agree.
        </div>
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
