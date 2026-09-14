<?php

declare(strict_types=1);

namespace App\Services\Mongo;

use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;

/**
 * Loads the same dairy farm the other three stacks hold, laid out the way
 * Figured lays it out: journal lines in MongoDB, everything else in MySQL.
 *
 * The split is the point. `AlloyDbSeeder` writes facts and dimensions into one
 * database because that is the proposition it tests; here they are deliberately
 * torn apart, because no report can then be a single query and PHP has to do
 * the join. Reproducing that constraint faithfully is the only way the baseline
 * means anything.
 *
 * Dimensions are UPSERTED rather than replaced. MySQL is shared with the `gcs`
 * and `minio` profiles, which seed the same farms with the same values, so
 * rewriting them has to be a no-op instead of pulling the lake's dimensions out
 * from under it.
 *
 * Journal values mirror `AlloyDbSeeder`, which in turn mirrors
 * `GrossMarginV2Seeder` — same amounts, same dates, same actuals/forecast
 * split. Verified identical output across the same four periods. A timing
 * comparison against data that differs would measure the data.
 *
 * One structural difference has no workaround: **every document is pushed from
 * PHP.** The Postgres and DuckDB seeders generate rows inside the engine from
 * `generate_series`, so nothing crosses a client connection. Mongo has no
 * server-side generator, so a 500M-row load here is bounded by the driver and
 * the wire rather than by the storage engine. That is a genuine property of the
 * topology and belongs in the findings, not a handicap to engineer around.
 */
final class MongoSeeder
{
    private const int FIXED_POINT = 10_000;

    private const int FIRST_YEAR = 2024;

    private const int YEARS = 4;

    private const int KG_MS_PER_COW_PEAK = 40;

    private const int PAYOUT_PER_KG_MS = 8;

    private const int HERD = 950;

    private const string HORIZON_DATE = '2026-08-31';

    private const int OPENING_STOCK = 1_200;

    /** [suffix, name, type, stock class] */
    private const array TRACKERS = [
        ['milk', 'Milk Platform', 'milk', ''],
        ['ma-cows', 'MA Cows', 'livestock', 'MA Cows'],
        ['r2-heifers', 'R2 Heifers', 'livestock', 'R2 Heifers'],
        ['bobby-calves', 'Bobby Calves', 'livestock', 'Bobby Calves'],
        ['bulls', 'Breeding Bulls', 'livestock', 'Breeding Bulls'],
    ];

    /** [suffix, name, class, category, group, group label, group order, line order, tracker] */
    private const array ACCOUNTS = [
        ['milk-current', 'Milk Production - Current Year', 'REVENUE', 'tracker_income', 'dairy_income', 'Dairy Income', 1, 1, 'milk'],
        ['milk-deferred', 'Milk Production - Deferred', 'REVENUE', 'tracker_income', 'dairy_income', 'Dairy Income', 1, 2, 'milk'],
        ['sales-bobby', 'Sales - Dairy Bobby Calves', 'REVENUE', 'tracker_income', 'livestock_income', 'Livestock Income', 2, 1, 'bobby-calves'],
        ['sales-r2', 'Sales - Dairy R2 Heifers', 'REVENUE', 'tracker_income', 'livestock_income', 'Livestock Income', 2, 2, 'r2-heifers'],
        ['sales-ma', 'Sales - Dairy MA Cows', 'REVENUE', 'tracker_income', 'livestock_income', 'Livestock Income', 2, 3, 'ma-cows'],
        ['sales-bulls', 'Sales - Dairy Breeding Bulls', 'REVENUE', 'tracker_income', 'livestock_income', 'Livestock Income', 2, 4, 'bulls'],
        ['other-income', 'Other Income', 'REVENUE', 'tracker_income', 'other_income', 'Other', 3, 1, 'milk'],
        ['feed-supplement', 'Feed - Supplements', 'EXPENSE', 'tracker_direct_costs', 'dairy_costs', 'Dairy Costs', 4, 1, 'milk'],
        ['feed-grazing', 'Feed - Grazing', 'EXPENSE', 'tracker_direct_costs', 'dairy_costs', 'Dairy Costs', 4, 2, 'milk'],
        ['shed-costs', 'Shed & Milk Harvesting', 'EXPENSE', 'tracker_direct_costs', 'dairy_costs', 'Dairy Costs', 4, 3, 'milk'],
        ['animal-health', 'Animal Health', 'EXPENSE', 'tracker_direct_costs', 'livestock_costs', 'Livestock Costs', 5, 1, 'ma-cows'],
        ['breeding', 'Breeding & AI', 'EXPENSE', 'tracker_direct_costs', 'livestock_costs', 'Livestock Costs', 5, 2, 'ma-cows'],
        ['calf-rearing', 'Calf Rearing', 'EXPENSE', 'tracker_direct_costs', 'livestock_costs', 'Livestock Costs', 5, 3, 'bobby-calves'],
        ['stock-purchases', 'Stock Purchases', 'EXPENSE', 'tracker_direct_costs', 'livestock_costs', 'Livestock Costs', 5, 4, 'r2-heifers'],
    ];

    /** @var list<array<string, mixed>> */
    private array $pending = [];

    private int $written = 0;

    public function __construct(
        private readonly MongoConnectionFactory $mongo,
        private readonly string $farmId,
        private readonly string $region,
        private readonly int $bulkRows = 0,
    ) {}

    /**
     * @param  callable(int): void|null  $onProgress  Called with the running document count.
     * @return array{dimension_rows: int, fact_rows: int}
     */
    public function seed(?callable $onProgress = null): array
    {
        $dimensions = $this->seedDimensions();

        $this->mongo->journals()->deleteMany(['farm_id' => $this->farmId]);
        $facts = $this->seedJournals($onProgress);

        return ['dimension_rows' => $dimensions, 'fact_rows' => $facts];
    }

    /**
     * The index the report's `$match` relies on.
     *
     * Built after loading for the same reason the Postgres one is: maintaining
     * a btree through a bulk insert costs more than one build at the end. The
     * key order mirrors `transaction_lines_scope_idx` on AlloyDB and the lake's
     * (farm_id, basis, year) partitioning, so no engine is handed a better
     * access path than the others.
     */
    public function index(): void
    {
        $this->mongo->journals()->createIndex(
            ['farm_id' => 1, 'basis' => 1, 'date' => 1],
            ['name' => 'farm_basis_date']
        );
    }

    public function dropIndex(): void
    {
        try {
            $this->mongo->journals()->dropIndex('farm_basis_date');
        } catch (\Throwable) {
            // Absent on a first load; nothing to undo.
        }
    }

    private function seedDimensions(): int
    {
        $count = 0;

        DB::table('farms')->upsert(
            [['farm_id' => $this->farmId, 'farm_type' => 'dairy', 'region' => $this->region]],
            ['farm_id']
        );
        $count++;

        foreach (self::ACCOUNTS as [$suffix, $name, $class, $category, $group, $label, $groupOrder, $lineOrder]) {
            DB::table('accounts')->upsert([[
                'account_id' => 'gm-'.$suffix,
                'account_name' => $name,
                'account_class' => $class,
                'account_category' => $category,
                'report_group' => $group,
                'report_group_label' => $label,
                'report_group_order' => $groupOrder,
                'line_order' => $lineOrder,
            ]], ['account_id']);
            $count++;
        }

        $trackerIds = array_map(fn (array $t): string => $this->trackerId($t[0]), self::TRACKERS);

        DB::table('tracker_milk_production')->whereIn('tracker_id', $trackerIds)->delete();
        DB::table('tracker_stock_movements')->whereIn('tracker_id', $trackerIds)->delete();

        foreach (self::TRACKERS as $order => [$suffix, $name, $type, $stockType]) {
            DB::table('trackers')->upsert([[
                'tracker_id' => $this->trackerId($suffix),
                'farm_id' => $this->farmId,
                'tracker_name' => $name,
                'tracker_type' => $type,
                'stock_type' => $stockType,
                'opening_stock' => self::OPENING_STOCK,
                'display_order' => $order,
            ]], ['tracker_id']);
            $count++;
        }

        $count += $this->seedMilkProduction();
        $count += $this->seedStockMovements();

        return $count;
    }

    private function seedMilkProduction(): int
    {
        $rows = [];
        $trackerId = $this->trackerId('milk');

        for ($year = self::FIRST_YEAR; $year <= self::lastYear(); $year++) {
            for ($month = 1; $month <= 12; $month++) {
                $seasonal = match (true) {
                    in_array($month, [9, 10, 11], true) => 100,
                    in_array($month, [8, 12], true) => 80,
                    in_array($month, [1, 2, 3], true) => 60,
                    in_array($month, [4, 5], true) => 30,
                    default => 0,
                };

                $rows[] = [
                    'tracker_id' => $trackerId,
                    'month' => sprintf('%04d-%02d-01', $year, $month),
                    'kg_ms_current' => intdiv(self::HERD * self::KG_MS_PER_COW_PEAK * $seasonal, 100),
                    'kg_ms_deferred' => $month === 9 ? 13_000 : 0,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('tracker_milk_production')->insert($chunk);
        }

        return count($rows);
    }

    private function seedStockMovements(): int
    {
        $rows = [];

        foreach (self::TRACKERS as $index => [$suffix]) {
            $trackerId = $this->trackerId($suffix);

            for ($year = self::FIRST_YEAR; $year <= self::lastYear(); $year++) {
                $phase = ($index + $year) % 3;

                for ($month = 1; $month <= 12; $month++) {
                    $rows[] = [
                        'tracker_id' => $trackerId,
                        'month' => sprintf('%04d-%02d-01', $year, $month),
                        'purchases' => $month === 7 ? 40 : 0,
                        'births' => in_array($month, [9, 10, 11], true) ? 120 + ($phase * 10) : 0,
                        'sales' => in_array($month, [3, 4, 5], true) ? 110 + ($phase * 10) : 0,
                        'deaths' => 3 + (($index + $month) % 4),
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('tracker_stock_movements')->insert($chunk);
        }

        return count($rows);
    }

    private function seedJournals(?callable $onProgress): int
    {
        $perMonth = $this->linesPerAccountMonth();

        foreach (self::ACCOUNTS as [$suffix, , $class, , , , , , $trackerSuffix]) {
            $accountId = 'gm-'.$suffix;
            $trackerId = $this->trackerId($trackerSuffix);

            if ($suffix === 'milk-current' || $suffix === 'milk-deferred') {
                $this->milkLines($accountId, $trackerId, $suffix === 'milk-current' ? 'kg_ms_current' : 'kg_ms_deferred', $onProgress);

                continue;
            }

            $this->accountLines($accountId, $trackerId, $class, $perMonth, $onProgress);
        }

        $this->flush($onProgress);

        return $this->written;
    }

    /**
     * Milk revenue derived from the production table, not fanned out.
     *
     * Reading the quantities back out of MySQL rather than recomputing them is
     * deliberate: it is the same dependency the report has, and if the two ever
     * disagreed the seeder would be hiding it.
     */
    private function milkLines(string $accountId, string $trackerId, string $column, ?callable $onProgress): void
    {
        $horizon = strtotime(self::HORIZON_DATE);

        $production = DB::table('tracker_milk_production')
            ->where('tracker_id', $trackerId)
            ->where($column, '>', 0)
            ->orderBy('month')
            ->get(['month', $column]);

        foreach ($production as $row) {
            $monthStart = strtotime((string) $row->month);
            $kg = (int) $row->{$column};

            $this->push([
                'farm_id' => $this->farmId,
                'farm_type' => 'dairy',
                'region' => $this->region,
                'line_id' => $accountId.'-'.date('Ym', $monthStart),
                'account_id' => $accountId,
                'type' => $monthStart <= $horizon ? 'actuals' : 'forecast',
                'basis' => 'cash',
                'date' => new UTCDateTime(strtotime('+14 days', $monthStart) * 1000),
                'amount' => -($kg * self::PAYOUT_PER_KG_MS * self::FIXED_POINT),
                'tracker_id' => $trackerId,
            ], $onProgress);
        }
    }

    private function accountLines(string $accountId, string $trackerId, string $class, int $perMonth, ?callable $onProgress): void
    {
        $sign = $class === 'REVENUE' ? -1 : 1;
        $base = $class === 'REVENUE' ? 9_000 : 6_000;
        $horizon = strtotime(self::HORIZON_DATE);

        for ($year = self::FIRST_YEAR; $year <= self::lastYear(); $year++) {
            for ($month = 1; $month <= 12; $month++) {
                $monthStart = mktime(0, 0, 0, $month, 1, $year);
                $type = $monthStart <= $horizon ? 'actuals' : 'forecast';

                // Rounded, not truncated, and rounded the same way Postgres is
                // told to round — `ROUND(x::NUMERIC * fp / perMonth)`. Integer
                // division here would put every line a cent low and make the
                // engines disagree by a rounding artefact rather than anything
                // real. See the comment in AlloyDbSeeder::insertAccountLines.
                $amount = $sign * (int) round(($base + ($month * 400)) * self::FIXED_POINT / $perMonth);

                $prefix = $accountId.'-'.sprintf('%04d%02d', $year, $month).'-';

                for ($n = 0; $n < $perMonth; $n++) {
                    $this->push([
                        'farm_id' => $this->farmId,
                        'farm_type' => 'dairy',
                        'region' => $this->region,
                        'line_id' => $prefix.$n,
                        'account_id' => $accountId,
                        'type' => $type,
                        'basis' => 'cash',
                        'date' => new UTCDateTime(strtotime('+'.($n % 28).' days', $monthStart) * 1000),
                        'amount' => $amount,
                        'tracker_id' => $trackerId,
                    ], $onProgress);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function push(array $document, ?callable $onProgress): void
    {
        $this->pending[] = $document;

        if (count($this->pending) >= (int) config('mongo.seed_batch')) {
            $this->flush($onProgress);
        }
    }

    private function flush(?callable $onProgress): void
    {
        if ($this->pending === []) {
            return;
        }

        // Unordered, so the server may apply the batch in parallel and does not
        // stop the whole write at the first rejected document. There are no
        // unique constraints beyond _id here, so nothing is being masked.
        $this->mongo->journals()->insertMany($this->pending, ['ordered' => false]);

        $this->written += count($this->pending);
        $this->pending = [];

        if ($onProgress !== null) {
            $onProgress($this->written);
        }
    }

    /**
     * Fan-out per account-month, derived from the requested total.
     *
     * Volume changes how much the report scans, never what it reports: the
     * per-line amount is divided by the fan-out, so the monthly total holds
     * constant at any scale.
     */
    private function linesPerAccountMonth(): int
    {
        if ($this->bulkRows < 1) {
            return 1;
        }

        $fanned = count(self::ACCOUNTS) - 2;

        return max(1, intdiv($this->bulkRows, $fanned * self::YEARS * 12));
    }

    private function trackerId(string $suffix): string
    {
        return $this->farmId.'-'.$suffix;
    }

    private static function lastYear(): int
    {
        return self::FIRST_YEAR + self::YEARS - 1;
    }
}
