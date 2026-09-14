<?php

declare(strict_types=1);

/*
 * The baseline stack: MongoDB holding journals only.
 *
 * Deliberately NOT a Laravel database connection. Figured's report path does
 * not go through Eloquent either — `QueryMongoReportDataService` hands a raw
 * aggregation pipeline to the driver — and routing this through
 * `mongodb/laravel-mongodb` would put model hydration inside the number being
 * measured. The raw library keeps the measurement on the engine and the
 * pipeline, which is what is being compared.
 */

return [

    'uri' => env('MONGO_URI', 'mongodb://127.0.0.1:27019'),

    'database' => env('MONGO_DATABASE', 'duckpoc'),

    /*
     * Facts only. Accounts, trackers, milk production and stock movements stay
     * in MySQL, reached through the default connection — that split is the
     * whole subject of this stack, not an implementation detail.
     */
    'collection' => env('MONGO_COLLECTION', 'transaction_lines'),

    /*
     * Batch size for the seeder's unordered bulk writes.
     *
     * Journal lines are pushed from PHP here, unlike the Postgres and DuckDB
     * seeders which generate rows inside the engine from `generate_series`.
     * Mongo has no server-side generator, so every document crosses the wire.
     * That is a real property of the topology, not a handicap imposed on it —
     * and it is why loading this stack is slow enough to be worth reporting.
     */
    'seed_batch' => (int) env('MONGO_SEED_BATCH', 10_000),

];
