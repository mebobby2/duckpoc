<?php

declare(strict_types=1);

namespace App\Services\Mongo;

use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;

/**
 * The "what did the report actually read" panel, for the Mongo stack.
 *
 * Separate from `MongoJournalQuery` because none of it is part of the report:
 * every method here is an EXTRA pass over the same documents, run only when the
 * report was fast enough to afford one. Mixing them would put diagnostics
 * inside the number being compared, which is exactly the mistake that made an
 * earlier version of the DuckDB page 40% slower than the report it described.
 *
 * The breakdown is also where a wrong horizon shows up: a period straddling it
 * should show both actuals and forecast, and a column of zeros on either side
 * means the scope predicate has silently excluded a range.
 */
final class MongoReportDiagnostics
{
    private const int FIXED_POINT = 10_000;

    public function __construct(
        private readonly MongoConnectionFactory $mongo,
    ) {}

    /**
     * The full scope predicate as one filter.
     *
     * Equivalent to the SQL versions' WHERE clause, including the disjunction
     * that keeps actuals on one side of the horizon and forecast on the other.
     * The report itself never uses this — it splits the same logic into
     * separate bucketed queries, the way Figured does.
     *
     * @param  list<string>  $accountIds
     * @return array<string, mixed>
     */
    public function scopeFilter(
        string $farmId,
        string $periodFrom,
        string $periodTo,
        string $horizon,
        string $basis,
        array $accountIds,
    ): array {
        $from = new UTCDateTime(strtotime($periodFrom.' 00:00:00') * 1000);
        $to = new UTCDateTime(strtotime($periodTo.' 23:59:59') * 1000);
        $horizonEnd = new UTCDateTime(strtotime($horizon.' 23:59:59') * 1000);

        return [
            'farm_id' => $farmId,
            'basis' => $basis,
            'account_id' => ['$in' => $accountIds],
            'date' => ['$gte' => $from, '$lte' => $to],
            '$or' => [
                ['type' => 'actuals', 'date' => ['$lte' => $horizonEnd]],
                ['type' => 'forecast', 'date' => ['$gt' => $horizonEnd]],
            ],
        ];
    }

    /**
     * @param  list<string>  $accountIds
     * @return array{n: int, n_groups: int, n_trackers: int, net_dollars: float}
     */
    public function summary(
        string $farmId,
        string $periodFrom,
        string $periodTo,
        string $horizon,
        string $basis,
        array $accountIds,
    ): array {
        $filter = $this->scopeFilter($farmId, $periodFrom, $periodTo, $horizon, $basis, $accountIds);

        $rows = $this->mongo->journals()->aggregate([
            ['$match' => $filter],
            ['$group' => [
                '_id' => null,
                'n' => ['$sum' => 1],
                'net' => ['$sum' => '$amount'],
                'trackers' => ['$addToSet' => '$tracker_id'],
                'accounts' => ['$addToSet' => '$account_id'],
            ]],
        ], ['allowDiskUse' => true])->toArray();

        if ($rows === []) {
            return ['n' => 0, 'n_groups' => 0, 'n_trackers' => 0, 'net_dollars' => 0.0];
        }

        $row = (array) $rows[0];

        // Report groups are a MySQL concept, so the distinct count has to be
        // derived from the account ids Mongo saw rather than counted in the
        // aggregation. The same boundary as everywhere else in this stack.
        $groups = DB::table('accounts')
            ->whereIn('account_id', (array) ($row['accounts'] ?? []))
            ->whereNotNull('report_group')
            ->distinct()
            ->count('report_group');

        return [
            'n' => (int) $row['n'],
            'n_groups' => (int) $groups,
            'n_trackers' => count((array) ($row['trackers'] ?? [])),
            'net_dollars' => (int) $row['net'] / self::FIXED_POINT,
        ];
    }

    /**
     * Per-account counts, split by budget type.
     *
     * @param  list<string>  $accountIds
     * @return list<array<string, mixed>>
     */
    public function lineBreakdown(
        string $farmId,
        string $periodFrom,
        string $periodTo,
        string $horizon,
        string $basis,
        array $accountIds,
    ): array {
        $filter = $this->scopeFilter($farmId, $periodFrom, $periodTo, $horizon, $basis, $accountIds);

        $rows = $this->mongo->journals()->aggregate([
            ['$match' => $filter],
            ['$group' => [
                '_id' => '$account_id',
                'n' => ['$sum' => 1],
                'n_actuals' => ['$sum' => ['$cond' => [['$eq' => ['$type', 'actuals']], 1, 0]]],
                'n_forecast' => ['$sum' => ['$cond' => [['$eq' => ['$type', 'forecast']], 1, 0]]],
                'first_date' => ['$min' => '$date'],
                'last_date' => ['$max' => '$date'],
                'net' => ['$sum' => '$amount'],
            ]],
        ], ['allowDiskUse' => true])->toArray();

        $accounts = DB::table('accounts')
            ->whereNotNull('report_group')
            ->get(['account_id', 'account_name', 'report_group_label', 'report_group_order', 'line_order'])
            ->keyBy('account_id');

        $out = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $account = $accounts[(string) $row['_id']] ?? null;

            if ($account === null) {
                continue;
            }

            $out[] = [
                'account_name' => $account->account_name,
                'report_group_label' => $account->report_group_label,
                'n_actuals' => (int) $row['n_actuals'],
                'n_forecast' => (int) $row['n_forecast'],
                'n' => (int) $row['n'],
                'first_date' => $this->date($row['first_date'] ?? null),
                'last_date' => $this->date($row['last_date'] ?? null),
                'net_dollars' => (int) $row['net'] / self::FIXED_POINT,
                '_sort' => [(int) $account->report_group_order, (int) $account->line_order],
            ];
        }

        usort($out, static fn (array $a, array $b): int => $a['_sort'] <=> $b['_sort']);

        return array_map(static function (array $row): array {
            unset($row['_sort']);

            return $row;
        }, $out);
    }

    /**
     * Individual journal lines, for reading rather than aggregating.
     *
     * Sorted by date alone, matching the SQL versions. A tie-breaker on line_id
     * would force Mongo to sort on a field the index does not carry, which is
     * the same trap that turned a 500-row debug listing into a full column scan
     * on the lake.
     *
     * @param  list<string>  $accountIds
     * @return list<array<string, mixed>>
     */
    public function sourceRows(
        string $farmId,
        string $periodFrom,
        string $periodTo,
        string $horizon,
        string $basis,
        array $accountIds,
        int $limit,
    ): array {
        $filter = $this->scopeFilter($farmId, $periodFrom, $periodTo, $horizon, $basis, $accountIds);

        $cursor = $this->mongo->journals()->find($filter, [
            'sort' => ['date' => 1],
            'limit' => $limit,
        ]);

        $accounts = DB::table('accounts')
            ->get(['account_id', 'account_name', 'report_group_label'])
            ->keyBy('account_id');

        $out = [];

        foreach ($cursor as $doc) {
            $doc = (array) $doc;
            $account = $accounts[(string) $doc['account_id']] ?? null;

            $out[] = [
                'line_id' => $doc['line_id'] ?? null,
                'date' => $this->date($doc['date'] ?? null),
                'type' => $doc['type'] ?? null,
                'account_name' => $account?->account_name,
                'report_group_label' => $account?->report_group_label,
                'tracker_id' => $doc['tracker_id'] ?? null,
                'amount_dollars' => (int) ($doc['amount'] ?? 0) / self::FIXED_POINT,
            ];
        }

        return $out;
    }

    /**
     * Collection-level storage figures, the Mongo counterpart to AlloyDB's
     * column-store coverage and the lake's file bytes.
     *
     * @return array{documents: int|null, size: int|null, storage_size: int|null, index_size: int|null, avg_doc_size: int|null, error: string|null}
     */
    public function collectionStats(): array
    {
        try {
            $stats = (array) $this->mongo->database()->command([
                'collStats' => (string) config('mongo.collection'),
            ])->toArray()[0];
        } catch (\Throwable $e) {
            return [
                'documents' => null, 'size' => null, 'storage_size' => null,
                'index_size' => null, 'avg_doc_size' => null, 'error' => $e->getMessage(),
            ];
        }

        return [
            'documents' => isset($stats['count']) ? (int) $stats['count'] : null,
            'size' => isset($stats['size']) ? (int) $stats['size'] : null,
            'storage_size' => isset($stats['storageSize']) ? (int) $stats['storageSize'] : null,
            'index_size' => isset($stats['totalIndexSize']) ? (int) $stats['totalIndexSize'] : null,
            'avg_doc_size' => isset($stats['avgObjSize']) ? (int) $stats['avgObjSize'] : null,
            'error' => null,
        ];
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof UTCDateTime
            ? $value->toDateTime()->format('Y-m-d')
            : null;
    }
}
