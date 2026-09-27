<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use Illuminate\Support\Facades\DB;
use Saturio\DuckDB\DuckDB;

/**
 * The pipeline oracle farm's shape at volume.
 *
 * Same accounts, same tags, same GST schedule, same overdraft — thirty years
 * of history and as many lines a month as it takes to hit the target — so
 * `duckdb:pipeline --all` can be run at 10K, 100K, 1M and 10M lines with the
 * per-stage check still on. The point is not the time; it is that the stage
 * that bends first under volume is the one with the bad plan, and that a
 * stage that *diverges* under volume is a bug the small farm could not show.
 *
 * Generated in SQL rather than PHP: a million VALUES rows through the driver
 * is minutes, `generate_series` is seconds, and the amounts are a hash of the
 * row's own coordinates so a reseed reproduces them exactly.
 *
 * The capital purchase that sends the farm into overdraft is sized from the
 * seeded data — four months of the actuals half's average net inflow — so the
 * recurrence fires at every scale rather than only at the one the number was
 * chosen for.
 */
final class PipelineScaleSeeder
{
    public const string HORIZON = PipelineOracleSeeder::HORIZON;
    public const string PERIOD_FROM = PipelineOracleSeeder::PERIOD_FROM;
    public const string PERIOD_TO = PipelineOracleSeeder::PERIOD_TO;

    private const int FIRST_YEAR = 1996;
    private const int LAST_YEAR = 2025;
    private const int FIXED_POINT = 10000;

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $alias,
    ) {
    }

    public static function farmId(int $targetLines): string
    {
        return 'pipeline-scale-'.self::label($targetLines);
    }

    public static function label(int $n): string
    {
        return $n >= 1_000_000 ? ($n / 1_000_000).'m' : ($n >= 1_000 ? ($n / 1_000).'k' : (string) $n);
    }

    /** @return int rows written */
    public function seed(int $targetLines): int
    {
        $farmId = self::farmId($targetLines);
        $months = (self::LAST_YEAR - self::FIRST_YEAR + 1) * 12;
        $perMonth = max(6, intdiv($targetLines, $months));

        $this->clear($farmId);

        DB::table('farms')->insert([
            'farm_id' => $farmId,
            'farm_type' => 'dairy',
            'region' => 'pipeline-scale',
            'opening_balance' => 0,
            'financial_year_end_month' => 6,
            'country_code' => 'NZ',
        ]);
        DB::table('gst_settings')->insert(['farm_id' => $farmId, 'sales_tax_period' => 'TWOMONTHS', 'sales_tax_basis' => 'PAYMENTS']);
        DB::table('opening_balances')->insert([
            ['farm_id' => $farmId, 'financial_year' => 2024, 'opening_bank' => 42_000 * self::FIXED_POINT, 'opening_gst' => -2_500 * self::FIXED_POINT],
            ['farm_id' => $farmId, 'financial_year' => 2025, 'opening_bank' => 50_000 * self::FIXED_POINT, 'opening_gst' => -3_000 * self::FIXED_POINT],
        ]);
        DB::table('overdrafts')->insert([
            'farm_id' => $farmId, 'rate' => 50000, 'overdraft_limit' => 100_000 * self::FIXED_POINT,
            'start_date' => self::PERIOD_FROM, 'payment_term' => 'interest_only_monthly',
        ]);

        $f = str_replace("'", "''", $farmId);
        $horizon = self::HORIZON;
        $first = self::FIRST_YEAR;
        $last = self::LAST_YEAR;
        $s = PipelineOracleSeeder::SALES;
        $w = PipelineOracleSeeder::WAGES;
        $fe = PipelineOracleSeeder::FERTILISER;
        $fi = PipelineOracleSeeder::FERTILISER_INTERNAL;
        $d = PipelineOracleSeeder::DEPRECIATION;
        $lo = PipelineOracleSeeder::LOAN;
        $g = PipelineOracleSeeder::GST;
        $b = PipelineOracleSeeder::BANK;
        $b2 = PipelineOracleSeeder::BANK_2;
        $m = PipelineOracleSeeder::MACHINERY;
        $tagEoy = PipelineOracleSeeder::TAG_EOY;
        $tagPay = PipelineOracleSeeder::TAG_GST_PAYMENT;

        // The bulk: revenue and expense lines, a hash of (month, i) for the
        // amount so the shape is noisy but reproducible. Roughly 40% revenue
        // by count and larger per line, so the farm clears cash most months.
        $this->db->query(<<<SQL
            INSERT INTO {$this->alias}.transaction_lines
                (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id, tag)
            SELECT
                '{$f}', 'dairy', 'pipeline-scale',
                'ps-' || strftime(ms, '%Y%m') || '-' || i,
                CASE (i % 10)
                    WHEN 0 THEN '{$s}' WHEN 1 THEN '{$s}' WHEN 2 THEN '{$s}' WHEN 3 THEN '{$s}'
                    WHEN 4 THEN '{$w}' WHEN 5 THEN '{$w}' WHEN 6 THEN '{$fe}' WHEN 7 THEN '{$fi}'
                    WHEN 8 THEN '{$d}' ELSE '{$lo}' END,
                CASE WHEN ms <= DATE '{$horizon}' THEN 'actuals' ELSE 'forecast' END,
                'cash',
                ms + INTERVAL ((i * 7) % 27) DAY,
                CASE WHEN (i % 10) < 4
                     THEN -(1500 + CAST(hash(strftime(ms, '%Y%m') || i) % 1200 AS BIGINT)) * {$this->fp()}
                     ELSE  (300 + CAST(hash(strftime(ms, '%Y%m') || i) % 500 AS BIGINT)) * {$this->fp()} END,
                NULL, NULL
            FROM generate_series(DATE '{$first}-01-01', DATE '{$last}-12-01', INTERVAL 1 MONTH) AS g(ms)
            CROSS JOIN generate_series(1, {$perMonth}) AS n(i)
            SQL);

        // Net GST per month, the 28th; settlements every second month tagged;
        // an EOY adjustment each 30 June; bank transfers; the opening bank
        // position the day before the report year.
        $this->db->query(<<<SQL
            INSERT INTO {$this->alias}.transaction_lines
                (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id, tag)
            SELECT '{$f}', 'dairy', 'pipeline-scale', 'ps-gst-' || strftime(ms, '%Y%m'), '{$g}',
                   CASE WHEN ms <= DATE '{$horizon}' THEN 'actuals' ELSE 'forecast' END, 'cash',
                   ms + INTERVAL 27 DAY,
                   -(200 + CAST(hash('gst' || strftime(ms, '%Y%m')) % 400 AS BIGINT)) * {$perMonth} * {$this->fp()} / 10,
                   NULL, NULL
            FROM generate_series(DATE '{$first}-01-01', DATE '{$last}-12-01', INTERVAL 1 MONTH) AS g(ms)
            UNION ALL
            SELECT '{$f}', 'dairy', 'pipeline-scale', 'ps-settle-' || strftime(ms, '%Y%m'), '{$g}',
                   'actuals', 'cash', ms + INTERVAL 27 DAY,
                   (300 + CAST(hash('pay' || strftime(ms, '%Y%m')) % 500 AS BIGINT)) * {$perMonth} * {$this->fp()} / 10,
                   NULL, '{$tagPay}'
            FROM generate_series(DATE '{$first}-01-01', DATE '{$horizon}', INTERVAL 1 MONTH) AS g(ms)
            WHERE month(ms) % 2 = 0
            UNION ALL
            SELECT '{$f}', 'dairy', 'pipeline-scale', 'ps-eoy-' || y, '{$w}',
                   CASE WHEN make_date(y, 6, 30) <= DATE '{$horizon}' THEN 'actuals' ELSE 'forecast' END, 'cash',
                   make_date(y, 6, 30), 9000 * {$this->fp()}, NULL, '{$tagEoy}'
            FROM generate_series({$first}, {$last}) AS yy(y)
            UNION ALL
            SELECT '{$f}', 'dairy', 'pipeline-scale', 'ps-xfer-out-' || y, '{$b}',
                   CASE WHEN make_date(y, 9, 3) <= DATE '{$horizon}' THEN 'actuals' ELSE 'forecast' END, 'cash',
                   make_date(y, 9, 3), -5000 * {$this->fp()}, NULL, NULL
            FROM generate_series({$first}, {$last}) AS yy(y)
            UNION ALL
            SELECT '{$f}', 'dairy', 'pipeline-scale', 'ps-xfer-in-' || y, '{$b2}',
                   CASE WHEN make_date(y, 9, 3) <= DATE '{$horizon}' THEN 'actuals' ELSE 'forecast' END, 'cash',
                   make_date(y, 9, 3), 5000 * {$this->fp()}, NULL, NULL
            FROM generate_series({$first}, {$last}) AS yy(y)
            UNION ALL
            SELECT '{$f}', 'dairy', 'pipeline-scale', 'ps-bank-open', '{$b}', 'actuals', 'cash',
                   DATE '2024-06-30', 8000 * {$this->fp()}, NULL, NULL
            SQL);

        // Size the purchase from the data: four months of the actuals half's
        // average net inflow, so the balance goes negative at any scale.
        $net = 0;
        foreach ($this->db->query(<<<SQL
            SELECT CAST(-SUM(tl.amount) / 6 AS BIGINT) AS monthly_net
            FROM {$this->alias}.transaction_lines tl
            JOIN {$this->appAlias()}.accounts a ON a.account_id = tl.account_id
            WHERE tl.farm_id = '{$f}' AND tl.basis = 'cash'
              AND tl.date BETWEEN DATE '{$this->periodFrom()}' AND DATE '{$horizon}'
              AND COALESCE(a.account_type, '') <> 'BANK'
            SQL)->rows(true) as $r) {
            $net = (int) (string) $r['monthly_net'];
        }
        $purchase = max(4 * $net, 200_000 * self::FIXED_POINT);

        $this->db->query(<<<SQL
            INSERT INTO {$this->alias}.transaction_lines
                (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id, tag)
            VALUES ('{$f}', 'dairy', 'pipeline-scale', 'ps-machinery', '{$m}', 'forecast', 'cash',
                    DATE '2025-01-20', {$purchase}, NULL, NULL)
            SQL);

        $n = 0;
        foreach ($this->db->query("SELECT count(*) AS n FROM {$this->alias}.transaction_lines WHERE farm_id = '{$f}'")->rows(true) as $r) {
            $n = (int) (string) $r['n'];
        }

        return $n;
    }

    private function clear(string $farmId): void
    {
        $this->db->query("DELETE FROM {$this->alias}.transaction_lines WHERE farm_id = '".str_replace("'", "''", $farmId)."'");
        foreach (['overdrafts', 'opening_balances', 'gst_settings', 'farms'] as $table) {
            DB::table($table)->where('farm_id', $farmId)->delete();
        }
    }

    private function fp(): int
    {
        return self::FIXED_POINT;
    }

    private function periodFrom(): string
    {
        return self::PERIOD_FROM;
    }

    private function appAlias(): string
    {
        return (string) config('duckdb.app_database.alias');
    }
}
