<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Saturio\DuckDB\DuckDB;

/**
 * Seeds the Cash Flow parity oracle: the exact scenario captured from
 * Figured's real V2 CashFlowStructureBuilder + ReportRunnerService, whose
 * expected output is documented in this project's README.
 *
 * Deliberately tiny (4 transaction lines) so a mismatch in the DuckDB SQL can
 * be traced row by row rather than hunted through synthetic volume.
 *
 * SIGN CONVENTION — the whole exercise turns on this. Revenue is stored as a
 * credit (NEGATIVE), expenses as a debit (POSITIVE), matching Xero's own
 * journal convention ("positive value for a debit and negative for a credit")
 * that Figured's actuals inherit by being synced from Xero journals. Seeding
 * revenue as an intuitive positive number produces a report that is wrong by
 * 2x the revenue figure while still looking internally consistent — see the
 * README's oracle section for the full account of that failure.
 */
final class CashFlowOracleSeeder
{
    public const string FARM_ID = 'oracle-farm';
    public const string FARM_TYPE = 'dairy';
    public const string REGION = 'waikato';

    /** Fixed-point multiplier — matches Figured's TEN_THOUSAND convention. */
    private const int FIXED_POINT = 10000;

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
    ) {
    }

    public function seed(): void
    {
        $this->clearExisting();
        $this->seedFarm();
        $this->seedAccounts();
        $this->seedTransactionLines();
    }

    private function clearExisting(): void
    {
        $farmId = self::FARM_ID;

        $this->db->query("DELETE FROM {$this->alias}.transaction_lines WHERE farm_id = '{$farmId}'");
        $this->db->query("DELETE FROM {$this->alias}.farms WHERE farm_id = '{$farmId}'");
        $this->db->query("DELETE FROM {$this->alias}.accounts WHERE account_id IN ('acc-sales', 'acc-wages')");
    }

    private function seedFarm(): void
    {
        // opening_balance 0 — the oracle's first interval opens at zero.
        $this->db->query(<<<SQL
            INSERT INTO {$this->alias}.farms (farm_id, farm_type, region, opening_balance)
            VALUES ('{$this->farmId()}', '{$this->farmType()}', '{$this->region()}', 0)
            SQL);
    }

    private function seedAccounts(): void
    {
        $this->db->query(<<<SQL
            INSERT INTO {$this->alias}.accounts
                (account_id, account_name, account_class, account_category, is_gst_account, is_default_bank_account)
            VALUES
                ('acc-sales', 'DuckPoc Sales', 'REVENUE', 'other_income',       false, false),
                ('acc-wages', 'DuckPoc Wages', 'EXPENSE', 'operating_expenses', false, false)
            SQL);
    }

    private function seedTransactionLines(): void
    {
        $rows = [
            // Actuals — dated before the 2024-02-28 horizon.
            ['line-1', 'acc-sales', 'actuals',  '2024-01-15', -1000.00],
            ['line-2', 'acc-wages', 'actuals',  '2024-01-20',   200.00],
            // Forecast — dated after the horizon.
            ['line-3', 'acc-sales', 'forecast', '2024-08-15',  -500.00],
            ['line-4', 'acc-wages', 'forecast', '2024-08-20',   100.00],
        ];

        $values = [];
        foreach ($rows as [$lineId, $accountId, $type, $date, $dollars]) {
            $amount = (int) round($dollars * self::FIXED_POINT);

            $values[] = sprintf(
                "('%s', '%s', '%s', '%s', '%s', '%s', 'cash', DATE '%s', %d)",
                $this->farmId(),
                $this->farmType(),
                $this->region(),
                $lineId,
                $accountId,
                $type,
                $date,
                $amount,
            );
        }

        // ORDER BY farm_id on the way in: farm_id is the physical sort key
        // within each (farm_type, region, year) partition, which is what lets
        // Parquet row-group statistics prune on it. Moot for four rows, kept
        // so the intent is visible where the real seeders will copy it.
        $sql = sprintf(
            'INSERT INTO %s.transaction_lines'
            .' (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount)'
            .' SELECT * FROM (VALUES %s) AS v ORDER BY 1',
            $this->alias,
            implode(', ', $values),
        );

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
