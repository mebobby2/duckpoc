<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\PracticeShape;
use InvalidArgumentException;
use Saturio\DuckDB\DuckDB;

/**
 * Seeds one practice's farms for the DuckDB engine, generated in DuckDB:
 * farm, chart, milk and livestock rows go to the Insights MySQL database
 * through the attached `insightsdb`, and journal lines go to the lake.
 *
 * The rules are the AlloyDB seeder's, rule for rule — hash-of-id attributes
 * from PracticeShape, one transaction per economic event with its legs on
 * both bases, actuals to the horizon and one forecast per account-month
 * after it, milk priced from production, two-monthly GST settlements up to
 * the horizon, monthly livestock movements. DuckDB's hash is not Postgres's,
 * so a practice seeded here has the same shape as an AlloyDB one, not the
 * same numbers.
 *
 * Lines are written a batch of farms at a time, each batch sorted by farm,
 * month and account, so every lake file covers a narrow farm range whose
 * statistics let a portfolio's scan skip the files of other practices.
 */
final class InsightsDuckDbPracticeSeeder
{
    public const string FIRST_MONTH = '2023-06-01';
    public const string LAST_MONTH = '2029-06-01';
    public const string OPENING_DATE = '2023-05-31';
    public const string HORIZON = PortfolioScope::HORIZON;
    public const string TAG_GST_PAYMENT = 'gst_payment';

    private const float GST_RATE = 0.15;
    private const int VALID_FROM = 1_700_000_000;
    private const int FIXED_POINT = 10_000;

    /**
     * Below DuckDB's default of 80% of RAM, leaving the container headroom.
     * The sorted, partitioned lake insert holds its whole output in memory
     * rather than spilling, so this caps a batch at roughly 30M lines; the
     * default batch of 50 farms stays well under it.
     */
    private const string MEMORY_LIMIT = '8GB';

    public function __construct(
        private readonly DuckDB $db,
        private readonly int $practiceId,
        private readonly int $firstFarmId,
        private readonly int $farmCount,
        private readonly int $farmsPerBatch = 50,
    ) {
        if ($firstFarmId < 1 || $farmCount < 1 || $firstFarmId + $farmCount > 900_000) {
            throw new InvalidArgumentException('Farm ids must fit the transaction id layout: 1 to 899,999.');
        }
    }

    private bool $writeDimensions = true;

    /**
     * @param callable(string): void $progress
     * @param ?int $resumeLinesFrom a farm id: the MySQL rows and the lines of
     *     every farm before it are already in, from a run that stopped; the
     *     generation scaffolding is rebuilt and the lines carry on from here
     * @return array<string, int>
     */
    public function seed(callable $progress, ?int $resumeLinesFrom = null): array
    {
        $this->writeDimensions = $resumeLinesFrom === null;
        $this->db->query("SET memory_limit='".self::MEMORY_LIMIT."'");
        $this->db->query('SET preserve_insertion_order=false');

        $this->db->query(<<<'SQL'
            CREATE OR REPLACE TEMP MACRO seed_uuid(s) AS
                substr(md5(s), 1, 8) || '-' || substr(md5(s), 9, 4) || '-' || substr(md5(s), 13, 4) || '-'
                || substr(md5(s), 17, 4) || '-' || substr(md5(s), 21, 12)
            SQL);

        $this->write(sprintf(
            "INSERT INTO %s (id, uuid, name, region) VALUES (%d, seed_uuid('practice-%d'), 'Practice %d', 'Waikato')",
            InsightsDuckDb::table('practices'),
            $this->practiceId,
            $this->practiceId,
            $this->practiceId,
        ));

        $counts = [];
        $this->profiles();
        if ($this->writeDimensions) {
            $counts['farms'] = $this->farms();
            $progress('farms');
        }
        $counts['accounts'] = $this->accounts();
        $progress('accounts');
        $counts['milk_trackers'] = $this->milk();
        $progress('milk');
        if ($this->writeDimensions) {
            $counts['stock_transactions'] = $this->livestock();
            $progress('livestock');
        }

        (new InsightsDuckDbSchema())->applyWriteTuning($this->db);
        $counts['transaction_lines'] = 0;
        for ($lo = $resumeLinesFrom ?? $this->firstFarmId; $lo <= $this->lastFarmId(); $lo += $this->farmsPerBatch) {
            $hi = min($lo + $this->farmsPerBatch - 1, $this->lastFarmId());
            $started = microtime(true);
            $written = $this->lines($lo, $hi);
            $counts['transaction_lines'] += $written;
            $progress(sprintf('lines for farms %d-%d: %s in %.1f s', $lo, $hi, number_format($written), microtime(true) - $started));
        }

        foreach (['seed_farm_profile', 'seed_plan', 'seed_system_accounts', 'seed_milk_trackers', 'seed_milk_productions', 'seed_milk_prices', 'seed_events'] as $table) {
            $this->db->query("DROP TABLE IF EXISTS {$table}");
        }

        return $counts;
    }

    private function profiles(): void
    {
        $typeCase = $this->bucketCase('hash(g * 7919) % 100', PracticeShape::TYPE_MIX, 0);
        $fyCase = $this->bucketCase('hash(g * 7919) % 100', PracticeShape::TYPE_MIX, 3);
        $bandCase = $this->bucketCase('hash(g * 104729) % 100', PracticeShape::SIZE_BANDS, 0);
        $txnCase = $this->bucketCase('hash(g * 104729) % 100', PracticeShape::SIZE_BANDS, 2);
        $scaleCase = $this->bucketCase('hash(g * 104729) % 100', PracticeShape::SIZE_BANDS, 3);
        $regions = $this->textList(PracticeShape::REGIONS);
        $regionCount = count(PracticeShape::REGIONS);
        $last = $this->lastFarmId() + 1;

        $this->db->query(<<<SQL
            CREATE OR REPLACE TEMP TABLE seed_farm_profile AS
            SELECT
                CAST(g AS INTEGER) AS farm_id,
                {$typeCase} AS farm_type,
                CAST({$fyCase} AS INTEGER) AS fy_end_month,
                {$bandCase} AS size_band,
                ({$scaleCase}) * (0.8 + (hash(g * 15485863) % 400) / 1000.0) AS scale,
                ({$txnCase}) / 12.0 * (0.8 + (hash(g * 32452843) % 400) / 1000.0) AS txns_per_month,
                {$regions}[CAST(1 + hash(g * 49979687) % {$regionCount} AS BIGINT)] AS region
            FROM range({$this->firstFarmId}, {$last}) t(g)
            SQL);
    }

    private function farms(): int
    {
        $vf = self::VALID_FROM;
        $names = implode(' ', array_map(
            fn (array $t): string => sprintf('WHEN %s THEN %s', $this->quote($t[0]), $this->quote(ucwords(str_replace('_', ' ', $t[0])).' farm ')),
            PracticeShape::TYPE_MIX,
        ));
        $typeNames = implode(', ', array_map(
            fn (array $t): string => sprintf('(%s, %s)', $this->quote($t[0]), $this->quote($t[2])),
            PracticeShape::TYPE_MIX,
        ));

        $this->write(<<<SQL
            INSERT INTO {$this->t('farms')} (id, _valid_from, uuid, name, practice_id, region_primary, financial_year_end_day, financial_year_end_month)
            SELECT farm_id, {$vf}, seed_uuid('farm-' || farm_id), (CASE farm_type {$names} END) || farm_id,
                   {$this->practiceId}, region, CASE fy_end_month WHEN 5 THEN 31 ELSE 30 END, fy_end_month
            FROM seed_farm_profile
            SQL);

        $this->write("INSERT INTO {$this->t('farm_practice')} (farm_id, practice_id) SELECT farm_id, {$this->practiceId} FROM seed_farm_profile");

        $this->write(<<<SQL
            INSERT INTO {$this->t('farm_types')} (uuid, name)
            SELECT seed_uuid('farm-type-' || name), name
            FROM (SELECT DISTINCT name FROM (VALUES {$typeNames}) t(farm_type, name))
            WHERE seed_uuid('farm-type-' || name) NOT IN (SELECT uuid FROM {$this->t('farm_types')})
            SQL);

        $this->write(<<<SQL
            INSERT INTO {$this->t('farms_operation_types')} (_valid_from, farm_id, farm_type_uuid)
            SELECT {$vf}, p.farm_id, seed_uuid('farm-type-' || t.name)
            FROM seed_farm_profile p
            JOIN (VALUES {$typeNames}) t(farm_type, name) ON t.farm_type = p.farm_type
            SQL);

        return $this->farmCount;
    }

    private function accounts(): int
    {
        $vf = self::VALID_FROM;

        $rows = [];
        foreach (PracticeShape::ACCOUNTS as $i => [$code, $name, $class, $type, $system, $group, $category, $types, $share, $monthly, $taxable]) {
            $rows[] = sprintf(
                '(%d, %s, %s, %s, %s, %s, %s, %s, %s, %F, %d, %s)',
                $i + 1,
                $this->quote($code),
                $this->quote($name),
                $this->quote($class),
                $this->quote($type),
                $system === null ? 'CAST(NULL AS VARCHAR)' : $this->quote($system),
                $this->quote($group),
                $this->quote($category),
                $this->textList($types),
                $share,
                $monthly,
                $taxable ? 'true' : 'false',
            );
        }
        $plan = implode(",\n", $rows);

        $this->db->query(<<<SQL
            CREATE OR REPLACE TEMP TABLE seed_plan AS
            WITH template(account_index, code, name, class, type, system_account, grp, category, farm_types, share, monthly, taxable) AS (
                VALUES {$plan}
            ),
            chosen AS (
                SELECT p.farm_id, t.*
                FROM seed_farm_profile p
                JOIN template t ON list_contains(t.farm_types, p.farm_type) OR list_contains(t.farm_types, '*')
            )
            SELECT c.farm_id, c.account_index, c.code, c.name, c.class, c.type, c.system_account, c.grp, c.category,
                   seed_uuid(c.farm_id || '-' || c.code) AS accountid,
                   c.share / SUM(c.share) OVER (PARTITION BY c.farm_id) AS share,
                   c.monthly, c.taxable
            FROM chosen c
            SQL);

        $system = [];
        foreach (PracticeShape::SYSTEM_ACCOUNTS as $key => [$code, $name, $class, $type, $sys, $group]) {
            $system[] = sprintf(
                '(%s, %s, %s, %s, %s, %s, %s)',
                $this->quote($key),
                $this->quote($code),
                $this->quote($name),
                $this->quote($class),
                $this->quote($type),
                $sys === null ? 'CAST(NULL AS VARCHAR)' : $this->quote($sys),
                $this->quote($group),
            );
        }
        $systemValues = implode(', ', $system);

        $this->db->query(<<<SQL
            CREATE OR REPLACE TEMP TABLE seed_system_accounts AS
            WITH template(role, code, name, class, type, system_account, grp) AS (VALUES {$systemValues})
            SELECT p.farm_id, t.*, seed_uuid(p.farm_id || '-' || t.code) AS accountid
            FROM seed_farm_profile p CROSS JOIN template t
            SQL);

        $this->write(<<<SQL
            INSERT INTO {$this->t('xero_accounts')} (_valid_from, farm_id, accountid, code, name, class, type, system_account)
            SELECT {$vf}, farm_id, accountid, code, name, class, type, system_account FROM seed_plan
            UNION ALL
            SELECT {$vf}, farm_id, accountid, code, name, class, type, system_account FROM seed_system_accounts
            SQL);

        $this->write(<<<SQL
            INSERT INTO {$this->t('categories')} (_valid_from, farm_id, name, "group", non_operating, "order", system_category_name)
            SELECT DISTINCT {$vf}, farm_id, category, grp, CAST(grp LIKE 'non_operating%' AS INTEGER), 0, category FROM seed_plan
            UNION
            SELECT {$vf}, farm_id, name, grp, 0, 0, name FROM seed_system_accounts
            SQL);

        $this->write(<<<SQL
            INSERT INTO {$this->t('category_xero_account')} (category_id, xero_account_id, farm_id)
            SELECT c.id, a.accountid, a.farm_id
            FROM (
                SELECT farm_id, accountid, category AS category_name FROM seed_plan
                UNION ALL
                SELECT farm_id, accountid, name FROM seed_system_accounts
            ) a
            JOIN (SELECT id, farm_id, name FROM {$this->t('categories')}
                  WHERE farm_id BETWEEN {$this->firstFarmId} AND {$this->lastFarmId()} AND milk_tracker_id IS NULL) c
              ON c.farm_id = a.farm_id AND c.name = a.category_name
            SQL);

        return $this->count("SELECT (SELECT count(*) FROM seed_plan) + (SELECT count(*) FROM seed_system_accounts)");
    }

    private function milk(): int
    {
        $vf = self::VALID_FROM;
        $large = PracticeShape::TYPE_DAIRY_LARGE;
        $largeCount = PracticeShape::MILK_TRACKERS_LARGE_HERD;
        $companies = $this->textList(PracticeShape::MILK_COMPANIES);
        $companyCount = count(PracticeShape::MILK_COMPANIES);
        $byBand = implode(' ', array_map(
            fn (string $band, int $n): string => sprintf('WHEN %s THEN %d', $this->quote($band), $n),
            array_keys(PracticeShape::MILK_TRACKERS_BY_BAND),
            PracticeShape::MILK_TRACKERS_BY_BAND,
        ));
        $curve = '['.implode(', ', PracticeShape::MILK_CURVE).']';
        $curveTotal = array_sum(PracticeShape::MILK_CURVE);
        $kgms = PracticeShape::MEDIUM_FARM_KGMS;
        $price = PracticeShape::MILK_PRICE;
        $horizon = self::HORIZON;

        $this->db->query(<<<SQL
            CREATE OR REPLACE TEMP TABLE seed_milk_trackers AS
            SELECT CAST(farm_id * 100 + k AS INTEGER) AS id, farm_id, 'Milk supply ' || k AS name,
                   {$companies}[CAST(1 + hash(CAST(farm_id AS BIGINT) * 101 + k) % {$companyCount} AS BIGINT)] AS company,
                   seed_uuid(farm_id || '-milk-' || k) AS income_accountid,
                   count(*) OVER (PARTITION BY farm_id) AS trackers
            FROM (
                SELECT farm_id, unnest(generate_series(1, CASE WHEN farm_type = '{$large}' THEN {$largeCount}
                                                                ELSE CASE size_band {$byBand} END END)) AS k
                FROM seed_farm_profile
                WHERE farm_type IN ('dairy', '{$large}')
            )
            SQL);

        $this->write(<<<SQL
            INSERT INTO {$this->t('milk_trackers')} (id, _valid_from, farm_id, name, company, income_accountid)
            SELECT id, {$vf}, farm_id, name, company, income_accountid FROM seed_milk_trackers
            SQL);

        $this->write(<<<SQL
            INSERT INTO {$this->t('xero_accounts')} (_valid_from, farm_id, accountid, code, name, class, type)
            SELECT {$vf}, farm_id, income_accountid, '200-' || (id % 100), 'Milk Sales - ' || name, 'REVENUE', 'SALES'
            FROM seed_milk_trackers
            SQL);

        $this->write(<<<SQL
            INSERT INTO {$this->t('categories')} (_valid_from, farm_id, name, "group", system_category_name, milk_tracker_id)
            SELECT {$vf}, farm_id, 'Milk Income', 'income', 'Milk Income', id FROM seed_milk_trackers
            SQL);

        $this->write(<<<SQL
            INSERT INTO {$this->t('category_xero_account')} (category_id, xero_account_id, farm_id)
            SELECT c.id, mt.income_accountid, mt.farm_id
            FROM seed_milk_trackers mt
            JOIN (SELECT id, milk_tracker_id FROM {$this->t('categories')}
                  WHERE farm_id BETWEEN {$this->firstFarmId} AND {$this->lastFarmId()} AND milk_tracker_id IS NOT NULL) c
              ON c.milk_tracker_id = mt.id
            SQL);

        $this->db->query(<<<SQL
            CREATE OR REPLACE TEMP TABLE seed_milk_productions AS
            SELECT mt.farm_id, mt.id AS milk_tracker_id, m,
                   CASE WHEN m <= DATE '{$horizon}' THEN 'actual' ELSE 'forecast' END AS type,
                   CAST(round(
                       p.scale * {$kgms} / mt.trackers
                       * {$curve}[month(m)] / {$curveTotal}
                       * (0.9 + (hash(CAST(mt.id AS BIGINT) * 31 + CAST(epoch(m) AS BIGINT)) % 200) / 1000.0)
                   ) AS INTEGER) AS production
            FROM seed_milk_trackers mt
            JOIN seed_farm_profile p ON p.farm_id = mt.farm_id
            CROSS JOIN ({$this->months()}) months
            SQL);

        $this->write(<<<SQL
            INSERT INTO {$this->t('milk_productions')} (_valid_from, farm_id, milk_tracker_id, transaction_date, type, production)
            SELECT {$vf}, farm_id, milk_tracker_id, m, type, production FROM seed_milk_productions
            SQL);

        $this->db->query(<<<SQL
            CREATE OR REPLACE TEMP TABLE seed_milk_prices AS
            SELECT mt.id AS milk_tracker_id, m AS month,
                   CAST(round({$price} * (0.95 + (hash(CAST(mt.id AS BIGINT) * 7 + year(m)) % 100) / 1000.0)) AS BIGINT) AS price
            FROM seed_milk_trackers mt
            CROSS JOIN ({$this->months()}) months
            SQL);

        $this->write("INSERT INTO {$this->t('milk_tracker_prices')} (milk_tracker_id, month, price) SELECT milk_tracker_id, month, price FROM seed_milk_prices");

        return $this->count('SELECT count(*) FROM seed_milk_trackers');
    }

    private function livestock(): int
    {
        $vf = self::VALID_FROM;
        $horizon = self::HORIZON;
        $opening = self::OPENING_DATE;
        $fp = self::FIXED_POINT;

        $mobs = [];
        $classes = [];
        $monthly = [];
        foreach (PracticeShape::LIVESTOCK as $m => [$tracker, $stockType, $types, $classDefs]) {
            $mobs[] = sprintf('(%d, %s, %s, %s)', $m + 1, $this->quote($tracker), $this->quote($stockType), $this->textList($types));
            foreach ($classDefs as [$name, $head, $openingShare, $value, $inTransition, $in, $outTransition, $out]) {
                $classes[] = sprintf('(%d, %s, %d, %F, %d)', $m + 1, $this->quote($name), $head, $openingShare, $value);
                for ($month = 1; $month <= 12; $month++) {
                    if ($in[$month - 1] > 0) {
                        $monthly[] = sprintf('(%d, %s, %d, %s, %F)', $m + 1, $this->quote($name), $month, $this->quote($inTransition), $in[$month - 1]);
                    }
                    if ($out[$month - 1] > 0) {
                        $monthly[] = sprintf('(%d, %s, %d, %s, %F)', $m + 1, $this->quote($name), $month, $this->quote($outTransition), $out[$month - 1]);
                    }
                }
            }
        }
        $mobValues = 'VALUES '.implode(', ', $mobs);
        $classValues = 'VALUES '.implode(', ', $classes);
        $monthlyValues = 'VALUES '.implode(', ', $monthly);
        $firstSeason = (int) substr(self::FIRST_MONTH, 0, 4);
        $lastSeason = (int) substr(self::LAST_MONTH, 0, 4);

        $this->write(<<<SQL
            INSERT INTO {$this->t('stock_types')} (uuid, name)
            SELECT seed_uuid('stock-type-' || t.name), t.name
            FROM ({$mobValues}) t(mob, tracker, name, farm_types)
            WHERE seed_uuid('stock-type-' || t.name) NOT IN (SELECT uuid FROM {$this->t('stock_types')})
            SQL);

        $this->write(<<<SQL
            INSERT INTO {$this->t('stock_classes')} (uuid, stock_type_uuid, name)
            SELECT seed_uuid('stock-class-' || c.name), seed_uuid('stock-type-' || t.name), c.name
            FROM ({$classValues}) c(mob, name, head, opening_share, value)
            JOIN ({$mobValues}) t(mob, tracker, name, farm_types) ON t.mob = c.mob
            WHERE seed_uuid('stock-class-' || c.name) NOT IN (SELECT uuid FROM {$this->t('stock_classes')})
            SQL);

        $this->db->query(<<<SQL
            CREATE OR REPLACE TEMP TABLE seed_trackers AS
            SELECT CAST(p.farm_id * 100 + 50 + t.mob AS INTEGER) AS id, p.farm_id, t.tracker AS name, t.mob, t.name AS stock_type, p.scale
            FROM seed_farm_profile p
            JOIN ({$mobValues}) t(mob, tracker, name, farm_types) ON list_contains(t.farm_types, p.farm_type)
            SQL);

        $this->write(<<<SQL
            INSERT INTO {$this->t('trackers')} (id, _valid_from, uuid, farm_id, name, tracker_number, stock_type_uuid)
            SELECT id, {$vf}, seed_uuid('tracker-' || farm_id || '-' || mob), farm_id, name, mob, seed_uuid('stock-type-' || stock_type)
            FROM seed_trackers
            SQL);

        $this->write(<<<SQL
            INSERT INTO {$this->t('stock_transactions')} (_valid_from, farm_id, tracker_id, stock_class_uuid, quantity, type, transition, transaction_date)
            SELECT {$vf}, tr.farm_id, tr.id, seed_uuid('stock-class-' || c.name),
                   round(c.head * tr.scale * c.opening_share), 'actual', 'opening', DATE '{$opening}'
            FROM seed_trackers tr
            JOIN ({$classValues}) c(mob, name, head, opening_share, value) ON c.mob = tr.mob
            WHERE c.opening_share > 0
            UNION ALL
            SELECT {$vf}, tr.farm_id, tr.id, seed_uuid('stock-class-' || c.name),
                   round(c.head * tr.scale * mv.share
                         * CASE WHEN mv.transition IN ('birth', 'purchase')
                                THEN 1 + (hash(CAST(tr.id AS BIGINT) * 131 + CAST(epoch(months.m) AS BIGINT) + length(c.name)) % 150) / 1000.0
                                ELSE 1 END),
                   CASE WHEN months.m <= DATE '{$horizon}' THEN 'actual' ELSE 'forecast' END,
                   mv.transition,
                   CAST(months.m + INTERVAL '14 days' AS DATE)
            FROM seed_trackers tr
            JOIN ({$classValues}) c(mob, name, head, opening_share, value) ON c.mob = tr.mob
            JOIN ({$monthlyValues}) mv(mob, name, month, transition, share) ON mv.mob = c.mob AND mv.name = c.name
            CROSS JOIN ({$this->months()}) months
            WHERE month(months.m) = mv.month
            SQL);

        $this->write(<<<SQL
            INSERT INTO {$this->t('stock_class_valuations')} (farm_id, tracker_id, stock_class_uuid, season, value_per_head)
            SELECT tr.farm_id, tr.id, seed_uuid('stock-class-' || c.name), season,
                   CAST(round(c.value * {$fp} * (0.9 + (hash(CAST(tr.id AS BIGINT) * 17 + season * 7 + length(c.name)) % 201) / 1000.0)) AS BIGINT)
            FROM seed_trackers tr
            JOIN ({$classValues}) c(mob, name, head, opening_share, value) ON c.mob = tr.mob
            CROSS JOIN range({$firstSeason}, {$lastSeason} + 1) s(season)
            SQL);

        $this->write(<<<SQL
            INSERT INTO {$this->t('xero_accounts')} (_valid_from, farm_id, accountid, code, name, class, type, "virtual")
            SELECT DISTINCT {$vf}, farm_id, seed_uuid(farm_id || '-livestock-valuation'), 'LVC', 'Livestock Valuation Change', 'REVENUE', 'REVENUE', 1
            FROM seed_trackers
            SQL);

        $this->write(<<<SQL
            INSERT INTO {$this->t('categories')} (_valid_from, farm_id, name, "group", system_category_name)
            SELECT DISTINCT {$vf}, farm_id, 'Livestock Valuation Change', 'income', 'Livestock Valuation Change'
            FROM seed_trackers
            SQL);

        $this->write(<<<SQL
            INSERT INTO {$this->t('category_xero_account')} (category_id, xero_account_id, farm_id)
            SELECT id, seed_uuid(farm_id || '-livestock-valuation'), farm_id
            FROM {$this->t('categories')}
            WHERE farm_id BETWEEN {$this->firstFarmId} AND {$this->lastFarmId()} AND name = 'Livestock Valuation Change'
            SQL);

        $movements = $this->count('SELECT count(*) FROM '.$this->t('stock_transactions')." WHERE farm_id BETWEEN {$this->firstFarmId} AND {$this->lastFarmId()}");
        $this->db->query('DROP TABLE IF EXISTS seed_trackers');

        return $movements;
    }

    /**
     * One batch of farms: its economic events, then every event's legs on
     * both bases, the opening position and the GST returns already filed,
     * written to the lake in one sorted insert.
     */
    private function lines(int $lo, int $hi): int
    {
        $horizon = self::HORIZON;
        $opening = self::OPENING_DATE;
        $gst = self::GST_RATE;
        $fp = self::FIXED_POINT;
        $tag = self::TAG_GST_PAYMENT;
        $lake = InsightsDuckDb::lines();

        $this->db->query(<<<SQL
            CREATE OR REPLACE TEMP TABLE seed_events AS
            WITH months AS (
                SELECT m, CAST(row_number() OVER (ORDER BY m) AS BIGINT) AS mi,
                       1 + 0.25 * cos(2 * pi() * (month(m) - 10) / 12) AS factor
                FROM ({$this->months()})
            ),
            actual_base AS (
                SELECT pl.farm_id, pl.accountid, pl.class, pl.taxable, pl.account_index, pl.monthly, fp.scale, se.m, se.mi, se.factor,
                       CAST(greatest(1, round(fp.txns_per_month * pl.share)) AS INTEGER) AS cnt
                FROM seed_plan pl
                JOIN seed_farm_profile fp ON fp.farm_id = pl.farm_id
                CROSS JOIN months se
                WHERE se.m <= DATE '{$horizon}' AND pl.farm_id BETWEEN {$lo} AND {$hi}
            ),
            actual_k AS (
                SELECT *, unnest(generate_series(1, cnt)) AS k FROM actual_base
            ),
            actual AS (
                SELECT farm_id, accountid, class, taxable, 'actuals' AS type,
                       CAST(farm_id AS BIGINT) * 10000000000 + mi * 100000000 + account_index * 1000000 + k AS txn_id,
                       CAST(m + CAST(hash(CAST(farm_id AS BIGINT) * 1000003 + mi * 1009 + account_index * 101 + k) % 28 AS INTEGER) AS DATE) AS accrual_date,
                       CAST(round(monthly * scale * factor / cnt
                             * (0.5 + (hash(CAST(farm_id AS BIGINT) * 7 + mi * 13 + account_index * 17 + k * 19) % 1000) / 1000.0)
                             * {$fp}) AS BIGINT) AS amount
                FROM actual_k
            ),
            forecast AS (
                SELECT pl.farm_id, pl.accountid, pl.class, pl.taxable, 'forecast' AS type,
                       CAST(pl.farm_id AS BIGINT) * 10000000000 + se.mi * 100000000 + pl.account_index * 1000000 AS txn_id,
                       CAST(se.m + 14 AS DATE) AS accrual_date,
                       CAST(round(pl.monthly * fp.scale * se.factor * {$fp}) AS BIGINT) AS amount
                FROM seed_plan pl
                JOIN seed_farm_profile fp ON fp.farm_id = pl.farm_id
                CROSS JOIN months se
                WHERE se.m > DATE '{$horizon}' AND pl.farm_id BETWEEN {$lo} AND {$hi}
            ),
            milk AS (
                SELECT mt.farm_id, mt.income_accountid AS accountid, 'REVENUE' AS class, true AS taxable, 'actuals' AS type,
                       CAST(mt.farm_id AS BIGINT) * 10000000000 + se.mi * 100000000 + (90 + mt.id % 100) * 1000000 AS txn_id,
                       CAST(se.m + INTERVAL '1 month' - INTERVAL '1 day' AS DATE) AS accrual_date,
                       CAST(mp.production AS BIGINT) * pr.price AS amount
                FROM seed_milk_trackers mt
                JOIN seed_milk_productions mp ON mp.milk_tracker_id = mt.id
                JOIN seed_milk_prices pr ON pr.milk_tracker_id = mt.id AND pr.month = mp.m
                JOIN months se ON se.m = mp.m
                WHERE mt.farm_id BETWEEN {$lo} AND {$hi}
                  AND mp.production > 0
                  AND CAST(mp.m + INTERVAL '1 month' - INTERVAL '1 day' AS DATE) <= DATE '{$horizon}'
            )
            SELECT *, CAST(accrual_date + 20 AS DATE) AS cash_date FROM (
                SELECT * FROM actual UNION ALL SELECT * FROM forecast UNION ALL SELECT * FROM milk
            )
            SQL);

        $before = $this->count("SELECT count(*) FROM {$lake} WHERE farm_id BETWEEN {$lo} AND {$hi}");

        $this->db->query(<<<SQL
            INSERT INTO {$lake} (transaction_id, farm_id, type, basis, date, month, account_id, net_amount, tax_amount, tag)
            WITH sa AS (
                SELECT farm_id,
                       max(accountid) FILTER (WHERE role = 'bank') AS bank,
                       max(accountid) FILTER (WHERE role = 'gst') AS gst,
                       max(accountid) FILTER (WHERE role = 'payables') AS payables,
                       max(accountid) FILTER (WHERE role = 'opening') AS equity
                FROM seed_system_accounts
                WHERE farm_id BETWEEN {$lo} AND {$hi}
                GROUP BY farm_id
            ),
            ev AS (
                SELECT e.*, sa.bank, sa.gst, sa.payables,
                       (CASE WHEN e.class = 'REVENUE' THEN -1 ELSE 1 END) * e.amount AS net,
                       CASE WHEN e.taxable THEN CAST(round((CASE WHEN e.class = 'REVENUE' THEN -1 ELSE 1 END) * e.amount * {$gst}) AS BIGINT)
                            ELSE CAST(0 AS BIGINT) END AS tax
                FROM seed_events e
                JOIN sa ON sa.farm_id = e.farm_id
            ),
            legs AS (
                SELECT txn_id, farm_id, type, gst, unnest([
                    {'basis': 'cash',    'date': cash_date,    'account_id': accountid, 'net_amount': net,           'tax_amount': tax},
                    {'basis': 'cash',    'date': cash_date,    'account_id': gst,       'net_amount': tax,           'tax_amount': CAST(0 AS BIGINT)},
                    {'basis': 'cash',    'date': cash_date,    'account_id': bank,      'net_amount': -(net + tax),  'tax_amount': CAST(0 AS BIGINT)},
                    {'basis': 'accrual', 'date': accrual_date, 'account_id': accountid, 'net_amount': net,           'tax_amount': tax},
                    {'basis': 'accrual', 'date': accrual_date, 'account_id': gst,       'net_amount': tax,           'tax_amount': CAST(0 AS BIGINT)},
                    {'basis': 'accrual', 'date': accrual_date, 'account_id': payables,  'net_amount': -(net + tax),  'tax_amount': CAST(0 AS BIGINT)},
                    {'basis': 'accrual', 'date': cash_date,    'account_id': payables,  'net_amount': net + tax,     'tax_amount': CAST(0 AS BIGINT)},
                    {'basis': 'accrual', 'date': cash_date,    'account_id': bank,      'net_amount': -(net + tax),  'tax_amount': CAST(0 AS BIGINT)}
                ]) AS leg
                FROM ev
            ),
            event_lines AS (
                SELECT txn_id, farm_id, type, leg.basis, leg.date, leg.account_id, leg.net_amount, leg.tax_amount, CAST(NULL AS VARCHAR) AS tag
                FROM legs
                WHERE NOT (leg.account_id = gst AND leg.net_amount = 0)
            ),
            opening_lines AS (
                SELECT CAST(p.farm_id AS BIGINT) * 10000000000 AS txn_id, p.farm_id, 'actuals' AS type, b.basis, DATE '{$opening}' AS date,
                       unnest([sa.bank, sa.equity]) AS account_id,
                       unnest([o.balance, -o.balance]) AS net_amount,
                       CAST(0 AS BIGINT) AS tax_amount, CAST(NULL AS VARCHAR) AS tag
                FROM seed_farm_profile p
                JOIN sa ON sa.farm_id = p.farm_id
                CROSS JOIN (VALUES ('cash'), ('accrual')) b(basis)
                CROSS JOIN LATERAL (
                    SELECT CAST(round(p.scale * 150000 * 10000 * ((hash(CAST(p.farm_id AS BIGINT) * 3) % 1000) / 1000.0 - 0.2)) AS BIGINT) AS balance
                ) o
            ),
            gst_monthly AS (
                SELECT farm_id, 'cash' AS basis, CAST(date_trunc('month', cash_date) AS DATE) AS ms, SUM(tax) AS net
                FROM ev WHERE type = 'actuals' GROUP BY ALL
                UNION ALL
                SELECT farm_id, 'accrual', CAST(date_trunc('month', accrual_date) AS DATE), SUM(tax)
                FROM ev WHERE type = 'actuals' GROUP BY ALL
            ),
            gst_pay AS (
                SELECT p.farm_id, months.m AS pm,
                       CASE
                           WHEN month(months.m) = 12 THEN make_date(year(months.m) + 1, 1, 15)
                           WHEN month(months.m) = 4 THEN make_date(year(months.m), 5, 7)
                           ELSE make_date(year(months.m), month(months.m), 28)
                       END AS pay_date
                FROM seed_farm_profile p
                CROSS JOIN ({$this->months("DATE '".self::FIRST_MONTH."' + INTERVAL '2 months'", "DATE '{$horizon}'")}) months
                WHERE p.farm_id BETWEEN {$lo} AND {$hi} AND month(months.m) % 2 = (p.fy_end_month + 1) % 2
            ),
            gst_returns AS (
                SELECT pay.farm_id, b.basis, pay.pay_date, pay.pm, COALESCE(SUM(mo.net), 0) AS net
                FROM gst_pay pay
                CROSS JOIN (VALUES ('cash'), ('accrual')) b(basis)
                LEFT JOIN gst_monthly mo ON mo.farm_id = pay.farm_id AND mo.basis = b.basis
                     AND mo.ms >= CAST(pay.pm - INTERVAL '2 months' AS DATE) AND mo.ms < pay.pm
                WHERE pay.pay_date <= DATE '{$horizon}'
                GROUP BY ALL
            ),
            gst_lines AS (
                SELECT CAST(r.farm_id AS BIGINT) * 10000000000 + 9000000000 + year(r.pm) * 100 + month(r.pm) AS txn_id,
                       r.farm_id, 'actuals' AS type, r.basis, r.pay_date AS date,
                       unnest([sa.gst, sa.bank]) AS account_id,
                       unnest([CAST(-r.net AS BIGINT), CAST(r.net AS BIGINT)]) AS net_amount,
                       CAST(0 AS BIGINT) AS tax_amount, '{$tag}' AS tag
                FROM gst_returns r
                JOIN sa ON sa.farm_id = r.farm_id
                WHERE r.net <> 0
            ),
            all_lines AS (
                SELECT * FROM event_lines UNION ALL SELECT * FROM opening_lines UNION ALL SELECT * FROM gst_lines
            )
            SELECT txn_id, farm_id, type, basis, date, CAST(date_trunc('month', date) AS DATE), account_id, net_amount, tax_amount, tag
            FROM all_lines
            ORDER BY farm_id, date_trunc('month', date), account_id
            SQL);

        $this->db->query('DROP TABLE IF EXISTS seed_events');

        return $this->count("SELECT count(*) FROM {$lake} WHERE farm_id BETWEEN {$lo} AND {$hi}") - $before;
    }

    /** The months of the seed window, or of a narrower range, as a one-column `m` subquery of dates. */
    private function months(?string $from = null, ?string $to = null): string
    {
        $from ??= "DATE '".self::FIRST_MONTH."'";
        $to ??= "DATE '".self::LAST_MONTH."'";

        return "SELECT CAST(generate_series AS DATE) AS m FROM generate_series(CAST({$from} AS TIMESTAMP), CAST({$to} AS TIMESTAMP), INTERVAL '1 month')";
    }

    private function write(string $sql): void
    {
        if ($this->writeDimensions) {
            $this->db->query($sql);
        }
    }

    private function t(string $table): string
    {
        return InsightsDuckDb::table($table);
    }

    private function lastFarmId(): int
    {
        return $this->firstFarmId + $this->farmCount - 1;
    }

    private function count(string $sql): int
    {
        $row = iterator_to_array($this->db->query($sql)->rows())[0] ?? [0];

        return (int) (string) $row[0];
    }

    /**
     * A CASE mapping a 0-99 bucket onto rows whose second element is a share
     * out of 100, returning each row's $column.
     *
     * @param list<array<int, mixed>> $rows
     */
    private function bucketCase(string $bucket, array $rows, int $column): string
    {
        $cases = [];
        $upTo = 0;
        foreach ($rows as $row) {
            $upTo += (int) $row[1];
            $value = is_string($row[$column]) ? $this->quote($row[$column]) : (string) $row[$column];
            $cases[] = "WHEN {$bucket} < {$upTo} THEN {$value}";
        }

        return 'CASE '.implode(' ', $cases).' END';
    }

    /**
     * @param list<string> $values
     */
    private function textList(array $values): string
    {
        return 'CAST(['.implode(', ', array_map(fn (string $v): string => $this->quote($v), $values)).'] AS VARCHAR[])';
    }

    private function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
