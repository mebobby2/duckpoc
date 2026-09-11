<?php

declare(strict_types=1);

namespace App\Services\AlloyDb;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Loads AlloyDB with data equivalent to the DuckLake path, so the two can be
 * timed against each other rather than against each other's data.
 *
 * Two different strategies, on purpose:
 *
 * - **Dimensions are copied from MySQL**, not regenerated. Accounts, trackers,
 *   milk production and stock movements total a few hundred rows, and copying
 *   makes them identical by construction. Regenerating them would risk the two
 *   sides disagreeing about, say, a tracker's opening stock — and then the
 *   reports would differ for reasons that have nothing to do with the engines.
 * - **Facts are generated inside Postgres**, mirroring
 *   `GrossMarginV2Seeder::insertAccountLines()` formula for formula. Shipping
 *   500M rows from PHP would measure the client, not the database.
 *
 * The formulas are duplicated rather than shared because the two dialects
 * differ in every function that matters — `strftime` vs `to_char`, `range` vs
 * `generate_series`, `INTERVAL n DAY` vs `n * INTERVAL '1 day'`. A shared
 * builder would be a translation layer wrapping two literal strings.
 */
final class AlloyDbSeeder
{
    private const int FIXED_POINT = 10_000;
    private const int FIRST_YEAR = 2024;
    private const int YEARS = 4;
    private const int PAYOUT_PER_KG_MS = 8;
    private const string HORIZON_DATE = '2026-08-31';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly string $farmId,
        private readonly int $bulkRows = 0,
    ) {
    }

    /**
     * @return array{dimension_rows: int, fact_rows: int}
     */
    public function seed(): array
    {
        $dimensions = $this->copyDimensions();
        $this->db->statement('DELETE FROM transaction_lines WHERE farm_id = ?', [$this->farmId]);
        $facts = $this->generateFacts();

        return ['dimension_rows' => $dimensions, 'fact_rows' => $facts];
    }

    private function copyDimensions(): int
    {
        $copied = 0;

        $farm = DB::table('farms')->where('farm_id', $this->farmId)->first();
        $this->db->table('farms')->upsert([
            ['farm_id' => $farm->farm_id, 'farm_type' => $farm->farm_type, 'region' => $farm->region],
        ], ['farm_id']);
        $copied++;

        $accounts = DB::table('accounts')->where('account_id', 'like', 'gm-%')->get();
        foreach ($accounts as $account) {
            $this->db->table('accounts')->upsert([[
                'account_id' => $account->account_id,
                'account_name' => $account->account_name,
                'account_class' => $account->account_class,
                'account_category' => $account->account_category,
                'report_group' => $account->report_group,
                'report_group_label' => $account->report_group_label,
                'report_group_order' => $account->report_group_order,
                'line_order' => $account->line_order,
            ]], ['account_id']);
            $copied++;
        }

        $trackers = DB::table('trackers')->where('farm_id', $this->farmId)->get();
        $trackerIds = $trackers->pluck('tracker_id')->all();

        foreach ($trackers as $tracker) {
            $this->db->table('trackers')->upsert([[
                'tracker_id' => $tracker->tracker_id,
                'farm_id' => $tracker->farm_id,
                'tracker_name' => $tracker->tracker_name,
                'tracker_type' => $tracker->tracker_type,
                'stock_type' => $tracker->stock_type,
                'opening_stock' => $tracker->opening_stock,
                'display_order' => $tracker->display_order,
            ]], ['tracker_id']);
            $copied++;
        }

        if ($trackerIds !== []) {
            $this->db->table('tracker_milk_production')->whereIn('tracker_id', $trackerIds)->delete();
            $this->db->table('tracker_stock_movements')->whereIn('tracker_id', $trackerIds)->delete();
        }

        foreach (DB::table('tracker_milk_production')->whereIn('tracker_id', $trackerIds)->get() as $row) {
            $this->db->table('tracker_milk_production')->insert([
                'tracker_id' => $row->tracker_id,
                'month' => $row->month,
                'kg_ms_current' => $row->kg_ms_current,
                'kg_ms_deferred' => $row->kg_ms_deferred,
            ]);
            $copied++;
        }

        foreach (DB::table('tracker_stock_movements')->whereIn('tracker_id', $trackerIds)->get() as $row) {
            $this->db->table('tracker_stock_movements')->insert([
                'tracker_id' => $row->tracker_id,
                'month' => $row->month,
                'purchases' => $row->purchases,
                'births' => $row->births,
                'sales' => $row->sales,
                'deaths' => $row->deaths,
            ]);
            $copied++;
        }

        return $copied;
    }

    private function generateFacts(): int
    {
        $farm = $this->db->table('farms')->where('farm_id', $this->farmId)->first();
        $accounts = $this->db->table('accounts')->where('account_id', 'like', 'gm-%')->get();
        $trackers = $this->db->table('trackers')->where('farm_id', $this->farmId)->get()->keyBy('tracker_type');

        $milkTracker = $trackers['milk']->tracker_id ?? null;
        $perMonth = $this->linesPerAccountMonth($accounts->count());

        foreach ($accounts as $account) {
            $trackerId = $this->trackerFor($account->account_id, $this->farmId);

            if (str_contains($account->account_id, 'milk-current') || str_contains($account->account_id, 'milk-deferred')) {
                $column = str_contains($account->account_id, 'milk-current') ? 'kg_ms_current' : 'kg_ms_deferred';
                $this->insertMilkLines($account->account_id, (string) $milkTracker, $column, $farm);

                continue;
            }

            $this->insertAccountLines($account->account_id, $trackerId, $account->account_class, $farm, $perMonth);
        }

        $count = $this->db->selectOne(
            'SELECT count(*) AS n FROM transaction_lines WHERE farm_id = ?',
            [$this->farmId]
        );

        return (int) $count->n;
    }

    private function insertMilkLines(string $accountId, string $trackerId, string $column, object $farm): void
    {
        $payout = self::PAYOUT_PER_KG_MS;
        $fp = self::FIXED_POINT;

        $this->db->statement(<<<SQL
            INSERT INTO transaction_lines
                (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id)
            SELECT
                ?, ?, ?,
                ? || to_char(mp.month, 'YYYYMM'),
                ?,
                CASE WHEN mp.month <= DATE '{$this->horizonDate()}' THEN 'actuals' ELSE 'forecast' END,
                'cash',
                (mp.month + INTERVAL '14 days')::DATE,
                -- Cast before multiplying, exactly as the DuckDB side does:
                -- kg_ms x payout x 10,000 overflows a 32-bit integer.
                -(mp.{$column}::BIGINT * {$payout} * {$fp}),
                ?
            FROM tracker_milk_production mp
            WHERE mp.tracker_id = ? AND mp.{$column} > 0
            SQL, [
                $this->farmId, $farm->farm_type, $farm->region,
                $accountId.'-', $accountId, $trackerId, $trackerId,
            ]);
    }

    private function insertAccountLines(
        string $accountId,
        string $trackerId,
        string $class,
        object $farm,
        int $perMonth,
    ): void {
        $fp = self::FIXED_POINT;
        $sign = $class === 'REVENUE' ? '-' : '';
        $base = $class === 'REVENUE' ? 9_000 : 6_000;

        // One insert per year, mirroring the DuckDB seeder's loop. There it kept
        // each write inside one partition; here it just bounds the size of any
        // single statement.
        for ($year = self::FIRST_YEAR; $year <= self::lastYear(); $year++) {
            $this->db->statement(<<<SQL
                INSERT INTO transaction_lines
                    (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id)
                SELECT
                    ?, ?, ?,
                    ? || to_char(m.month_start, 'YYYYMM') || '-' || g.n::TEXT,
                    ?,
                    CASE WHEN m.month_start <= DATE '{$this->horizonDate()}' THEN 'actuals' ELSE 'forecast' END,
                    'cash',
                    (m.month_start + ((g.n % 28) * INTERVAL '1 day'))::DATE,
                    {$sign}(({$base} + (EXTRACT(MONTH FROM m.month_start)::INT * 400)) * {$fp} / {$perMonth})::BIGINT,
                    ?
                FROM generate_series(
                    DATE '{$year}-01-01',
                    DATE '{$year}-12-01',
                    INTERVAL '1 month'
                ) AS m(month_start)
                CROSS JOIN generate_series(0, {$perMonth} - 1) AS g(n)
                SQL, [
                    $this->farmId, $farm->farm_type, $farm->region,
                    $accountId.'-', $accountId, $trackerId,
                ]);
        }
    }

    private function trackerFor(string $accountId, string $farmId): string
    {
        $map = [
            'sales-bobby' => 'bobby-calves',
            'sales-r2' => 'r2-heifers',
            'sales-ma' => 'ma-cows',
            'sales-bulls' => 'bulls',
        ];

        foreach ($map as $needle => $suffix) {
            if (str_contains($accountId, $needle)) {
                return $farmId.'-'.$suffix;
            }
        }

        return $farmId.'-milk';
    }

    private function linesPerAccountMonth(int $accountCount): int
    {
        if ($this->bulkRows < 1) {
            return 1;
        }

        // Two milk accounts are driven by the production table, not fanned out.
        $fanned = max(1, $accountCount - 2);

        return max(1, intdiv($this->bulkRows, $fanned * self::YEARS * 12));
    }

    private function horizonDate(): string
    {
        return self::HORIZON_DATE;
    }

    private static function lastYear(): int
    {
        return self::FIRST_YEAR + self::YEARS - 1;
    }
}
