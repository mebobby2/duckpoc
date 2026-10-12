<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use InvalidArgumentException;
use RuntimeException;
use Saturio\DuckDB\DuckDB;

/**
 * Moves a seeded practice forward in time: the forecast agreed at the
 * horizon becomes each farm's active plan, and the months after it fill with
 * actuals that drift from that plan in known ways, so a plan-versus-actual
 * report has something real to find.
 *
 * Actuals are generated from the plan itself (each forecast event becomes as
 * many real transactions as its account had a year earlier, with the
 * seeder's noise), never from the seeder's hash scaffolding, so any practice
 * can be advanced however it was seeded. Each
 * farm gets one drift — on plan, a fertiliser price rise, a dry spell, a milk
 * price cut, a repairs blowout, or fertiliser applied a month early — and
 * `plan_drifts` records which, as the answer key.
 *
 * Two things keep the plan honest. The seeder pays June's bills after the
 * horizon as actuals only, so the forecast never planned to pay them; they
 * are copied in as forecast, the way a plan carries opening creditors. And
 * new actuals are paid on the 20th of the following month, so the month
 * straddling the new horizon is covered once: by actuals paid before it, or
 * by the forecast after it.
 */
final class InsightsDuckDbActualsAdvancer
{
    public const string FERTILISER = '420';
    public const string SUPPLEMENTARY_FEED = '425';
    public const string REPAIRS = '435';

    /** The k slot copied creditor payments take in a transaction id, clear of real transactions. */
    private const int CREDITOR_COPY = 900_000;

    /**
     * Each group's drifts, then its on-plan farms, dealt to farms in hash
     * order: any practice with five dairy and three other farms gets every
     * drift at least once.
     */
    private const array DAIRY_CYCLE = ['fertiliser_price', 'drought', 'milk_price', 'repairs', 'timing',
        'on_plan', 'on_plan', 'on_plan', 'on_plan', 'on_plan', 'on_plan', 'on_plan'];
    private const array OTHER_CYCLE = ['fertiliser_price', 'repairs', 'timing',
        'on_plan', 'on_plan', 'on_plan', 'on_plan', 'on_plan', 'on_plan', 'on_plan'];

    private const array DESCRIPTIONS = [
        'on_plan' => 'Actuals within normal noise of the plan',
        'fertiliser_price' => 'Fertiliser 45% over plan from July: a price rise',
        'drought' => 'Dry from August: milk production 15% under plan, supplementary feed 60% over',
        'milk_price' => 'Milk paid 12% under the plan price from July',
        'repairs' => 'Repairs and maintenance 120% over plan from July: machinery breakdowns',
        'timing' => "October's fertiliser applied in September: spend brought forward, season unchanged",
    ];

    private readonly string $lake;

    public function __construct(
        private readonly DuckDB $db,
        private readonly int $practiceId,
        private readonly string $planHorizon = PortfolioScope::HORIZON,
        private readonly string $actualsTo = '2026-09-30',
        private readonly int $farmsPerBatch = 50,
    ) {
        foreach ([$planHorizon, $actualsTo] as $date) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new InvalidArgumentException("Expected a Y-m-d date, got '{$date}'.");
            }
        }
        if ($actualsTo <= $planHorizon) {
            throw new InvalidArgumentException('Actuals must advance past the plan horizon.');
        }
        $this->lake = InsightsDuckDb::lines();
    }

    /**
     * @param callable(string): void $progress
     * @return array<string, int>
     */
    public function advance(callable $progress): array
    {
        (new InsightsDuckDbSchema())->createPlanTables();
        $farmIds = $this->practiceFarms();
        $this->refuseIfAdvanced($farmIds);

        $this->drifts($farmIds);
        $progress('drifts dealt');

        (new InsightsDuckDbSchema())->applyWriteTuning($this->db);
        $counts = ['creditor_copies' => 0, 'actual_lines' => 0, 'gst_return_lines' => 0, 'milk_productions' => 0, 'stock_transactions' => 0];
        foreach (array_chunk($farmIds, $this->farmsPerBatch) as $batch) {
            $started = microtime(true);
            $counts['creditor_copies'] += $this->copyCreditorPayments($batch);
            $this->production($batch);
            $this->events($batch);
            $counts['actual_lines'] += $this->eventLines($batch);
            $counts['gst_return_lines'] += $this->gstReturns($batch);
            $counts['milk_productions'] += $this->writeProduction();
            $counts['stock_transactions'] += $this->stock($batch);
            $progress(sprintf('farms %d-%d in %.1f s', min($batch), max($batch), microtime(true) - $started));
        }

        $this->activePlans();
        foreach (['adv_drift', 'adv_production', 'adv_events'] as $table) {
            $this->db->query("DROP TABLE IF EXISTS {$table}");
        }

        return $counts;
    }

    /**
     * @return non-empty-list<int>
     */
    private function practiceFarms(): array
    {
        $ids = array_map(
            static fn (object $r): int => (int) $r->farm_id,
            InsightsDuckDb::mysql()->select('SELECT farm_id FROM farm_practice WHERE practice_id = ? ORDER BY farm_id', [$this->practiceId]),
        );

        return $ids === [] ? throw new InvalidArgumentException("Practice {$this->practiceId} has no farms.") : $ids;
    }

    /**
     * The creditor copies are written first and the active plans last, so a
     * run that stopped part way is caught as well as a finished one.
     *
     * @param list<int> $farmIds
     */
    private function refuseIfAdvanced(array $farmIds): void
    {
        $plans = InsightsDuckDb::mysql()->selectOne(
            'SELECT count(*) AS n FROM active_plans WHERE farm_id IN ('.implode(',', $farmIds).')',
        )->n;
        $copy = self::CREDITOR_COPY;
        $copies = $this->count(<<<SQL
            SELECT count(*) FROM {$this->lake} tl
            WHERE {$this->farms('tl.farm_id', $farmIds)} AND tl.type = 'forecast'
              AND tl.date > DATE '{$this->planHorizon}' AND tl.transaction_id % 1000000 >= {$copy}
            SQL);

        if ((int) $plans > 0 || $copies > 0) {
            throw new RuntimeException("Practice {$this->practiceId} has already been advanced, or partly; reseed it to advance again.");
        }
    }

    /**
     * @param list<int> $farmIds
     */
    private function drifts(array $farmIds): void
    {
        $dairy = $this->textList(self::DAIRY_CYCLE);
        $other = $this->textList(self::OTHER_CYCLE);
        $dairyCount = count(self::DAIRY_CYCLE);
        $otherCount = count(self::OTHER_CYCLE);

        $this->db->query(<<<SQL
            CREATE OR REPLACE TEMP TABLE adv_drift AS
            WITH farm AS (
                SELECT f.id AS farm_id, CAST(f.financial_year_end_month AS INTEGER) AS fy_month, d.farm_id IS NOT NULL AS dairy
                FROM {$this->t('farms')} f
                LEFT JOIN (
                    SELECT DISTINCT farm_id FROM {$this->t('milk_trackers')}
                    WHERE {$this->farms('farm_id', $farmIds)} AND _valid_to IS NULL
                ) d ON d.farm_id = f.id
                WHERE {$this->farms('f.id', $farmIds)} AND f._valid_to IS NULL
            ),
            ranked AS (
                SELECT *, row_number() OVER (PARTITION BY dairy ORDER BY hash(farm_id * 7727)) - 1 AS pos
                FROM farm
            )
            SELECT farm_id, fy_month, dairy,
                   CASE WHEN dairy THEN {$dairy}[pos % {$dairyCount} + 1] ELSE {$other}[pos % {$otherCount} + 1] END AS kind
            FROM ranked
            SQL);
    }

    /**
     * June's bills paid after the horizon, as the plan should have had them:
     * every leg of each such payment, as forecast. Milk cheques are left
     * out; the plan already pays them as its milk virtual journal.
     *
     * @param list<int> $batch
     */
    private function copyCreditorPayments(array $batch): int
    {
        $copy = self::CREDITOR_COPY;
        $before = $this->forecastCount($batch);

        $this->db->query(<<<SQL
            INSERT INTO {$this->lake} (transaction_id, farm_id, type, basis, date, month, account_id, net_amount, tax_amount, tag)
            SELECT tl.transaction_id + {$copy}, tl.farm_id, 'forecast', tl.basis, tl.date, tl.month,
                   tl.account_id, tl.net_amount, tl.tax_amount, tl.tag
            FROM {$this->lake} tl
            WHERE {$this->farms('tl.farm_id', $batch)} AND tl.basis = 'cash' AND tl.type = 'actuals'
              AND tl.date > DATE '{$this->planHorizon}' AND tl.tag IS NULL
              AND tl.transaction_id NOT IN (
                  SELECT m.transaction_id FROM {$this->lake} m
                  WHERE {$this->farms('m.farm_id', $batch)} AND m.basis = 'cash' AND m.type = 'actuals'
                    AND m.date > DATE '{$this->planHorizon}'
                    AND m.account_id IN (SELECT income_accountid FROM {$this->t('milk_trackers')} WHERE {$this->farms('farm_id', $batch)})
              )
            ORDER BY tl.farm_id, tl.month, tl.account_id
            SQL);

        return $this->forecastCount($batch) - $before;
    }

    /**
     * Each forecast production month becomes an actual one, with noise, and
     * a dry spell's shortfall from its second month.
     *
     * @param list<int> $batch
     */
    private function production(array $batch): void
    {
        $this->db->query(<<<SQL
            CREATE OR REPLACE TEMP TABLE adv_production AS
            SELECT mp.farm_id, mp.milk_tracker_id, mp.transaction_date AS m,
                   CAST(round(mp.production
                       * (0.95 + (hash(CAST(mp.milk_tracker_id AS BIGINT) * 131 + CAST(epoch(mp.transaction_date) AS BIGINT)) % 101) / 1000.0)
                       * CASE WHEN d.kind = 'drought' AND mp.transaction_date >= {$this->droughtFrom()} THEN 0.85 ELSE 1 END
                   ) AS INTEGER) AS production
            FROM {$this->t('milk_productions')} mp
            JOIN adv_drift d ON d.farm_id = mp.farm_id
            WHERE {$this->farms('mp.farm_id', $batch)} AND mp._valid_to IS NULL AND mp.budget_id = 0 AND mp.type = 'forecast'
              AND mp.transaction_date > DATE '{$this->planHorizon}' AND mp.transaction_date <= DATE '{$this->actualsTo}'
            SQL);
    }

    /**
     * The economic events between the horizons: each forecast event split
     * into as many transactions as the account had in the same month last
     * year, each with the seeder's noise, so the new months vary as much as
     * the farm's history does, then scaled by the farm's drift; October's
     * fertiliser a month early on the timing farms; and the milk cheques for
     * the actual production.
     *
     * @param list<int> $batch
     */
    private function events(array $batch): void
    {
        $gst = InsightsDuckDbPracticeSeeder::GST_RATE;
        $firstMonth = InsightsDuckDbPracticeSeeder::FIRST_MONTH;
        $fertiliser = self::FERTILISER;
        $feed = self::SUPPLEMENTARY_FEED;
        $repairs = self::REPAIRS;

        $this->db->query(<<<SQL
            CREATE OR REPLACE TEMP TABLE adv_events AS
            WITH account AS (
                SELECT farm_id, accountid, code FROM {$this->t('xero_accounts')}
                WHERE {$this->farms('farm_id', $batch)} AND _valid_to IS NULL AND system_account IS NULL AND type <> 'BANK'
            ),
            planned AS (
                SELECT tl.transaction_id, tl.farm_id, tl.account_id, a.code, tl.date, tl.net_amount AS net, tl.tax_amount AS tax,
                       CAST(date_trunc('month', tl.date) AS DATE) AS m, d.kind
                FROM {$this->lake} tl
                JOIN account a ON a.farm_id = tl.farm_id AND a.accountid = tl.account_id
                JOIN adv_drift d ON d.farm_id = tl.farm_id
                WHERE {$this->farms('tl.farm_id', $batch)} AND tl.basis = 'accrual' AND tl.type = 'forecast'
                  AND tl.date > DATE '{$this->planHorizon}' AND tl.date <= DATE '{$this->actualsTo}' + INTERVAL '1 month'
            ),
            last_year AS (
                SELECT tl.farm_id, tl.account_id, CAST(date_trunc('month', tl.date) AS DATE) AS m,
                       count(DISTINCT tl.transaction_id) AS transactions
                FROM {$this->lake} tl
                JOIN account a ON a.farm_id = tl.farm_id AND a.accountid = tl.account_id
                WHERE {$this->farms('tl.farm_id', $batch)} AND tl.basis = 'accrual' AND tl.type = 'actuals'
                  AND tl.date > DATE '{$this->planHorizon}' - INTERVAL '1 year'
                  AND tl.date <= DATE '{$this->actualsTo}' - INTERVAL '1 year'
                GROUP BY 1, 2, 3
            ),
            months_events AS (
                SELECT p.*, false AS early, CAST(p.m - INTERVAL '1 year' AS DATE) AS like_month FROM planned p
                WHERE p.date <= DATE '{$this->actualsTo}'
                UNION ALL
                SELECT p.*, true, CAST(p.m - INTERVAL '1 month' - INTERVAL '1 year' AS DATE) FROM planned p
                WHERE p.kind = 'timing' AND p.code = '{$fertiliser}' AND p.date > DATE '{$this->actualsTo}'
            ),
            pieces AS (
                SELECT e.*, COALESCE(ly.transactions, 1) AS cnt, unnest(generate_series(1, COALESCE(ly.transactions, 1))) AS k
                FROM months_events e
                LEFT JOIN last_year ly ON ly.farm_id = e.farm_id AND ly.account_id = e.account_id AND ly.m = e.like_month
            ),
            scaled AS (
                SELECT *,
                       CAST(CASE
                           WHEN early THEN 1.0
                           WHEN kind = 'fertiliser_price' AND code = '{$fertiliser}' THEN 1.45
                           WHEN kind = 'drought' AND code = '{$feed}' AND m >= {$this->droughtFrom()} THEN 1.6
                           WHEN kind = 'repairs' AND code = '{$repairs}' THEN 2.2
                           ELSE 1.0
                       END AS DOUBLE) / cnt * (0.5 + (hash(transaction_id * 17 + k) % 1000) / 1000.0) AS factor
                FROM pieces
            ),
            account_events AS (
                SELECT CASE WHEN early THEN transaction_id - 100000000 + 500000 + k ELSE transaction_id + k END AS txn_id,
                       farm_id, account_id,
                       CAST(CASE WHEN early THEN m - INTERVAL '1 month' ELSE m END
                            + CAST(hash(transaction_id * 31 + k) % 28 AS INTEGER) * INTERVAL '1 day' AS DATE) AS accrual_date,
                       CAST(round(net * factor) AS BIGINT) AS net, CAST(round(tax * factor) AS BIGINT) AS tax
                FROM scaled
            ),
            milk_events AS (
                SELECT CAST(p.farm_id AS BIGINT) * 10000000000
                           + (datediff('month', DATE '{$firstMonth}', p.m) + 1) * 100000000
                           + (90 + p.milk_tracker_id % 100) * 1000000 AS txn_id,
                       p.farm_id, mt.income_accountid AS account_id,
                       CAST(p.m + INTERVAL '1 month' - INTERVAL '1 day' AS DATE) AS accrual_date,
                       CAST(round(p.production * pr.price * CASE WHEN d.kind = 'milk_price' THEN 0.88 ELSE 1 END) AS BIGINT) AS amount
                FROM adv_production p
                JOIN adv_drift d ON d.farm_id = p.farm_id
                JOIN {$this->t('milk_trackers')} mt ON mt.id = p.milk_tracker_id AND mt._valid_to IS NULL
                JOIN {$this->t('milk_tracker_prices')} pr ON pr.milk_tracker_id = p.milk_tracker_id AND pr.month = p.m
                WHERE {$this->farms('mt.farm_id', $batch)} AND p.production > 0
            )
            SELECT txn_id, farm_id, account_id, accrual_date, {$this->twentiethOfNextMonth('accrual_date')} AS cash_date, net, tax
            FROM account_events
            UNION ALL
            SELECT txn_id, farm_id, account_id, accrual_date, CAST(accrual_date + 20 AS DATE),
                   -amount, CAST(round(-amount * {$gst}) AS BIGINT)
            FROM milk_events
            SQL);
    }

    /**
     * Every event's legs on both bases, the seeder's way: on cash the
     * account, GST and bank on the day it is paid; on accrual the account,
     * GST and payable on the day it is incurred, and the payable cleared from
     * the bank when paid.
     *
     * @param list<int> $batch
     */
    private function eventLines(array $batch): int
    {
        $before = $this->actualCount($batch);

        $this->db->query(<<<SQL
            INSERT INTO {$this->lake} (transaction_id, farm_id, type, basis, date, month, account_id, net_amount, tax_amount, tag)
            WITH sa AS ({$this->systemAccounts($batch)}),
            legs AS (
                SELECT e.txn_id, e.farm_id, sa.gst, unnest([
                    {'basis': 'cash',    'date': e.cash_date,    'account_id': e.account_id, 'net_amount': e.net,             'tax_amount': e.tax},
                    {'basis': 'cash',    'date': e.cash_date,    'account_id': sa.gst,       'net_amount': e.tax,             'tax_amount': CAST(0 AS BIGINT)},
                    {'basis': 'cash',    'date': e.cash_date,    'account_id': sa.bank,      'net_amount': -(e.net + e.tax),  'tax_amount': CAST(0 AS BIGINT)},
                    {'basis': 'accrual', 'date': e.accrual_date, 'account_id': e.account_id, 'net_amount': e.net,             'tax_amount': e.tax},
                    {'basis': 'accrual', 'date': e.accrual_date, 'account_id': sa.gst,       'net_amount': e.tax,             'tax_amount': CAST(0 AS BIGINT)},
                    {'basis': 'accrual', 'date': e.accrual_date, 'account_id': sa.payables,  'net_amount': -(e.net + e.tax),  'tax_amount': CAST(0 AS BIGINT)},
                    {'basis': 'accrual', 'date': e.cash_date,    'account_id': sa.payables,  'net_amount': e.net + e.tax,     'tax_amount': CAST(0 AS BIGINT)},
                    {'basis': 'accrual', 'date': e.cash_date,    'account_id': sa.bank,      'net_amount': -(e.net + e.tax),  'tax_amount': CAST(0 AS BIGINT)}
                ]) AS leg
                FROM adv_events e
                JOIN sa ON sa.farm_id = e.farm_id
            )
            SELECT txn_id, farm_id, 'actuals', leg.basis, leg.date, CAST(date_trunc('month', leg.date) AS DATE),
                   leg.account_id, leg.net_amount, leg.tax_amount, CAST(NULL AS VARCHAR)
            FROM legs
            WHERE NOT (leg.account_id = gst AND leg.net_amount = 0)
            ORDER BY farm_id, date_trunc('month', leg.date), leg.account_id
            SQL);

        return $this->actualCount($batch) - $before;
    }

    /**
     * The two-monthly GST returns falling due between the horizons, filed
     * from the actual GST each window collected, on the seeder's calendar.
     *
     * @param list<int> $batch
     */
    private function gstReturns(array $batch): int
    {
        $tag = InsightsDuckDbPracticeSeeder::TAG_GST_PAYMENT;
        $before = $this->actualCount($batch);

        $this->db->query(<<<SQL
            INSERT INTO {$this->lake} (transaction_id, farm_id, type, basis, date, month, account_id, net_amount, tax_amount, tag)
            WITH sa AS ({$this->systemAccounts($batch)}),
            pay AS (
                SELECT d.farm_id, CAST(g.m AS DATE) AS pm,
                       CASE
                           WHEN month(g.m) = 12 THEN make_date(year(g.m) + 1, 1, 15)
                           WHEN month(g.m) = 4 THEN make_date(year(g.m), 5, 7)
                           ELSE make_date(year(g.m), month(g.m), 28)
                       END AS pay_date
                FROM adv_drift d
                CROSS JOIN generate_series(CAST(date_trunc('month', DATE '{$this->planHorizon}') AS TIMESTAMP),
                                           CAST(DATE '{$this->actualsTo}' AS TIMESTAMP), INTERVAL '1 month') AS g(m)
                WHERE {$this->farms('d.farm_id', $batch)} AND month(g.m) % 2 = (d.fy_month + 1) % 2
            ),
            collected AS (
                SELECT tl.farm_id, tl.basis, tl.month AS ms, SUM(tl.net_amount) AS net
                FROM {$this->lake} tl
                JOIN sa ON sa.farm_id = tl.farm_id AND tl.account_id = sa.gst
                WHERE {$this->farms('tl.farm_id', $batch)} AND tl.type = 'actuals' AND tl.tag IS NULL
                  AND tl.date >= DATE '{$this->planHorizon}' - INTERVAL '3 months' AND tl.date <= DATE '{$this->actualsTo}'
                GROUP BY ALL
            ),
            returns AS (
                SELECT p.farm_id, b.basis, p.pay_date, p.pm, COALESCE(SUM(c.net), 0) AS net
                FROM pay p
                CROSS JOIN (VALUES ('cash'), ('accrual')) b(basis)
                LEFT JOIN collected c ON c.farm_id = p.farm_id AND c.basis = b.basis
                     AND c.ms >= CAST(p.pm - INTERVAL '2 months' AS DATE) AND c.ms < p.pm
                WHERE p.pay_date > DATE '{$this->planHorizon}' AND p.pay_date <= DATE '{$this->actualsTo}'
                GROUP BY ALL
            )
            SELECT * FROM (
                SELECT CAST(r.farm_id AS BIGINT) * 10000000000 + 9000000000 + year(r.pm) * 100 + month(r.pm) AS txn_id,
                       r.farm_id, 'actuals' AS type, r.basis, r.pay_date AS date, CAST(date_trunc('month', r.pay_date) AS DATE) AS month,
                       unnest([sa.gst, sa.bank]) AS account_id,
                       unnest([CAST(-r.net AS BIGINT), CAST(r.net AS BIGINT)]) AS net_amount,
                       CAST(0 AS BIGINT) AS tax_amount, '{$tag}' AS tag
                FROM returns r
                JOIN sa ON sa.farm_id = r.farm_id
                WHERE r.net <> 0
            )
            ORDER BY farm_id, month, account_id
            SQL);

        return $this->actualCount($batch) - $before;
    }

    private function writeProduction(): int
    {
        $this->db->query(<<<SQL
            INSERT INTO {$this->t('milk_productions')} (_valid_from, farm_id, milk_tracker_id, transaction_date, type, production)
            SELECT {$this->validFrom()}, farm_id, milk_tracker_id, m, 'actual', production FROM adv_production
            SQL);

        return $this->count('SELECT count(*) FROM adv_production');
    }

    /**
     * Livestock moves as planned, births give or take 5%.
     *
     * @param list<int> $batch
     */
    private function stock(array $batch): int
    {
        $this->db->query(<<<SQL
            INSERT INTO {$this->t('stock_transactions')} (_valid_from, farm_id, tracker_id, stock_class_uuid, quantity, type, transition, transaction_date)
            SELECT {$this->validFrom()}, st.farm_id, st.tracker_id, st.stock_class_uuid,
                   round(st.quantity * CASE WHEN st.transition IN ('birth', 'purchase')
                       THEN 0.95 + (hash(st.tracker_id, st.transaction_date, st.stock_class_uuid) % 101) / 1000.0
                       ELSE 1 END),
                   'actual', st.transition, st.transaction_date
            FROM {$this->t('stock_transactions')} st
            WHERE {$this->farms('st.farm_id', $batch)} AND st._valid_to IS NULL AND st.budget_id = 0 AND st.type = 'forecast'
              AND st.transaction_date > DATE '{$this->planHorizon}' AND st.transaction_date <= DATE '{$this->actualsTo}'
            SQL);

        return $this->count(<<<SQL
            SELECT count(*) FROM {$this->t('stock_transactions')}
            WHERE {$this->farms('farm_id', $batch)} AND type = 'actual' AND transaction_date > DATE '{$this->planHorizon}'
            SQL);
    }

    private function activePlans(): void
    {
        $season = PortfolioScope::CURRENT_SEASON;
        $name = sprintf('FY%02d plan', $season % 100);
        $descriptions = implode(' ', array_map(
            fn (string $kind, string $text): string => sprintf('WHEN %s THEN %s', $this->quote($kind), $this->quote($text)),
            array_keys(self::DESCRIPTIONS),
            self::DESCRIPTIONS,
        ));

        $this->db->query(<<<SQL
            INSERT INTO {$this->t('plan_drifts')} (farm_id, kind, description)
            SELECT farm_id, kind, CASE kind {$descriptions} END FROM adv_drift
            SQL);

        $this->db->query(<<<SQL
            INSERT INTO {$this->t('active_plans')} (farm_id, name, season, plan_horizon, actuals_to)
            SELECT farm_id, '{$name}', {$season}, DATE '{$this->planHorizon}', DATE '{$this->actualsTo}' FROM adv_drift
            SQL);
    }

    /**
     * @param list<int> $batch
     */
    private function systemAccounts(array $batch): string
    {
        return <<<SQL
            SELECT farm_id,
                   max(accountid) FILTER (WHERE type = 'BANK') AS bank,
                   max(accountid) FILTER (WHERE system_account = 'GST') AS gst,
                   max(accountid) FILTER (WHERE system_account = 'CREDITORS') AS payables
            FROM {$this->t('xero_accounts')}
            WHERE {$this->farms('farm_id', $batch)} AND _valid_to IS NULL
            GROUP BY farm_id
            SQL;
    }

    private function droughtFrom(): string
    {
        return "CAST(date_trunc('month', DATE '{$this->planHorizon}') + INTERVAL '2 months' AS DATE)";
    }

    private function twentiethOfNextMonth(string $date): string
    {
        return "CAST(date_trunc('month', {$date}) + INTERVAL '1 month' + INTERVAL '19 days' AS DATE)";
    }

    /** When the new rows were recorded: the day after the actuals they bring in. */
    private function validFrom(): string
    {
        return "CAST(epoch(DATE '{$this->actualsTo}' + INTERVAL '1 day') AS BIGINT)";
    }

    /**
     * @param list<int> $farmIds
     */
    private function farms(string $column, array $farmIds): string
    {
        return sprintf('%s BETWEEN %d AND %d AND %s IN (%s)', $column, min($farmIds), max($farmIds), $column, implode(', ', $farmIds));
    }

    /**
     * @param list<int> $batch
     */
    private function actualCount(array $batch): int
    {
        return $this->count("SELECT count(*) FROM {$this->lake} WHERE {$this->farms('farm_id', $batch)} AND type = 'actuals' AND date > DATE '{$this->planHorizon}'");
    }

    /**
     * @param list<int> $batch
     */
    private function forecastCount(array $batch): int
    {
        return $this->count("SELECT count(*) FROM {$this->lake} WHERE {$this->farms('farm_id', $batch)} AND type = 'forecast'");
    }

    private function t(string $table): string
    {
        return InsightsDuckDb::table($table);
    }

    private function count(string $sql): int
    {
        $row = iterator_to_array($this->db->query($sql)->rows())[0] ?? [0];

        return (int) (string) $row[0];
    }

    /**
     * @param list<string> $values
     */
    private function textList(array $values): string
    {
        return 'CAST(['.implode(', ', array_map($this->quote(...), $values)).'] AS VARCHAR[])';
    }

    private function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
