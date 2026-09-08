<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CashFlow\CashFlowOracleSeeder;
use App\Services\CashFlow\CashFlowQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Renders the DuckDB Cash Flow report in a browser, with the report
 * parameters exposed as form inputs.
 *
 * Every request builds its own DuckDB instance (extensions loaded, DuckLake
 * attached) via the container binding, runs one query, and discards it —
 * which is the per-request model this PoC is evaluating, so the elapsed time
 * shown on the page includes that setup cost, not just the query.
 */
class CashFlowReportController extends Controller
{
    public function __invoke(Request $request, DuckDB $db): View
    {
        $alias = config('duckdb.attached_alias');

        $farms = $this->availableFarms($db, $alias);

        $farmId = (string) $request->query('farm_id', CashFlowOracleSeeder::FARM_ID);
        $periodFrom = (string) $request->query('period_from', '2024-01-01');
        $periodTo = (string) $request->query('period_to', '2024-12-31');
        $horizon = (string) $request->query('horizon', '2024-02-28');
        $basis = (string) $request->query('basis', 'cash');

        $farm = collect($farms)->firstWhere('farm_id', $farmId) ?? ($farms[0] ?? null);

        $rows = [];
        $sql = null;
        $error = null;
        $elapsedMs = null;

        if ($farm === null) {
            $error = 'No farms found in DuckLake. Run: php artisan duckdb:cashflow:seed';
        } else {
            $query = new CashFlowQuery($db, $alias);

            $sql = $query->sql(
                farmId: $farm['farm_id'],
                farmType: $farm['farm_type'],
                region: $farm['region'],
                periodFrom: $periodFrom,
                periodTo: $periodTo,
                horizon: $horizon,
                basis: $basis,
            );

            try {
                $startedAt = microtime(true);

                $rows = $query->run(
                    farmId: $farm['farm_id'],
                    farmType: $farm['farm_type'],
                    region: $farm['region'],
                    periodFrom: $periodFrom,
                    periodTo: $periodTo,
                    horizon: $horizon,
                    basis: $basis,
                );

                $elapsedMs = (microtime(true) - $startedAt) * 1000;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return view('cashflow', [
            'farms' => $farms,
            'farm' => $farm,
            'farmId' => $farm['farm_id'] ?? $farmId,
            'periodFrom' => $periodFrom,
            'periodTo' => $periodTo,
            'horizon' => $horizon,
            'basis' => $basis,
            'rows' => $rows,
            'reportRows' => $this->reportRowDefinitions(),
            'sql' => $sql,
            'error' => $error,
            'elapsedMs' => $elapsedMs,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function availableFarms(DuckDB $db, string $alias): array
    {
        try {
            return iterator_to_array(
                $db->query("SELECT farm_id, farm_type, region, opening_balance FROM {$alias}.farms ORDER BY farm_id")
                    ->rows(true)
            );
        } catch (Throwable) {
            // Schema not created yet — the view surfaces this as guidance.
            return [];
        }
    }

    /**
     * Report line order and emphasis, mirroring the section order
     * CashFlowStructureBuilder declares.
     *
     * @return list<array{field: string, label: string, strong: bool}>
     */
    private function reportRowDefinitions(): array
    {
        return [
            ['field' => 'income', 'label' => 'Income', 'strong' => false],
            ['field' => 'direct_costs', 'label' => 'Direct Costs', 'strong' => false],
            ['field' => 'gross_profit', 'label' => 'Gross Profit', 'strong' => true],
            ['field' => 'operating_expenses', 'label' => 'Operating Expenses', 'strong' => false],
            ['field' => 'operating_surplus', 'label' => 'Operating Surplus', 'strong' => true],
            ['field' => 'total_surplus', 'label' => 'Total Surplus', 'strong' => true],
            ['field' => 'net_cash_movement', 'label' => 'Net Cash Movement', 'strong' => true],
            ['field' => 'opening', 'label' => 'Opening Balance', 'strong' => false],
            ['field' => 'closing', 'label' => 'Closing Balance', 'strong' => true],
        ];
    }
}
