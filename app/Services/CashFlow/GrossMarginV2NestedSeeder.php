<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Saturio\DuckDB\DuckDB;

/**
 * Writes the same farm as `GrossMarginV2Seeder --raw`, nested the way Figured
 * stores it: one row per TRANSACTION, carrying its lines in a list.
 *
 * The flat farm and this one hold identical facts. An invoice that is 3.5 rows
 * in `transaction_lines` is one row in `transactions` with 3.5 entries in its
 * `lines` list. That is the only difference, and it is the whole experiment:
 * the report's filtering column lives inside the list here, so nothing can be
 * skipped until `UNNEST` has expanded it — the relational `$unwind`.
 *
 * Dimensions are NOT touched. Accounts, trackers, milk production and stock
 * movements are whatever the flat seeder already put in MySQL, so the two
 * farms differ in fact layout and in nothing else.
 */
final class GrossMarginV2NestedSeeder
{
    private const int FIXED_POINT = 10000;

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
        private readonly string $appAlias,
        private readonly string $farmIdValue,
        private readonly string $regionValue,
        private readonly int $bulkRows,
    ) {
    }

    public function seed(): void
    {
        $this->db->query(
            "DELETE FROM {$this->alias}.transactions WHERE farm_id = '{$this->farmIdValue}'"
        );

        foreach (GrossMarginV2Seeder::reportAccounts() as [$suffix, $class, $trackerSuffix]) {
            $this->insertTransactions('gm-'.$suffix, $this->farmIdValue.'-'.$trackerSuffix, $class);
        }

        // Milk carries no bookkeeping legs, matching the flat seeder: the
        // payout is derived from recorded production rather than fanned out,
        // so it is one line per month and stays that way. Skipping it would
        // leave the report with no Dairy Income at all.
        foreach (['milk-current' => 'kg_ms_current', 'milk-deferred' => 'kg_ms_deferred'] as $suffix => $column) {
            $this->insertMilkTransactions('gm-'.$suffix, $this->farmIdValue.'-milk', $column);
        }
    }

    private function insertMilkTransactions(string $accountId, string $trackerId, string $column): void
    {
        $fp = self::FIXED_POINT;
        $payout = GrossMarginV2Seeder::payoutPerKgMs();

        $this->db->query(<<<SQL
            INSERT INTO {$this->alias}.transactions
                (farm_id, farm_type, region, transaction_id, type, basis, date, lines)
            SELECT
                '{$this->farmIdValue}', 'dairy', '{$this->regionValue}',
                '{$accountId}-' || strftime(mp.month, '%Y%m'),
                CASE WHEN mp.month <= DATE '{$this->horizonDate()}' THEN 'actuals' ELSE 'forecast' END,
                'cash',
                (mp.month + INTERVAL 14 DAY)::DATE,
                [{
                    'line_id': '{$accountId}-' || strftime(mp.month, '%Y%m'),
                    'account_id': '{$accountId}',
                    -- Cast BEFORE multiplying: kg_ms x payout x 10,000
                    -- overflows INT32 at realistic production volumes.
                    'amount': -(CAST(mp.{$column} AS BIGINT) * {$payout} * {$fp}),
                    'tracker_id': '{$trackerId}'
                }]
            FROM {$this->appAlias}.tracker_milk_production mp
            WHERE mp.tracker_id = '{$trackerId}' AND mp.{$column} > 0
            SQL);
    }

    /**
     * One transaction per report line, holding that line plus its bookkeeping
     * legs — the shape a real invoice has.
     *
     * GST appears on every second transaction, so the list length alternates
     * between 4 and 3 and averages 3.5. That is not decoration: a fixed-length
     * list would let the reader assume a constant stride, and Figured's
     * documents vary too.
     */
    private function insertTransactions(string $accountId, string $trackerId, string $class): void
    {
        $fp = self::FIXED_POINT;
        $signMult = $class === 'REVENUE' ? -1 : 1;
        $base = $class === 'REVENUE' ? 9_000 : 6_000;
        $perMonth = $this->linesPerAccountMonth();
        $counterparty = $class === 'REVENUE' ? 'gm-accounts-receivable' : 'gm-accounts-payable';

        // Division last, so the report leg (multiple 1.0) is bit-identical to
        // the flat farm's. The two layouts must agree to the cent or the A/B is
        // measuring arithmetic rather than nesting.
        $amount = fn (float $multiple): string => sprintf(
            'CAST(%d * ((%d + (month(m.month_start) * 400)) * %d * %s) / %d AS BIGINT)',
            $signMult, $base, $fp, $multiple, $perMonth
        );

        $lineId = fn (string $suffix): string => sprintf(
            "'%s-' || strftime(m.month_start, '%%Y%%m') || '-' || g.n || '%s'",
            $accountId, $suffix
        );

        $struct = fn (string $idSuffix, string $account, float $multiple, ?string $tracker): string => sprintf(
            "{'line_id': %s, 'account_id': '%s', 'amount': %s, 'tracker_id': %s}",
            $lineId($idSuffix), $account, $amount($multiple),
            $tracker === null ? 'NULL' : "'".$tracker."'"
        );

        $report = $struct('', $accountId, 1.0, $trackerId);
        $gst = $struct('-gst', 'gm-gst', 0.15, null);
        $cp = $struct('-cp', $counterparty, -1.15, null);
        $bank = $struct('-bank', 'gm-bank', 1.15, null);

        for ($year = GrossMarginV2Seeder::firstYear(); $year <= GrossMarginV2Seeder::lastYear(); $year++) {
            $this->db->query(<<<SQL
                INSERT INTO {$this->alias}.transactions
                    (farm_id, farm_type, region, transaction_id, type, basis, date, lines)
                SELECT
                    '{$this->farmIdValue}', 'dairy', '{$this->regionValue}',
                    '{$accountId}-' || strftime(m.month_start, '%Y%m') || '-' || g.n,
                    CASE WHEN m.month_start <= DATE '{$this->horizonDate()}' THEN 'actuals' ELSE 'forecast' END,
                    'cash',
                    (m.month_start + INTERVAL (CAST(g.n % 28 AS INTEGER)) DAY)::DATE,
                    CASE WHEN g.n % 2 = 0
                         THEN [{$report}, {$gst}, {$cp}, {$bank}]
                         ELSE [{$report}, {$cp}, {$bank}]
                    END
                FROM generate_series(
                    DATE '{$year}-01-01',
                    DATE '{$year}-12-01',
                    INTERVAL 1 MONTH
                ) AS m(month_start)
                CROSS JOIN range(0, {$perMonth}) AS g(n)
                SQL);
        }
    }

    private function linesPerAccountMonth(): int
    {
        if ($this->bulkRows < 1) {
            return 1;
        }

        return max(1, intdiv($this->bulkRows, count(GrossMarginV2Seeder::reportAccounts()) * GrossMarginV2Seeder::yearCount() * 12));
    }

    private function horizonDate(): string
    {
        return GrossMarginV2Seeder::HORIZON_DATE;
    }
}
