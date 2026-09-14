<?php

declare(strict_types=1);

namespace App\Services\Mongo;

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;

/**
 * One `MongoDB\Client` per process, shared by the report and the seeder.
 *
 * The client owns a connection pool and does server discovery on first use, so
 * building a new one per request would charge every measurement for a handshake
 * that a long-lived PHP-FPM worker would have paid once. Registered as a lazy
 * singleton for the same reason the DuckLake connection is.
 */
final class MongoConnectionFactory
{
    private ?Client $client = null;

    public function client(): Client
    {
        return $this->client ??= new Client(
            (string) config('mongo.uri'),
            [],
            // The report reads BSON documents and never hydrates a model, so
            // plain PHP arrays are both faster and closer to what
            // QueryMongoReportDataService actually gets back.
            ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]
        );
    }

    public function database(): Database
    {
        return $this->client()->selectDatabase((string) config('mongo.database'));
    }

    public function journals(): Collection
    {
        return $this->database()->selectCollection((string) config('mongo.collection'));
    }

    /**
     * Server version and cache pressure, for the diagnostics panel.
     *
     * WiredTiger's cache figures are the Mongo analogue of AlloyDB's column
     * store coverage: a report served entirely from cache and one that is
     * paging from disk differ by an order of magnitude, and neither shows up in
     * the query's own timing.
     *
     * @return array{version: string|null, cache_bytes: int|null, cache_max_bytes: int|null, error: string|null}
     */
    public function serverState(): array
    {
        try {
            $status = (array) $this->database()->command(['serverStatus' => 1])->toArray()[0];
        } catch (\Throwable $e) {
            return ['version' => null, 'cache_bytes' => null, 'cache_max_bytes' => null, 'error' => $e->getMessage()];
        }

        $cache = $status['wiredTiger']['cache'] ?? [];

        return [
            'version' => isset($status['version']) ? (string) $status['version'] : null,
            'cache_bytes' => isset($cache['bytes currently in the cache'])
                ? (int) $cache['bytes currently in the cache'] : null,
            'cache_max_bytes' => isset($cache['maximum bytes configured'])
                ? (int) $cache['maximum bytes configured'] : null,
            'error' => null,
        ];
    }
}
