<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;

/**
 * Seeds synthetic transaction volume for the scale test.
 *
 * Two things this is shaped to exercise, neither of which the 4-row oracle
 * touches at all:
 *
 * 1. **Partition pruning.** Farms are spread across three
 *    `(farm_type, region)` cohorts, so a single-cohort query has other
 *    partitions it must skip. With one partition there is nothing to prune.
 * 2. **Row-group pruning on `farm_id`.** The hero farm shares a cohort with
 *    the oracle farm deliberately — so re-running the oracle's parity check
 *    afterwards means finding 4 rows inside a partition holding ~800K, which
 *    is exactly the case `farm_id` sort order exists to make cheap. Parquet
 *    row groups are 122,880 rows by default, so this is also the first volume
 *    at which more than one row group even forms.
 *
 * Rows are generated inside DuckDB from `range()` rather than built in PHP
 * and inserted — 800K round trips through FFI would dominate the runtime and
 * measure the wrong thing.
 *
 * The sign convention is the same one everything else here depends on:
 * revenue negative (credit), expenses positive (debit). Synthetic data that
 * got this backwards would still aggregate, just to meaningless totals.
 */
final class CashFlowScaleSeeder
{
    private const int FIXED_POINT = 10000;

    /** Actuals up to and including this year; forecast after it. */
    private const int HORIZON_YEAR = 2021;

    private const int FIRST_YEAR = 2018;
    private const int YEARS = 8;

    /**
     * farm_id => [farm_type, region, row target]
     *
     * The hero farm's ~800K matches the largest real Figured farm measured
     * (an 8-year farm at ~800K journal rows, roughly half actuals / half
     * forecast). The rest exist to give pruning something to skip.
     */
    private const array FARMS = [
        'scale-hero-farm' => ['dairy', 'waikato', 800_000],
        'scale-farm-a' => ['dairy', 'waikato', 20_000],
        'scale-farm-b' => ['dairy', 'canterbury', 20_000],
        'scale-farm-c' => ['dairy', 'canterbury', 20_000],
        'scale-farm-d' => ['sheep', 'otago', 20_000],
    ];

    /** Chart of accounts: [suffix, class, category] */
    private const array ACCOUNTS = [
        ['milk-income', 'REVENUE', 'other_income'],
        ['livestock-sales', 'REVENUE', 'other_income'],
        ['crop-sales', 'REVENUE', 'other_income'],
        ['wool-income', 'REVENUE', 'other_income'],
        ['grazing-income', 'REVENUE', 'other_income'],
        ['sundry-income', 'REVENUE', 'other_income'],
        ['feed', 'EXPENSE', 'direct_costs'],
        ['fertiliser', 'EXPENSE', 'direct_costs'],
        ['animal-health', 'EXPENSE', 'direct_costs'],
        ['seed', 'EXPENSE', 'direct_costs'],
        ['cartage', 'EXPENSE', 'direct_costs'],
        ['shearing', 'EXPENSE', 'direct_costs'],
        ['wages', 'EXPENSE', 'operating_expenses'],
        ['repairs', 'EXPENSE', 'operating_expenses'],
        ['fuel', 'EXPENSE', 'operating_expenses'],
        ['insurance', 'EXPENSE', 'operating_expenses'],
        ['rates', 'EXPENSE', 'operating_expenses'],
        ['electricity', 'EXPENSE', 'operating_expenses'],
        ['admin', 'EXPENSE', 'operating_expenses'],
        ['professional-fees', 'EXPENSE', 'operating_expenses'],
    ];

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
        private readonly string $appAlias,
    ) {
    }

    /**
     * @param null|callable(string, int): void $onFarmSeeded
     */
    public function seed(?callable $onFarmSeeded = null): void
    {
        $this->clearExisting();
        $this->seedAccounts();
        $this->seedFarms();

        foreach (self::FARMS as $farmId => [$farmType, $region, $rowTarget]) {
            $this->seedTransactionLines($farmId, $farmType, $region, $rowTarget);

            if ($onFarmSeeded !== null) {
                $onFarmSeeded($farmId, $rowTarget);
            }
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function farms(): array
    {
        return self::FARMS;
    }

    public static function totalRows(): int
    {
        return array_sum(array_column(self::FARMS, 2));
    }

    /**
     * Fact rows come out of the lake; dimension rows out of MySQL. Scoped to
     * this seeder's own `scale-` ids so the Cash Flow oracle farm — which
     * shares a cohort with the hero farm — survives untouched.
     */
    private function clearExisting(): void
    {
        $this->db->query("DELETE FROM {$this->alias}.transaction_lines WHERE farm_id LIKE 'scale-%'");

        DB::table('farms')->where('farm_id', 'like', 'scale-%')->delete();
        DB::table('accounts')->where('account_id', 'like', 'scale-%')->delete();
    }

    private function seedAccounts(): void
    {
        $rows = [];
        foreach (self::ACCOUNTS as [$suffix, $class, $category]) {
            $rows[] = [
                'account_id' => 'scale-'.$suffix,
                'account_name' => ucwords(str_replace('-', ' ', $suffix)),
                'account_class' => $class,
                'account_category' => $category,
                'is_gst_account' => false,
                'is_default_bank_account' => false,
            ];
        }

        DB::table('accounts')->insert($rows);
    }

    private function seedFarms(): void
    {
        $rows = [];
        foreach (self::FARMS as $farmId => [$farmType, $region]) {
            $rows[] = [
                'farm_id' => $farmId,
                'farm_type' => $farmType,
                'region' => $region,
                'opening_balance' => 0,
            ];
        }

        DB::table('farms')->insert($rows);
    }

    /**
     * One INSERT per farm, rows synthesised in DuckDB from `range()`.
     *
     * `i` is mapped to an account, a year and a day-of-year by modular
     * arithmetic; the 7919 multiplier is a prime, which scatters dates across
     * the year instead of marching through it in lockstep with the account
     * cycle.
     */
    private function seedTransactionLines(string $farmId, string $farmType, string $region, int $rowTarget): void
    {
        $accountCount = count(self::ACCOUNTS);
        $fp = self::FIXED_POINT;

        $sql = <<<SQL
            INSERT INTO {$this->alias}.transaction_lines
                (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount)
            SELECT
                '{$farmId}',
                '{$farmType}',
                '{$region}',
                '{$farmId}-' || g.i,
                a.account_id,
                CASE WHEN g.yr <= {$this->horizonYear()} THEN 'actuals' ELSE 'forecast' END,
                'cash',
                (make_date(g.yr, 1, 1) + INTERVAL (g.day_offset) DAY)::DATE,
                -- Revenue negative, expenses positive. Amount varies \$100-\$599
                -- so totals are not a single repeated value.
                CASE
                    WHEN a.account_class = 'REVENUE' THEN -(((g.i % 500) + 100) * {$fp})
                    ELSE (((g.i % 500) + 100) * {$fp})
                END
            FROM (
                SELECT
                    i,
                    -- `//`, not `/`: DuckDB's `/` is float division, so
                    -- `(i / 20) % 8` yields fractional values that CAST
                    -- unevenly and can reach 8, spilling into a ninth year.
                    -- Confirmed directly: SELECT 21/20 returns 1.05.
                    {$this->firstYear()} + CAST((i // {$accountCount}) % {$this->years()} AS INTEGER) AS yr,
                    CAST((i * 7919) % 365 AS INTEGER) AS day_offset,
                    CAST(i % {$accountCount} AS INTEGER) AS acc_idx
                FROM range(0, {$rowTarget}) AS t(i)
            ) g
            -- Accounts come from MySQL, so even the seed write is a federated
            -- join: synthetic fact rows generated in DuckDB against live
            -- relational dimension rows.
            JOIN (
                SELECT
                    account_id,
                    account_class,
                    CAST(row_number() OVER (ORDER BY account_id) - 1 AS INTEGER) AS idx
                FROM {$this->appAlias}.accounts
                WHERE account_id LIKE 'scale-%'
            ) a ON a.idx = g.acc_idx
            -- farm_id is the physical sort key inside each cohort partition;
            -- ordering the write is what keeps row-group min/max ranges narrow
            -- enough for DuckDB to skip groups on a single-farm query.
            ORDER BY 1
            SQL;

        $this->db->query($sql);
    }

    private function horizonYear(): int
    {
        return self::HORIZON_YEAR;
    }

    private function firstYear(): int
    {
        return self::FIRST_YEAR;
    }

    private function years(): int
    {
        return self::YEARS;
    }
}
