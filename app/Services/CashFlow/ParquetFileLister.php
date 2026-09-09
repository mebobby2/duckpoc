<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Saturio\DuckDB\DuckDB;
use Throwable;

/**
 * Lists the Parquet files DuckLake holds for `transaction_lines`, annotated
 * with whether each file's partition is in scope for a given query.
 *
 * "In scope" is derived from the partition path, NOT from DuckDB's query plan.
 * So it reports which files the partition predicate *should* let DuckDB skip,
 * which is not a promise about what it physically opened. Two measured facts
 * make that gap real rather than theoretical: `farm_type`/`region` pruning
 * works, but the `year(date)` transform does not prune at all — only a raw
 * `date BETWEEN` range does, via row-group statistics. DuckDB therefore likely
 * opens more year partitions than this listing implies.
 *
 * Shared by both report viewers on purpose. The partition-path parsing is
 * fiddly enough that a second copy would drift, and the two pages would then
 * disagree about which files a query reads — with no test to catch it, since
 * this is diagnostic output rather than report output.
 */
final class ParquetFileLister
{
    /**
     * Only `transaction_lines` has a Parquet footprint — `accounts`, `farms`
     * and `trackers` are MySQL tables.
     */
    private const array TABLES = ['transaction_lines'];

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
    ) {
    }

    /**
     * @param array<string, mixed> $farm
     * @return list<array<string, mixed>>
     */
    public function forQuery(array $farm, string $periodFrom, string $periodTo): array
    {
        $fromYear = (int) date('Y', strtotime($periodFrom));
        $toYear = (int) date('Y', strtotime($periodTo));

        $files = [];

        foreach (self::TABLES as $table) {
            try {
                $listed = $this->db->query(
                    "SELECT data_file, data_file_size_bytes, delete_file
                     FROM ducklake_list_files('{$this->alias}', '{$table}')"
                )->rows(true);
            } catch (Throwable) {
                continue;
            }

            foreach ($listed as $row) {
                $files[] = $this->describe(
                    (string) $row['data_file'],
                    (int) $row['data_file_size_bytes'],
                    !empty($row['delete_file']),
                    $table,
                    $farm,
                    $fromYear,
                    $toYear,
                );
            }
        }

        usort($files, static fn (array $a, array $b): int => [$b['in_scope'], $a['table'], (string) $a['partition']]
            <=> [$a['in_scope'], $b['table'], (string) $b['partition']]);

        return $files;
    }

    /**
     * @param array<string, mixed> $farm
     * @return array<string, mixed>
     */
    private function describe(
        string $path,
        int $bytes,
        bool $hasDeleteFile,
        string $table,
        array $farm,
        int $fromYear,
        int $toYear,
    ): array {
        $partition = null;
        // Unpartitioned files have no cohort to compare, so they are always
        // read — treating them as out of scope would be wrong, not cautious.
        $inScope = true;

        if (preg_match('~/(farm_id=[^/]+)/(basis=[^/]+)/(year=[^/]+)/~', $path, $m)) {
            $partition = "{$m[1]}/{$m[2]}/{$m[3]}";

            $year = (int) substr($m[3], strlen('year='));

            $inScope = $m[1] === 'farm_id='.$farm['farm_id']
                && $year >= $fromYear
                && $year <= $toYear;
        }

        return [
            'table' => $table,
            'partition' => $partition,
            'name' => basename($path),
            'path' => $path,
            'bytes' => $bytes,
            'has_delete_file' => $hasDeleteFile,
            'in_scope' => $inScope,
        ];
    }
}
