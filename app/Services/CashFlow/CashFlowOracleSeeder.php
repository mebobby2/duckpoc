<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Illuminate\Support\Facades\DB;
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
    public const string FARM_ID = 'cashflow-oracle-farm';
    public const string FARM_TYPE = 'dairy';
    public const string REGION = 'waikato';

    /**
     * Farm ids this seeder used to write under. Cleared alongside the current
     * one so a rename doesn't leave rows behind that no seeder owns any more —
     * DuckLake has no foreign keys or cascade, so orphans just sit there and
     * quietly inflate the cohort partition.
     */
    private const array LEGACY_FARM_IDS = ['oracle-farm'];

    /** Dimension rows this seeder owns, so it clears only its own. */
    private const array ACCOUNT_IDS = ['acc-sales', 'acc-wages'];

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

    /**
     * Fact rows come out of the lake; dimension rows out of MySQL. Two stores,
     * because that is where each kind of data actually lives.
     */
    private function clearExisting(): void
    {
        $farmIds = [self::FARM_ID, ...self::LEGACY_FARM_IDS];

        $quoted = implode(
            ', ',
            array_map(
                static fn (string $id): string => "'".str_replace("'", "''", $id)."'",
                $farmIds,
            ),
        );

        $this->db->query("DELETE FROM {$this->alias}.transaction_lines WHERE farm_id IN ({$quoted})");

        DB::table('farms')->whereIn('farm_id', $farmIds)->delete();
        DB::table('accounts')->whereIn('account_id', self::ACCOUNT_IDS)->delete();
    }

    private function seedFarm(): void
    {
        // opening_balance 0 — the oracle's first interval opens at zero.
        DB::table('farms')->insert([
            'farm_id' => self::FARM_ID,
            'farm_type' => self::FARM_TYPE,
            'region' => self::REGION,
            'opening_balance' => 0,
        ]);
    }

    private function seedAccounts(): void
    {
        DB::table('accounts')->insert([
            [
                'account_id' => 'acc-sales',
                'account_name' => 'DuckPoc Sales',
                'account_class' => 'REVENUE',
                'account_category' => 'other_income',
                'is_gst_account' => false,
                'is_default_bank_account' => false,
            ],
            [
                'account_id' => 'acc-wages',
                'account_name' => 'DuckPoc Wages',
                'account_class' => 'EXPENSE',
                'account_category' => 'operating_expenses',
                'is_gst_account' => false,
                'is_default_bank_account' => false,
            ],
        ]);
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
