<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CashFlow\OverdraftOracleSeeder;
use App\Services\CashFlow\OverdraftQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Overdraft interest — Phase 3, and the one report here that is a recurrence.
 *
 * The page exists to make the compounding visible. A flat closing balance with
 * a rising interest charge is the whole proof: nothing else in the data is
 * changing, so the growth can only be interest accruing on itself.
 */
class OverdraftReportController extends Controller
{
    private const string DEFAULT_PERIOD_FROM = '2024-01-01';
    private const string DEFAULT_PERIOD_TO = '2024-12-31';
    private const string DEFAULT_HORIZON = '2026-08-31';

    /** @var list<string> */
    private const array TERMS = [
        'interest_only_monthly',
        'interest_only_bi_monthly',
        'interest_only_quarterly',
        'interest_only_semi_annually',
        'interest_only_annually',
    ];

    public function __invoke(Request $request, DuckDB $db): View
    {
        $alias = config('duckdb.attached_alias');
        $appAlias = config('duckdb.app_database.alias');

        $farms = $this->farmsWithOverdrafts();
        $farmId = (string) $request->query('farm_id', $farms[0]['farm_id'] ?? OverdraftOracleSeeder::FARM_ID);
        $periodFrom = (string) $request->query('period_from', self::DEFAULT_PERIOD_FROM);
        $periodTo = (string) $request->query('period_to', self::DEFAULT_PERIOD_TO);
        $horizon = (string) $request->query('horizon', self::DEFAULT_HORIZON);

        $query = new OverdraftQuery($db, $alias, $appAlias);

        $rows = [];
        $error = null;
        $elapsedMs = null;

        if ($farms === []) {
            $error = 'No farm has an overdraft configured. Run: php artisan duckdb:overdraft';
        } else {
            try {
                $startedAt = microtime(true);
                $rows = $query->run($farmId, $periodFrom, $periodTo, $horizon);
                $elapsedMs = (microtime(true) - $startedAt) * 1000;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $serverMs = defined('LARAVEL_START') ? (microtime(true) - LARAVEL_START) * 1000 : null;

        return view('overdraft', [
            'farms' => $farms,
            'farmId' => $farmId,
            'periodFrom' => $periodFrom,
            'periodTo' => $periodTo,
            'horizon' => $horizon,
            'rows' => $rows,
            'error' => $error,
            'elapsedMs' => $elapsedMs,
            'serverMs' => $serverMs,
            'sql' => $error === null ? $query->sql() : null,
            'config' => $this->overdraftConfig($farmId),
            'terms' => self::TERMS,
            'oracle' => OverdraftOracleSeeder::expectedMonthly(),
            'isOracleFarm' => $farmId === OverdraftOracleSeeder::FARM_ID
                && $periodFrom === self::DEFAULT_PERIOD_FROM
                && $periodTo === self::DEFAULT_PERIOD_TO,
        ]);
    }

    /**
     * Saves the overdraft settings, then redirects back.
     *
     * A POST rather than more query parameters because it writes. The rate and
     * term are read from the `overdrafts` table by the SQL — deliberately, so
     * the query stays the one Figured's shape implies — which means changing
     * them has to be a write rather than a bind.
     */
    public function save(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'farm_id' => ['required', 'string'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'payment_term' => ['required', 'string', 'in:'.implode(',', self::TERMS)],
        ]);

        DB::table('overdrafts')
            ->where('farm_id', $validated['farm_id'])
            ->update([
                // Stored inflated, as Figured stores it: 5% is 50000.
                'rate' => (int) round(((float) $validated['rate']) * 10000),
                'payment_term' => $validated['payment_term'],
            ]);

        return redirect()->route('overdraft', $request->only(['farm_id', 'period_from', 'period_to', 'horizon']));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function farmsWithOverdrafts(): array
    {
        try {
            return array_map(
                static fn (object $row): array => (array) $row,
                DB::table('overdrafts as o')
                    ->join('farms as f', 'f.farm_id', '=', 'o.farm_id')
                    ->groupBy('o.farm_id', 'f.region')
                    ->orderBy('o.farm_id')
                    ->get(['o.farm_id', 'f.region'])
                    ->all()
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function overdraftConfig(string $farmId): ?array
    {
        $row = DB::table('overdrafts')
            ->where('farm_id', $farmId)
            ->orderByDesc('start_date')
            ->first();

        return $row === null ? null : (array) $row;
    }
}
