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
    /** Source rows shown in the viewer before truncating. */
    private const int SOURCE_ROW_LIMIT = 500;

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

        $query = new CashFlowQuery($db, $alias);

        $sourceRows = [];
        $sourceSummary = ['n' => 0, 'net_dollars' => 0.0];
        $files = [];

        if ($farm === null) {
            $error = 'No farms found in DuckLake. Run: php artisan duckdb:cashflow:seed';
        } else {
            $sql = $query->sql();

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

                $sourceSummary = $query->sourceRowSummary(
                    farmId: $farm['farm_id'],
                    farmType: $farm['farm_type'],
                    region: $farm['region'],
                    periodFrom: $periodFrom,
                    periodTo: $periodTo,
                    horizon: $horizon,
                    basis: $basis,
                );

                $sourceRows = $query->sourceRows(
                    farmId: $farm['farm_id'],
                    farmType: $farm['farm_type'],
                    region: $farm['region'],
                    periodFrom: $periodFrom,
                    periodTo: $periodTo,
                    horizon: $horizon,
                    basis: $basis,
                    limit: self::SOURCE_ROW_LIMIT,
                );

                $files = $this->parquetFiles($db, $alias, $farm, $periodFrom, $periodTo);
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
            'reportRows' => $query->definition()->displayRows(),
            'sql' => $sql,
            'error' => $error,
            'elapsedMs' => $elapsedMs,
            'sourceRows' => $sourceRows,
            'sourceSummary' => $sourceSummary,
            'sourceRowLimit' => self::SOURCE_ROW_LIMIT,
            'files' => $files,
        ]);
    }

    /**
     * Parquet files DuckLake holds for the tables this report reads, annotated
     * with whether each one's partition is in scope for the current query.
     *
     * "In scope" is derived from the partition path, not from DuckDB's query
     * plan — so it says which files the partition predicate *should* let it
     * skip, which is not a promise about what it physically opened. Worth
     * knowing when reading this: measurement in this PoC showed
     * `farm_type`/`region` pruning working but the `year(date)` transform not
     * pruning at all, so DuckDB likely reads more year partitions than the
     * in-scope flag suggests.
     *
     * @param array<string, mixed> $farm
     * @return list<array<string, mixed>>
     */
    private function parquetFiles(
        DuckDB $db,
        string $alias,
        array $farm,
        string $periodFrom,
        string $periodTo,
    ): array {
        $fromYear = (int) date('Y', strtotime($periodFrom));
        $toYear = (int) date('Y', strtotime($periodTo));

        $files = [];

        foreach (['transaction_lines', 'accounts', 'farms'] as $table) {
            try {
                $listed = $db->query(
                    "SELECT data_file, data_file_size_bytes, delete_file
                     FROM ducklake_list_files('{$alias}', '{$table}')"
                )->rows(true);
            } catch (Throwable) {
                continue;
            }

            foreach ($listed as $row) {
                $path = (string) $row['data_file'];

                $partition = null;
                $inScope = true;

                if (preg_match('~/(farm_type=[^/]+)/(region=[^/]+)/(year=[^/]+)/~', $path, $m)) {
                    $partition = "{$m[1]}/{$m[2]}/{$m[3]}";

                    $year = (int) substr($m[3], strlen('year='));

                    $inScope = $m[1] === 'farm_type='.$farm['farm_type']
                        && $m[2] === 'region='.$farm['region']
                        && $year >= $fromYear
                        && $year <= $toYear;
                }

                $files[] = [
                    'table' => $table,
                    'partition' => $partition,
                    'name' => basename($path),
                    'path' => $path,
                    'bytes' => (int) $row['data_file_size_bytes'],
                    'has_delete_file' => !empty($row['delete_file']),
                    'in_scope' => $inScope,
                ];
            }
        }

        usort($files, static fn (array $a, array $b): int => [$b['in_scope'], $a['table'], (string) $a['partition']]
            <=> [$a['in_scope'], $b['table'], (string) $b['partition']]);

        return $files;
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

}
