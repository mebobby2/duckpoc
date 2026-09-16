<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AlloyDb\AlloyDbQueryProfile;
use App\Services\AlloyDb\OverdraftPgQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * Overdraft interest on AlloyDB.
 *
 * The diagnostics differ from the lake's on purpose. There are no Parquet
 * files, no storage requests and no partition pruning to report — the question
 * for a memory-resident columnar engine is whether the scan was served from the
 * column store or fell back to the heap, and that is what the panels here show.
 */
class AlloyDbOverdraftController extends Controller
{
    private const string DEFAULT_PERIOD_FROM = '2024-01-01';
    private const string DEFAULT_PERIOD_TO = '2024-12-31';
    private const string DEFAULT_HORIZON = '2026-08-31';

    private const int SOURCE_ROW_LIMIT = 500;

    /**
     * Above this, the extra passes are skipped. The report's own elapsed time
     * is free to check; a row count would mean paying for a scan to decide
     * whether a scan is affordable.
     */
    private const float DIAGNOSTICS_BUDGET_MS = 2_000.0;

    /** @var list<string> */
    private const array TERMS = [
        'interest_only_monthly',
        'interest_only_bi_monthly',
        'interest_only_quarterly',
        'interest_only_semi_annually',
        'interest_only_annually',
    ];

    public function __invoke(Request $request): View
    {
        $db = DB::connection('alloydb');
        $query = new OverdraftPgQuery($db);
        $profiler = new AlloyDbQueryProfile($db);

        $farms = $this->farmsWithOverdrafts($db);
        $farmId = (string) $request->query('farm_id', $farms[0]['farm_id'] ?? OverdraftPgQuery::ORACLE_FARM_ID);
        $periodFrom = (string) $request->query('period_from', self::DEFAULT_PERIOD_FROM);
        $periodTo = (string) $request->query('period_to', self::DEFAULT_PERIOD_TO);
        $horizon = (string) $request->query('horizon', self::DEFAULT_HORIZON);

        $rows = [];
        $error = null;
        $elapsedMs = null;
        $plan = null;
        $sourceRows = [];
        $sourceSummary = [];
        $sourceRowsMs = null;
        $diagnosticsAffordable = true;

        if ($farms === []) {
            $error = 'No farm has an overdraft configured. Run: php artisan alloydb:overdraft';
        } else {
            try {
                $startedAt = microtime(true);
                $rows = $query->run($farmId, $periodFrom, $periodTo, $horizon);
                $elapsedMs = (microtime(true) - $startedAt) * 1000;

                $diagnosticsAffordable = $elapsedMs < self::DIAGNOSTICS_BUDGET_MS
                    || $request->boolean('force_diagnostics');

                if ($diagnosticsAffordable) {
                    $startedAt = microtime(true);
                    $sourceSummary = $query->sourceSummary($farmId, $periodFrom, $periodTo, $horizon);
                    $sourceRows = $query->sourceRows($farmId, $periodFrom, $periodTo, $horizon, self::SOURCE_ROW_LIMIT);
                    $sourceRowsMs = (microtime(true) - $startedAt) * 1000;
                }

                if ($request->boolean('explain')) {
                    $plan = $profiler->explain($query->sql(), [
                        'farm_id' => $farmId, 'farm_id2' => $farmId, 'farm_id3' => $farmId,
                        'period_from' => $periodFrom, 'period_from2' => $periodFrom,
                        'period_to' => $periodTo, 'period_to2' => $periodTo,
                        'horizon' => $horizon, 'horizon2' => $horizon,
                    ]);
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $serverMs = defined('LARAVEL_START') ? (microtime(true) - LARAVEL_START) * 1000 : null;

        return view('alloydb-overdraft', [
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
            'config' => $config = $this->overdraftConfig($db, $farmId),
            'terms' => self::TERMS,
            'oracle' => OverdraftPgQuery::expectedMonthly(),
            'isOracleFarm' => $farmId === OverdraftPgQuery::ORACLE_FARM_ID
                && $periodFrom === self::DEFAULT_PERIOD_FROM
                && $periodTo === self::DEFAULT_PERIOD_TO
                && ((int) ($config['rate'] ?? 0)) === OverdraftPgQuery::ORACLE_RATE
                && ($config['payment_term'] ?? null) === 'interest_only_monthly',
            'plan' => $plan,
            'sourceRows' => $sourceRows,
            'sourceSummary' => $sourceSummary,
            'sourceRowsMs' => $sourceRowsMs,
            'sourceRowLimit' => self::SOURCE_ROW_LIMIT,
            'diagnosticsAffordable' => $diagnosticsAffordable,
            'diagnosticsBudgetMs' => self::DIAGNOSTICS_BUDGET_MS,
            // Guarded: AlloyDB being down should render the error panel, not a
            // 500 — which is exactly when the page is most worth reading.
            'columnar' => $this->guarded(fn (): array => $profiler->columnarState()),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'farm_id' => ['required', 'string'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'payment_term' => ['required', 'string', 'in:'.implode(',', self::TERMS)],
        ]);

        DB::connection('alloydb')->table('overdrafts')
            ->where('farm_id', $validated['farm_id'])
            ->update([
                'rate' => (int) round(((float) $validated['rate']) * 10000),
                'payment_term' => $validated['payment_term'],
            ]);

        return redirect()->route('alloydb-overdraft',
            $request->only(['farm_id', 'period_from', 'period_to', 'horizon']));
    }

    /**
     * @param callable(): array<string, mixed> $probe
     * @return array<string, mixed>
     */
    private function guarded(callable $probe): array
    {
        try {
            return $probe();
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function farmsWithOverdrafts(object $db): array
    {
        try {
            return array_map(
                static fn (object $row): array => (array) $row,
                $db->select('SELECT DISTINCT o.farm_id FROM overdrafts o ORDER BY o.farm_id')
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function overdraftConfig(object $db, string $farmId): ?array
    {
        try {
            $row = $db->selectOne(
                'SELECT rate, payment_term, start_date FROM overdrafts
                 WHERE farm_id = ? ORDER BY start_date DESC LIMIT 1',
                [$farmId]
            );

            return $row === null ? null : (array) $row;
        } catch (Throwable) {
            return null;
        }
    }
}
