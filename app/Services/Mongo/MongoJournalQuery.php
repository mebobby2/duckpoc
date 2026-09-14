<?php

declare(strict_types=1);

namespace App\Services\Mongo;

use MongoDB\BSON\UTCDateTime;

/**
 * The MongoDB half of the baseline: grouped sums of journal lines, and nothing
 * else.
 *
 * Modelled on `QueryMongoReportDataService` in figured-webapp, which is
 * narrower than people assume. It partitions the reporting period into budget
 * types and issues ONE aggregation per non-empty bucket — at most four for a
 * whole report, independent of how many trackers or accounts the farm has. The
 * pipeline is `$match` then `$group`, and the result is a bag of
 * (account, interval) totals with no names, no ordering and no structure.
 *
 * Everything a reader would recognise as the report — which account belongs to
 * which group, the subtotals, the stock chain, the per-unit margins — happens
 * afterwards in PHP, because the data it needs is in MySQL and no Mongo stage
 * can reach across. `GrossMarginV2MongoReport` is that half.
 *
 * Two deliberate departures from figured-webapp, both of which make Mongo look
 * BETTER than production, so the baseline is conservative rather than flattered
 * in the PoC's favour:
 *
 * - **No `$unwind`.** Figured's `transactions` collection stores a document per
 *   transaction with an array of lines, and the real pipeline unwinds them.
 *   Here one document IS one line, matching the grain of the lake and the
 *   Postgres table. Keeping the grain identical across all four stacks matters
 *   more than reproducing the nesting, and it spares Mongo a stage.
 * - **No report cache.** Figured memoises the pipeline result in Redis for 600
 *   seconds. Every measurement here is cold, which is the number worth having.
 *
 * What is NOT simulated, and would only ever be added cost: virtual journals.
 * Figured synthesises journal lines at report time from business rules, tracked
 * separately as `report_vj_duration`. No engine in this PoC does that work.
 */
final class MongoJournalQuery
{
    public function __construct(
        private readonly MongoConnectionFactory $mongo,
    ) {}

    /**
     * One aggregation per budget type present in the period.
     *
     * The bucketing is Figured's, not an optimisation: actuals and forecast
     * cannot be mixed in one pipeline because they are selected by different
     * predicates, so a period straddling the horizon costs two queries and a
     * period entirely on one side costs one.
     *
     * @return array{
     *     totals: array<string, array{account_id: string, month: string, amount_raw: int, line_count: int}>,
     *     queries: list<array{bucket: string, ms: float, groups: int}>,
     *     ms: float
     * }
     */
    public function totalsByAccountMonth(
        string $farmId,
        string $periodFrom,
        string $periodTo,
        string $horizon,
        string $basis,
        array $accountIds,
    ): array {
        $totals = [];
        $queries = [];
        $startedAll = microtime(true);

        foreach ($this->buckets($periodFrom, $periodTo, $horizon) as $bucket => $range) {
            $started = microtime(true);

            $cursor = $this->mongo->journals()->aggregate(
                $this->pipeline($farmId, $basis, $bucket, $range['from'], $range['to'], $accountIds),
                // The grouped output is accounts x months — a few hundred rows
                // even on the billion-line farm — so this never actually
                // spills. It is set so that a mis-specified period fails slowly
                // and visibly rather than with Mongo's 100 MB group limit,
                // which reads like a bug in the report.
                ['allowDiskUse' => true]
            );

            $groups = 0;

            foreach ($cursor as $row) {
                $accountId = (string) $row['_id']['account_id'];
                $month = (string) $row['_id']['month'];
                $key = $accountId.'|'.$month;

                // Buckets are disjoint by construction, so a key cannot repeat
                // across them; summing rather than assigning would silently
                // hide it if that ever stopped being true.
                $totals[$key] = [
                    'account_id' => $accountId,
                    'month' => $month,
                    'amount_raw' => (int) ($totals[$key]['amount_raw'] ?? 0) + (int) $row['amount_raw'],
                    'line_count' => (int) ($totals[$key]['line_count'] ?? 0) + (int) $row['line_count'],
                ];
                $groups++;
            }

            $queries[] = [
                'bucket' => $bucket,
                'ms' => (microtime(true) - $started) * 1000,
                'groups' => $groups,
            ];
        }

        return [
            'totals' => $totals,
            'queries' => $queries,
            'ms' => (microtime(true) - $startedAll) * 1000,
        ];
    }

    /**
     * Date ranges per budget type, mirroring the scope predicate the other
     * three stacks express in SQL: actuals up to the horizon, forecast strictly
     * after it, both clipped to the reporting period.
     *
     * An empty range is dropped rather than queried, which is what makes the
     * count 1-4 rather than always 4.
     *
     * @return array<string, array{from: string, to: string}>
     */
    private function buckets(string $periodFrom, string $periodTo, string $horizon): array
    {
        $buckets = [];

        $actualsTo = min($periodTo, $horizon);

        if ($periodFrom <= $actualsTo) {
            $buckets['actuals'] = ['from' => $periodFrom, 'to' => $actualsTo];
        }

        $forecastFrom = max($periodFrom, date('Y-m-d', strtotime($horizon.' +1 day')));

        if ($forecastFrom <= $periodTo) {
            $buckets['forecast'] = ['from' => $forecastFrom, 'to' => $periodTo];
        }

        return $buckets;
    }

    /**
     * @param  list<string>  $accountIds
     * @return list<array<string, mixed>>
     */
    public function pipeline(
        string $farmId,
        string $basis,
        string $type,
        string $from,
        string $to,
        array $accountIds,
    ): array {
        return [
            [
                '$match' => [
                    'farm_id' => $farmId,
                    'basis' => $basis,
                    'type' => $type,
                    // The account list comes from MySQL. That is the federation
                    // boundary in its most literal form: the dimension table
                    // cannot be joined, so its primary keys are marshalled into
                    // the query as a literal $in. On a farm with a large chart
                    // of accounts this predicate is itself a payload.
                    'account_id' => ['$in' => $accountIds],
                    'date' => [
                        '$gte' => new UTCDateTime(strtotime($from.' 00:00:00') * 1000),
                        '$lte' => new UTCDateTime(strtotime($to.' 23:59:59') * 1000),
                    ],
                ],
            ],
            [
                '$group' => [
                    '_id' => [
                        'account_id' => '$account_id',
                        // Bucketed in the $group key rather than in a preceding
                        // $project. One stage less to stream 500M documents
                        // through, and identical output.
                        'month' => ['$dateToString' => ['format' => '%Y-%m', 'date' => '$date']],
                    ],
                    'amount_raw' => ['$sum' => '$amount'],
                    'line_count' => ['$sum' => 1],
                ],
            ],
        ];
    }

    /**
     * Execution stats for the actuals bucket, for the diagnostics panel.
     *
     * `executionStats` rather than `queryPlanner`, because the question is not
     * which index was chosen but how many documents were examined to return the
     * groups — the Mongo analogue of AlloyDB's "Rows Aggregated by Columnar
     * Scan". A totalDocsExamined far above the returned group count is the
     * signature of a collection scan.
     *
     * @param  list<string>  $accountIds
     * @return array{error: string|null, stage: string|null, docs_examined: int|null, keys_examined: int|null, ms: int|null, index: string|null}
     */
    public function explain(
        string $farmId,
        string $periodFrom,
        string $periodTo,
        string $horizon,
        string $basis,
        array $accountIds,
    ): array {
        $empty = ['error' => null, 'stage' => null, 'docs_examined' => null, 'keys_examined' => null, 'ms' => null, 'index' => null];

        $buckets = $this->buckets($periodFrom, $periodTo, $horizon);

        if ($buckets === []) {
            return [...$empty, 'error' => 'The period selects no months.'];
        }

        $bucket = array_key_first($buckets);

        try {
            $result = (array) $this->mongo->database()->command([
                'explain' => [
                    'aggregate' => (string) config('mongo.collection'),
                    'pipeline' => $this->pipeline(
                        $farmId, $basis, $bucket,
                        $buckets[$bucket]['from'], $buckets[$bucket]['to'], $accountIds
                    ),
                    'cursor' => new \stdClass,
                ],
                'verbosity' => 'executionStats',
            ])->toArray()[0];
        } catch (\Throwable $e) {
            return [...$empty, 'error' => $e->getMessage()];
        }

        // Mongo reports the shape differently depending on whether the
        // aggregation was pushed into the query layer or run as stages, so both
        // shapes have to be probed rather than assumed.
        $stats = $result['executionStats']
            ?? $result['stages'][0]['$cursor']['executionStats']
            ?? null;

        $winning = $result['queryPlanner']['winningPlan']
            ?? $result['stages'][0]['$cursor']['queryPlanner']['winningPlan']
            ?? [];

        return [
            'error' => null,
            'stage' => $this->planStage($winning),
            'docs_examined' => isset($stats['totalDocsExamined']) ? (int) $stats['totalDocsExamined'] : null,
            'keys_examined' => isset($stats['totalKeysExamined']) ? (int) $stats['totalKeysExamined'] : null,
            'ms' => isset($stats['executionTimeMillis']) ? (int) $stats['executionTimeMillis'] : null,
            'index' => $this->indexName($winning),
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function planStage(array $plan): ?string
    {
        $stages = [];

        while ($plan !== []) {
            if (isset($plan['stage'])) {
                $stages[] = (string) $plan['stage'];
            }

            $plan = (array) ($plan['inputStage'] ?? $plan['queryPlan'] ?? []);
        }

        return $stages === [] ? null : implode(' <- ', $stages);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function indexName(array $plan): ?string
    {
        while ($plan !== []) {
            if (isset($plan['indexName'])) {
                return (string) $plan['indexName'];
            }

            $plan = (array) ($plan['inputStage'] ?? $plan['queryPlan'] ?? []);
        }

        return null;
    }
}
