<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;

/**
 * Seeds one very large farm — up to a billion journal lines — with 50
 * trackers, for stress-testing DuckDB's scan and grouping throughput.
 *
 * This is explicitly NOT a realistic farm. Figured's largest real farms run
 * ~700K journals over ~10 years, so a billion rows on one farm is ~1,400x
 * anything that exists. It is the right shape for two other questions:
 * DuckDB's raw ceiling, and the volume a *practice-wide* aggregate across
 * every farm would have to scan — which is the BigQuery-side problem this
 * architecture is also meant to serve.
 *
 * Written in chunks rather than one statement, for reasons that are not
 * cosmetic:
 *
 * 1. **No global sort.** A single `INSERT ... SELECT ... ORDER BY date` over a
 *    billion rows asks DuckDB to sort a billion rows before writing any of
 *    them, which spills to disk and can exhaust temp space. Each chunk here
 *    targets exactly one year, so sorting happens within a chunk — bounded,
 *    and still enough to tighten Parquet row-group min/max on `date`, which
 *    is the statistic the report's `date BETWEEN` predicate prunes on.
 * 2. **Bounded memory.** Chunk size caps peak working set regardless of the
 *    total requested.
 * 3. **Restartability and progress.** A failure at row 900M does not discard
 *    the first 900M, and a multi-hour seed reports where it is.
 * 4. **Sane file sizes.** One insert per (year) chunk produces a handful of
 *    large Parquet files rather than thousands of small ones — per-file round
 *    trips are the measured dominant cost when reading from object storage.
 */
final class HeroTrackerSeeder
{
    public const string FARM_ID = 'hero-tracker-farm-50';
    public const string FARM_TYPE = 'dairy';

    /** Its own partition, so its volume never lands on another farm's query. */
    public const string REGION = 'tracker-hero';

    public const int TRACKER_COUNT = 50;

    private const int FIXED_POINT = 10000;
    private const int REVENUE_WEIGHT = 3;

    /** Actuals up to and including this year; forecast after. */
    private const int HORIZON_YEAR = 2021;

    /**
     * 30 years, 1996-2025.
     *
     * Deliberately longer than any real Figured farm — the product has only
     * existed since ~2013, and real farms hold ~10 years. The span is stretched
     * for a partitioning reason, not a realism one: `year(date)` is the
     * partition key, so 30 years means 30 partitions of ~16.7M rows instead of
     * 10 of 50M. That makes narrowing a report's window actually reduce the
     * files it opens, which at 10 years it barely did — a one-month query and a
     * one-year query read the same files.
     *
     * Per-year density is still ~208x a real farm (16.7M vs ~80K). Nothing
     * about the span fixes that; only fewer rows would.
     */
    private const int FIRST_YEAR = 1996;
    private const int YEARS = 30;

    /** Same chart of accounts as the small tracker farms, so results compare. */
    private const array ACCOUNTS = [
        ['livestock-sales', 'REVENUE', 'tracker_income'],
        ['wool-income', 'REVENUE', 'tracker_income'],
        ['feed', 'EXPENSE', 'tracker_direct_costs'],
        ['animal-health', 'EXPENSE', 'tracker_direct_costs'],
        ['shearing', 'EXPENSE', 'tracker_direct_costs'],
        ['cartage', 'EXPENSE', 'tracker_direct_costs'],

        ['other-income', 'REVENUE', 'other_income'],
        ['sundry-income', 'REVENUE', 'other_income'],
        ['general-direct', 'EXPENSE', 'direct_costs'],
        ['wages', 'EXPENSE', 'operating_expenses'],
        ['fuel', 'EXPENSE', 'operating_expenses'],
        ['repairs', 'EXPENSE', 'operating_expenses'],
        ['insurance', 'EXPENSE', 'operating_expenses'],
        ['gst-control', 'LIABILITY', 'gst'],
    ];

    private const array STOCK_TYPES = ['Sheep', 'Cattle', 'Deer'];

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
        private readonly string $appAlias,
    ) {
    }

    /**
     * @param int $totalRows       total journal lines to write
     * @param int $chunkRows       rows per INSERT
     * @param null|callable(int, int, int, float): void $onChunk
     *        (chunkIndex, chunkCount, rowsWrittenSoFar, secondsElapsed)
     */
    public function seed(
        int $totalRows,
        int $chunkRows,
        ?callable $onChunk = null,
    ): void {
        $this->clearExisting();
        $this->seedFarm();
        $this->seedTrackers();

        // Driven by years, not by chunk index.
        //
        // The previous version mapped chunk -> year with `chunk % YEARS`, which
        // only distributes evenly when the chunk count is a multiple of the
        // year count. At 500M rows in 25M chunks over 30 years that is 20
        // chunks, so ten years would have received no rows at all — it had been
        // working by coincidence (40/10, 20/10). Deriving the plan from the
        // years makes even coverage structural rather than lucky.
        $plan = $this->chunkPlan($totalRows, $chunkRows);

        $chunkCount = count($plan);
        $startedAt = microtime(true);
        $written = 0;
        $offset = 0;

        foreach ($plan as $index => [$year, $rows]) {
            $this->insertChunk($offset, $rows, $year);

            $offset += $rows;
            $written += $rows;

            if ($onChunk !== null) {
                $onChunk($index + 1, $chunkCount, $written, microtime(true) - $startedAt);
            }
        }
    }

    /** How many inserts `seed()` will issue for this request. */
    public function plannedChunkCount(int $totalRows, int $chunkRows): int
    {
        return count($this->chunkPlan($totalRows, $chunkRows));
    }

    /**
     * Splits the requested total evenly across every year, then splits each
     * year into inserts no larger than `$chunkRows`.
     *
     * Each insert therefore targets exactly one year, and so lands in exactly
     * one partition — which is what keeps the sort bounded and the written
     * files aligned to partitions. Any remainder from uneven division goes to
     * the final year rather than being dropped.
     *
     * @return list<array{0: int, 1: int}> [year, rows] per insert
     */
    private function chunkPlan(int $totalRows, int $chunkRows): array
    {
        $perYear = intdiv($totalRows, self::YEARS);
        $remainder = $totalRows - ($perYear * self::YEARS);

        $plan = [];

        for ($y = 0; $y < self::YEARS; $y++) {
            $year = self::FIRST_YEAR + $y;
            $rowsThisYear = $perYear + ($y === self::YEARS - 1 ? $remainder : 0);

            while ($rowsThisYear > 0) {
                $rows = min($chunkRows, $rowsThisYear);
                $plan[] = [$year, $rows];
                $rowsThisYear -= $rows;
            }
        }

        return $plan;
    }

    /**
     * Accounts are shared with the small tracker farms and seeded by
     * `TrackerScaleSeeder`; this only ensures they exist so the hero farm can
     * be seeded independently.
     */
    public function ensureAccounts(): void
    {
        if (DB::table('accounts')->where('account_id', 'like', 'tracker-%')->exists()) {
            return;
        }

        $rows = [];
        foreach (self::ACCOUNTS as [$suffix, $class, $category]) {
            $rows[] = [
                'account_id' => 'tracker-'.$suffix,
                'account_name' => ucwords(str_replace('-', ' ', $suffix)),
                'account_class' => $class,
                'account_category' => $category,
                'is_gst_account' => $category === 'gst',
                'is_default_bank_account' => false,
            ];
        }

        DB::table('accounts')->insert($rows);
    }

    /**
     * Scoped to this farm's own id, so every other dataset in the lake — the
     * oracle, the `scale-` volume farms, the small `tracker-` farms — survives.
     */
    private function clearExisting(): void
    {
        $this->db->query(
            "DELETE FROM {$this->alias}.transaction_lines WHERE farm_id = '".self::FARM_ID."'"
        );

        DB::table('trackers')->where('farm_id', self::FARM_ID)->delete();
        DB::table('farms')->where('farm_id', self::FARM_ID)->delete();
    }

    private function seedFarm(): void
    {
        DB::table('farms')->insert([
            'farm_id' => self::FARM_ID,
            'farm_type' => self::FARM_TYPE,
            'region' => self::REGION,
            'opening_balance' => 0,
        ]);
    }

    private function seedTrackers(): void
    {
        $rows = [];

        for ($i = 0; $i < self::TRACKER_COUNT; $i++) {
            $stockType = self::STOCK_TYPES[$i % count(self::STOCK_TYPES)];

            $rows[] = [
                'tracker_id' => sprintf('%s-t%02d', self::FARM_ID, $i),
                'farm_id' => self::FARM_ID,
                'tracker_name' => sprintf('%s Mob %d', $stockType, $i + 1),
                'tracker_type' => 'livestock',
                'stock_type' => $stockType,
                'display_order' => $i,
            ];
        }

        DB::table('trackers')->insert($rows);
    }

    /**
     * One INSERT, one year, `$rows` rows generated inside DuckDB.
     *
     * `$offset` keeps `line_id` unique across chunks and keeps the account and
     * tracker cycles advancing rather than repeating identically per chunk.
     */
    private function insertChunk(int $offset, int $rows, int $year): void
    {
        $accountCount = count(self::ACCOUNTS);
        $fp = self::FIXED_POINT;
        $weight = self::REVENUE_WEIGHT;
        $trackers = self::TRACKER_COUNT;
        $type = $year <= self::HORIZON_YEAR ? 'actuals' : 'forecast';
        $upper = $offset + $rows;

        $sql = <<<SQL
            INSERT INTO {$this->alias}.transaction_lines
                (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id)
            SELECT
                '{$this->farmId()}',
                '{$this->farmType()}',
                '{$this->region()}',
                'hero-' || g.i,
                a.account_id,
                '{$type}',
                'cash',
                (make_date({$year}, 1, 1) + INTERVAL (g.day_offset) DAY)::DATE,
                CASE
                    WHEN a.account_class = 'REVENUE'
                        THEN -(((g.i % 500) + 100) * {$fp} * {$weight})
                    ELSE (((g.i % 500) + 100) * {$fp})
                END,
                CASE
                    WHEN a.account_category IN ('tracker_income', 'tracker_direct_costs')
                    THEN '{$this->farmId()}-t' || lpad(CAST(g.tracker_idx AS VARCHAR), 2, '0')
                END
            FROM (
                SELECT
                    i,
                    -- 7919 is prime, so dates scatter across the year instead
                    -- of marching in lockstep with the account cycle.
                    CAST((i * 7919) % 365 AS INTEGER) AS day_offset,
                    CAST(i % {$accountCount} AS INTEGER) AS acc_idx,
                    -- The CYCLE number, not the phase.
                    --
                    -- Using `i % trackers` correlates the tracker with the
                    -- account, because gcd(accounts, trackers) > 1: with 14
                    -- accounts and 50 trackers, gcd is 2, so even-numbered
                    -- trackers only ever saw even-numbered accounts. Both
                    -- income accounts are odd-indexed, so half the trackers
                    -- got costs and no income at all — every one of them
                    -- showing a large negative gross margin.
                    --
                    -- Dividing instead advances the tracker once per complete
                    -- pass through the chart of accounts, so every tracker
                    -- receives every account. Year is a per-chunk constant
                    -- here, so it cannot correlate with either.
                    CAST((i // {$accountCount}) % {$trackers} AS INTEGER) AS tracker_idx
                FROM range({$offset}, {$upper}) AS t(i)
            ) g
            JOIN (
                SELECT
                    account_id,
                    account_class,
                    account_category,
                    CAST(row_number() OVER (ORDER BY account_id) - 1 AS INTEGER) AS idx
                FROM {$this->appAlias}.accounts
                WHERE account_id LIKE 'tracker-%'
            ) a ON a.idx = g.acc_idx
            -- Within-chunk only. Tightens row-group min/max on `date`, which is
            -- what the report's `date BETWEEN` predicate prunes on.
            ORDER BY (make_date({$year}, 1, 1) + INTERVAL (g.day_offset) DAY)
            SQL;

        $this->db->query($sql);
    }

    private function farmId(): string
    {
        return self::FARM_ID;
    }

    private function farmType(): string
    {
        return self::FARM_TYPE;
    }

    private function region(): string
    {
        return self::REGION;
    }
}
