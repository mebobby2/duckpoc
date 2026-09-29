<?php

declare(strict_types=1);

namespace App\Services\Insights;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Seeds one practice's farms into the `insights` schema, entirely in SQL.
 *
 * Every attribute a farm has — type, size band, region, scale, how many milk
 * supplies — is a hash of its id, so a reseed reproduces the practice exactly
 * and a farm's numbers can be recomputed from its id alone. The mix and sizes
 * come from PracticeShape.
 *
 * Journals are written the way Figured stores them: one transaction per
 * economic event, carrying its lines on both bases. On cash basis an invoice
 * is its account, GST and bank legs on the payment date; on accrual basis it
 * is the account, GST and payable legs on the invoice date plus the payment's
 * payable and bank legs. That is the chaff a real farm carries — roughly three
 * lines in four are not report lines — and it is what the portfolio statement
 * has to read past.
 *
 * Actuals run to the horizon, forecast one transaction per account per month
 * after it. Milk income before the horizon is a real journal priced from
 * production; after it there is only production, and the statement's milk
 * virtual journal prices it, as Figured's does. Two-monthly GST settlements
 * are real journals before the horizon and predicted by the statement after.
 *
 * Helper tables prefixed `seed_` are generation scaffolding, not part of the
 * production shape, and are dropped at the end.
 */
final class InsightsPracticeSeeder
{
    public const string FIRST_MONTH = '2023-06-01';
    public const string LAST_MONTH = '2029-06-01';
    public const string OPENING_DATE = '2023-05-31';
    public const string HORIZON = '2026-06-30';

    public const string TAG_GST_PAYMENT = 'gst_payment';

    private const float GST_RATE = 0.15;

    /** One unix timestamp for every `_valid_from`, so seeded rows are all current. */
    private const int VALID_FROM = 1_700_000_000;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly int $practiceId,
        private readonly int $firstFarmId,
        private readonly int $farmCount,
    ) {
        if ($firstFarmId < 1 || $farmCount < 1 || $firstFarmId + $farmCount > 900_000) {
            throw new InvalidArgumentException('Farm ids must fit the transaction id layout: 1 to 899,999.');
        }
    }

    /**
     * @return array<string, int>
     */
    public function seed(): array
    {
        $s = InsightsSchema::SCHEMA;
        $counts = [];

        $this->db->statement(sprintf(
            "INSERT INTO {$s}.practices (id, uuid, name, region) VALUES (%d, md5('practice-%d')::uuid, 'Practice %d', 'Waikato')",
            $this->practiceId,
            $this->practiceId,
            $this->practiceId,
        ));

        $this->profiles();
        $counts['farms'] = $this->farms();
        $counts['accounts'] = $this->accounts();
        $counts['milk_trackers'] = $this->milk();
        $counts['transactions'] = $this->events();
        $counts['transaction_lines'] = $this->lines();
        $counts['gst_settlements'] = $this->gstSettlements();

        foreach (['seed_events', 'seed_plan', 'seed_system_accounts', 'seed_farm_profile'] as $table) {
            $this->db->statement("DROP TABLE IF EXISTS {$s}.{$table}");
        }

        return $counts;
    }

    /**
     * Type, size band and scale for every farm, as a hash of its id. Two
     * independent hashes, so a farm's type says nothing about its size.
     */
    private function profiles(): void
    {
        $s = InsightsSchema::SCHEMA;
        $last = $this->firstFarmId + $this->farmCount - 1;

        $typeCase = $this->bucketCase('abs(hashint8(g::bigint * 7919)) % 100', PracticeShape::TYPE_MIX, 0);
        $fyCase = $this->bucketCase('abs(hashint8(g::bigint * 7919)) % 100', PracticeShape::TYPE_MIX, 3);
        $bandCase = $this->bucketCase('abs(hashint8(g::bigint * 104729)) % 100', PracticeShape::SIZE_BANDS, 0);
        $txnCase = $this->bucketCase('abs(hashint8(g::bigint * 104729)) % 100', PracticeShape::SIZE_BANDS, 2);
        $scaleCase = $this->bucketCase('abs(hashint8(g::bigint * 104729)) % 100', PracticeShape::SIZE_BANDS, 3);
        $regions = $this->textArray(PracticeShape::REGIONS);
        $regionCount = count(PracticeShape::REGIONS);

        $this->db->statement("DROP TABLE IF EXISTS {$s}.seed_farm_profile");
        $this->db->statement(<<<SQL
            CREATE UNLOGGED TABLE {$s}.seed_farm_profile AS
            SELECT
                g AS farm_id,
                {$typeCase} AS farm_type,
                ({$fyCase})::smallint AS fy_end_month,
                {$bandCase} AS size_band,
                -- +/-20% around the band, so no two farms are the same size.
                ({$scaleCase}) * (0.8 + (abs(hashint8(g::bigint * 15485863)) % 400) / 1000.0) AS scale,
                ({$txnCase}) / 12.0 * (0.8 + (abs(hashint8(g::bigint * 32452843)) % 400) / 1000.0) AS txns_per_month,
                ({$regions})[1 + abs(hashint8(g::bigint * 49979687)) % {$regionCount}] AS region
            FROM generate_series({$this->firstFarmId}, {$last}) g
            SQL);
    }

    private function farms(): int
    {
        $s = InsightsSchema::SCHEMA;
        $vf = self::VALID_FROM;

        $this->db->statement(<<<SQL
            INSERT INTO {$s}.farms (id, _valid_from, uuid, name, practice_id, region_primary,
                                    financial_year_end_day, financial_year_end_month)
            SELECT farm_id, {$vf}, md5('farm-' || farm_id)::uuid,
                   initcap(replace(farm_type, '_', ' ')) || ' farm ' || farm_id,
                   {$this->practiceId}, region,
                   CASE fy_end_month WHEN 5 THEN 31 ELSE 30 END, fy_end_month
            FROM {$s}.seed_farm_profile
            SQL);

        $this->db->statement(<<<SQL
            INSERT INTO {$s}.farm_practice (farm_id, practice_id)
            SELECT farm_id, {$this->practiceId} FROM {$s}.seed_farm_profile
            SQL);

        $this->db->statement(<<<SQL
            INSERT INTO {$s}.farm_types (uuid, name)
            SELECT DISTINCT md5('farm-type-' || name)::uuid, name
            FROM (VALUES ('Dairy'), ('Sheep & Beef'), ('Arable')) t(name)
            ON CONFLICT DO NOTHING
            SQL);

        $typeNames = implode(', ', array_map(
            fn (array $t): string => sprintf("(%s, %s)", $this->quote($t[0]), $this->quote($t[2])),
            PracticeShape::TYPE_MIX,
        ));

        $this->db->statement(<<<SQL
            INSERT INTO {$s}.farms_operation_types (_valid_from, farm_id, farm_type_uuid)
            SELECT {$vf}, p.farm_id, md5('farm-type-' || t.name)::uuid
            FROM {$s}.seed_farm_profile p
            JOIN (VALUES {$typeNames}) t(farm_type, name) ON t.farm_type = p.farm_type
            SQL);

        return $this->farmCount;
    }

    /**
     * Each farm's chart: its type's plan accounts plus the system accounts the
     * double entry writes to, every one mapped to exactly one category.
     */
    private function accounts(): int
    {
        $s = InsightsSchema::SCHEMA;
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
                $system === null ? 'NULL' : $this->quote($system),
                $this->quote($group),
                $this->quote($category),
                $this->textArray($types),
                $share,
                $monthly,
                $taxable ? 'true' : 'false',
            );
        }
        $plan = implode(",\n", $rows);

        $this->db->statement("DROP TABLE IF EXISTS {$s}.seed_plan");
        $this->db->statement(<<<SQL
            CREATE UNLOGGED TABLE {$s}.seed_plan AS
            WITH template(account_index, code, name, class, type, system_account, grp, category, farm_types, share, monthly, taxable) AS (
                VALUES {$plan}
            ),
            chosen AS (
                SELECT p.farm_id, t.*
                FROM {$s}.seed_farm_profile p
                JOIN template t ON p.farm_type = ANY (t.farm_types) OR '*' = ANY (t.farm_types)
            )
            SELECT
                c.farm_id, c.account_index, c.code, c.name, c.class, c.type, c.system_account, c.grp, c.category,
                md5(c.farm_id || '-' || c.code)::uuid::text AS accountid,
                -- Normalised per farm, so a type with fewer accounts still
                -- writes its band's number of transactions.
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
                $sys === null ? 'NULL' : $this->quote($sys),
                $this->quote($group),
            );
        }
        $systemValues = implode(', ', $system);

        $this->db->statement("DROP TABLE IF EXISTS {$s}.seed_system_accounts");
        $this->db->statement(<<<SQL
            CREATE UNLOGGED TABLE {$s}.seed_system_accounts AS
            WITH template(role, code, name, class, type, system_account, grp) AS (VALUES {$systemValues})
            SELECT p.farm_id, t.*, md5(p.farm_id || '-' || t.code)::uuid::text AS accountid
            FROM {$s}.seed_farm_profile p CROSS JOIN template t
            SQL);

        $this->db->statement(<<<SQL
            INSERT INTO {$s}.xero_accounts (_valid_from, farm_id, accountid, code, name, class, type, system_account)
            SELECT {$vf}, farm_id, accountid, code, name, class, type, system_account FROM {$s}.seed_plan
            UNION ALL
            SELECT {$vf}, farm_id, accountid, code, name, class, type, system_account FROM {$s}.seed_system_accounts
            SQL);

        $this->db->statement(<<<SQL
            INSERT INTO {$s}.categories (_valid_from, farm_id, name, "group", non_operating, "order", system_category_name)
            SELECT DISTINCT {$vf}, farm_id, category, grp, grp LIKE 'non_operating%', 0, category FROM {$s}.seed_plan
            UNION
            SELECT {$vf}, farm_id, name, grp, false, 0, name FROM {$s}.seed_system_accounts
            SQL);

        $this->db->statement(<<<SQL
            INSERT INTO {$s}.category_xero_account (category_id, xero_account_id, farm_id)
            SELECT c.id, a.accountid, a.farm_id
            FROM (
                SELECT farm_id, accountid, category AS category_name FROM {$s}.seed_plan
                UNION ALL
                SELECT farm_id, accountid, name FROM {$s}.seed_system_accounts
            ) a
            JOIN {$s}.categories c ON c.farm_id = a.farm_id AND c.name = a.category_name
            SQL);

        return (int) $this->db->selectOne("SELECT count(*) AS n FROM {$s}.xero_accounts WHERE farm_id BETWEEN ? AND ?", [$this->firstFarmId, $this->firstFarmId + $this->farmCount - 1])->n;
    }

    /**
     * Milk supplies for the dairy farms: each a tracker with its own income
     * account under a Milk Income category, monthly production over the whole
     * window on the seasonal curve, and a price per month.
     */
    private function milk(): int
    {
        $s = InsightsSchema::SCHEMA;
        $vf = self::VALID_FROM;
        $large = PracticeShape::TYPE_DAIRY_LARGE;
        $largeCount = PracticeShape::MILK_TRACKERS_LARGE_HERD;
        $companies = $this->textArray(PracticeShape::MILK_COMPANIES);
        $companyCount = count(PracticeShape::MILK_COMPANIES);
        $byBand = implode(' ', array_map(
            fn (string $band, int $n): string => sprintf('WHEN %s THEN %d', $this->quote($band), $n),
            array_keys(PracticeShape::MILK_TRACKERS_BY_BAND),
            PracticeShape::MILK_TRACKERS_BY_BAND,
        ));
        $curve = implode(', ', PracticeShape::MILK_CURVE);
        $curveTotal = array_sum(PracticeShape::MILK_CURVE);
        $kgms = PracticeShape::MEDIUM_FARM_KGMS;
        $price = PracticeShape::MILK_PRICE;
        $horizon = self::HORIZON;

        $this->db->statement(<<<SQL
            INSERT INTO {$s}.milk_trackers (id, _valid_from, farm_id, name, company, income_accountid)
            SELECT p.farm_id * 100 + k, {$vf}, p.farm_id, 'Milk supply ' || k,
                   ({$companies})[1 + abs(hashint8(p.farm_id::bigint * 101 + k)) % {$companyCount}],
                   md5(p.farm_id || '-milk-' || k)::uuid::text
            FROM {$s}.seed_farm_profile p
            CROSS JOIN LATERAL generate_series(1, CASE WHEN p.farm_type = '{$large}' THEN {$largeCount}
                                                       ELSE CASE p.size_band {$byBand} END END) k
            WHERE p.farm_type IN ('dairy', '{$large}')
            SQL);

        $this->db->statement(<<<SQL
            INSERT INTO {$s}.xero_accounts (_valid_from, farm_id, accountid, code, name, class, type)
            SELECT {$vf}, farm_id, income_accountid, '200-' || (id % 100), 'Milk Sales - ' || name, 'REVENUE', 'SALES'
            FROM {$s}.milk_trackers WHERE farm_id BETWEEN {$this->firstFarmId} AND {$this->lastFarmId()}
            SQL);

        $this->db->statement(<<<SQL
            INSERT INTO {$s}.categories (_valid_from, farm_id, name, "group", system_category_name, milk_tracker_id)
            SELECT {$vf}, farm_id, 'Milk Income', 'income', 'Milk Income', id
            FROM {$s}.milk_trackers WHERE farm_id BETWEEN {$this->firstFarmId} AND {$this->lastFarmId()}
            SQL);

        $this->db->statement(<<<SQL
            INSERT INTO {$s}.category_xero_account (category_id, xero_account_id, farm_id)
            SELECT c.id, mt.income_accountid, mt.farm_id
            FROM {$s}.milk_trackers mt
            JOIN {$s}.categories c ON c.milk_tracker_id = mt.id
            WHERE mt.farm_id BETWEEN {$this->firstFarmId} AND {$this->lastFarmId()}
            SQL);

        $this->db->statement(<<<SQL
            INSERT INTO {$s}.milk_productions (_valid_from, farm_id, milk_tracker_id, transaction_date, type, production)
            SELECT {$vf}, mt.farm_id, mt.id, m::date,
                   CASE WHEN m::date <= DATE '{$horizon}' THEN 'actual' ELSE 'forecast' END,
                   round(
                       p.scale * {$kgms} / n.trackers
                       * (ARRAY[{$curve}])[EXTRACT(MONTH FROM m)::int] / {$curveTotal}
                       * (0.9 + (abs(hashint8(mt.id::bigint * 31 + EXTRACT(EPOCH FROM m)::bigint)) % 200) / 1000.0)
                   )::int
            FROM {$s}.milk_trackers mt
            JOIN {$s}.seed_farm_profile p ON p.farm_id = mt.farm_id
            JOIN (SELECT farm_id, count(*) AS trackers FROM {$s}.milk_trackers GROUP BY farm_id) n ON n.farm_id = mt.farm_id
            CROSS JOIN generate_series(DATE '{$this->firstMonth()}', DATE '{$this->lastMonth()}', INTERVAL '1 month') m
            SQL);

        $this->db->statement(<<<SQL
            INSERT INTO {$s}.milk_tracker_prices (milk_tracker_id, month, price)
            SELECT mt.id, m::date,
                   round({$price} * (0.95 + (abs(hashint8(mt.id::bigint * 7 + EXTRACT(YEAR FROM m)::bigint)) % 100) / 1000.0))::bigint
            FROM {$s}.milk_trackers mt
            JOIN {$s}.seed_farm_profile p ON p.farm_id = mt.farm_id
            CROSS JOIN generate_series(DATE '{$this->firstMonth()}', DATE '{$this->lastMonth()}', INTERVAL '1 month') m
            SQL);

        return (int) $this->db->selectOne("SELECT count(*) AS n FROM {$s}.milk_trackers WHERE farm_id BETWEEN ? AND ?", [$this->firstFarmId, $this->lastFarmId()])->n;
    }

    /**
     * Every economic event as one row: plan-account actuals fanned out to the
     * farm's transaction rate, one forecast per account-month, the milk
     * cheques already paid, and the opening balance. The transaction id packs
     * farm, month, account and sequence, so it is unique without a sequence.
     */
    private function events(): int
    {
        $s = InsightsSchema::SCHEMA;
        $horizon = self::HORIZON;
        $opening = self::OPENING_DATE;
        $fp = 10_000;

        $this->db->statement("DROP TABLE IF EXISTS {$s}.seed_events");
        $this->db->statement(<<<SQL
            CREATE UNLOGGED TABLE {$s}.seed_events AS
            WITH months AS (
                SELECT m::date AS m, (row_number() OVER (ORDER BY m))::int AS mi
                FROM generate_series(DATE '{$this->firstMonth()}', DATE '{$this->lastMonth()}', INTERVAL '1 month') m
            ),
            seasonal AS (
                SELECT m, mi, 1 + 0.25 * cos(2 * pi() * (EXTRACT(MONTH FROM m) - 10) / 12) AS factor FROM months
            ),
            actual AS (
                SELECT
                    pl.farm_id, pl.accountid, pl.class, pl.taxable, 'actuals'::text AS type,
                    pl.farm_id::bigint * 10000000000 + se.mi::bigint * 100000000 + pl.account_index * 1000000 + k AS txn_id,
                    se.m + (abs(hashint8(pl.farm_id::bigint * 1000003 + se.mi * 1009 + pl.account_index * 101 + k)) % 28)::int AS accrual_date,
                    round(pl.monthly * fp.scale * se.factor / n.cnt
                          * (0.5 + (abs(hashint8(pl.farm_id::bigint * 7 + se.mi * 13 + pl.account_index * 17 + k * 19)) % 1000) / 1000.0)
                          * {$fp})::bigint AS amount
                FROM {$s}.seed_plan pl
                JOIN {$s}.seed_farm_profile fp ON fp.farm_id = pl.farm_id
                CROSS JOIN seasonal se
                CROSS JOIN LATERAL (SELECT greatest(1, round(fp.txns_per_month * pl.share))::int AS cnt) n
                CROSS JOIN LATERAL generate_series(1, n.cnt) k
                WHERE se.m <= DATE '{$horizon}'
            ),
            forecast(farm_id, accountid, class, taxable, type, txn_id, accrual_date, amount) AS (
                SELECT
                    pl.farm_id, pl.accountid, pl.class, pl.taxable, 'forecast'::text,
                    pl.farm_id::bigint * 10000000000 + se.mi::bigint * 100000000 + pl.account_index * 1000000,
                    se.m + 14,
                    round(pl.monthly * fp.scale * se.factor * {$fp})::bigint
                FROM {$s}.seed_plan pl
                JOIN {$s}.seed_farm_profile fp ON fp.farm_id = pl.farm_id
                CROSS JOIN seasonal se
                WHERE se.m > DATE '{$horizon}'
            ),
            milk(farm_id, accountid, class, taxable, type, txn_id, accrual_date, amount) AS (
                SELECT
                    mt.farm_id, mt.income_accountid, 'REVENUE'::text, true, 'actuals'::text,
                    mt.farm_id::bigint * 10000000000 + se.mi::bigint * 100000000 + (90 + mt.id % 100) * 1000000,
                    (se.m + INTERVAL '1 month' - INTERVAL '1 day')::date,
                    mp.production::bigint * pr.price
                FROM {$s}.milk_trackers mt
                JOIN {$s}.milk_productions mp ON mp.milk_tracker_id = mt.id
                JOIN {$s}.milk_tracker_prices pr ON pr.milk_tracker_id = mt.id AND pr.month = mp.transaction_date
                JOIN seasonal se ON se.m = mp.transaction_date
                WHERE mt.farm_id BETWEEN {$this->firstFarmId} AND {$this->lastFarmId()}
                  AND mp.production > 0
                  AND (mp.transaction_date + INTERVAL '1 month 19 days')::date <= DATE '{$horizon}'
            )
            SELECT *, accrual_date + 20 AS cash_date FROM actual
            UNION ALL
            SELECT *, accrual_date + 20 FROM forecast
            UNION ALL
            SELECT *, accrual_date + 20 FROM milk
            SQL);

        $this->db->statement(<<<SQL
            INSERT INTO {$s}.transactions (id, transaction_number, farm_id, type, date, cash_date, accrual_date)
            SELECT txn_id, 'INV-' || txn_id, farm_id, type, accrual_date, cash_date, accrual_date FROM {$s}.seed_events
            UNION ALL
            SELECT farm_id::bigint * 10000000000, 'OPENING-' || farm_id, farm_id, 'actuals',
                   DATE '{$opening}', DATE '{$opening}', DATE '{$opening}'
            FROM {$s}.seed_farm_profile
            SQL);

        return (int) $this->db->selectOne("SELECT count(*) AS n FROM {$s}.seed_events")->n + $this->farmCount;
    }

    /**
     * Each event's legs on both bases. Revenue is credit-negative and
     * everything else debit-positive, so the bank leg is always the negation
     * of the account and GST legs together.
     */
    private function lines(): int
    {
        $s = InsightsSchema::SCHEMA;
        $gst = self::GST_RATE;
        $opening = self::OPENING_DATE;

        $this->db->statement(<<<SQL
            INSERT INTO {$s}.transaction_lines (transaction_id, farm_id, type, basis, date, account_id, net_amount, tax_amount)
            SELECT e.txn_id, e.farm_id, e.type, leg.basis, leg.date, leg.account_id, leg.net_amount, leg.tax_amount
            FROM {$s}.seed_events e
            JOIN (
                SELECT farm_id,
                       max(accountid) FILTER (WHERE role = 'bank') AS bank,
                       max(accountid) FILTER (WHERE role = 'gst') AS gst,
                       max(accountid) FILTER (WHERE role = 'payables') AS payables
                FROM {$s}.seed_system_accounts GROUP BY farm_id
            ) sa ON sa.farm_id = e.farm_id
            CROSS JOIN LATERAL (
                SELECT (CASE WHEN e.class = 'REVENUE' THEN -1 ELSE 1 END) * e.amount AS net,
                       CASE WHEN e.taxable THEN round((CASE WHEN e.class = 'REVENUE' THEN -1 ELSE 1 END) * e.amount * {$gst})::bigint ELSE 0 END AS tax
            ) a
            CROSS JOIN LATERAL (VALUES
                ('cash',    e.cash_date,    e.accountid,  a.net,           a.tax),
                ('cash',    e.cash_date,    sa.gst,       a.tax,           0::bigint),
                ('cash',    e.cash_date,    sa.bank,      -(a.net + a.tax), 0::bigint),
                ('accrual', e.accrual_date, e.accountid,  a.net,           a.tax),
                ('accrual', e.accrual_date, sa.gst,       a.tax,           0::bigint),
                ('accrual', e.accrual_date, sa.payables,  -(a.net + a.tax), 0::bigint),
                ('accrual', e.cash_date,    sa.payables,  a.net + a.tax,   0::bigint),
                ('accrual', e.cash_date,    sa.bank,      -(a.net + a.tax), 0::bigint)
            ) leg(basis, date, account_id, net_amount, tax_amount)
            WHERE NOT (leg.account_id = sa.gst AND leg.net_amount = 0)
            SQL);

        // The opening position: a bank balance against opening equity, sized
        // to the farm, negative for about one farm in five so some portfolios
        // start overdrawn.
        $this->db->statement(<<<SQL
            INSERT INTO {$s}.transaction_lines (transaction_id, farm_id, type, basis, date, account_id, net_amount)
            SELECT p.farm_id::bigint * 10000000000, p.farm_id, 'actuals', b.basis, DATE '{$opening}', leg.account_id, leg.net_amount
            FROM {$s}.seed_farm_profile p
            JOIN (
                SELECT farm_id,
                       max(accountid) FILTER (WHERE role = 'bank') AS bank,
                       max(accountid) FILTER (WHERE role = 'opening') AS equity
                FROM {$s}.seed_system_accounts GROUP BY farm_id
            ) sa ON sa.farm_id = p.farm_id
            CROSS JOIN (VALUES ('cash'), ('accrual')) b(basis)
            CROSS JOIN LATERAL (
                SELECT round(p.scale * 150000 * 10000 * ((abs(hashint8(p.farm_id::bigint * 3)) % 1000) / 1000.0 - 0.2))::bigint AS balance
            ) o
            CROSS JOIN LATERAL (VALUES (sa.bank, o.balance), (sa.equity, -o.balance)) leg(account_id, net_amount)
            SQL);

        return (int) $this->db->selectOne("SELECT count(*) AS n FROM {$s}.transaction_lines WHERE farm_id BETWEEN ? AND ?", [$this->firstFarmId, $this->lastFarmId()])->n;
    }

    /**
     * The two-monthly returns already filed: for every payment date on or
     * before the horizon, the window's net GST settled against the bank,
     * tagged so the statement can tell a settlement from a tax component.
     * The calendar is the statement's own: a return period ends every second
     * month counted from the balance date, paid on the 28th of the month
     * after, except November's (15 January) and March's (7 May).
     */
    private function gstSettlements(): int
    {
        $s = InsightsSchema::SCHEMA;
        $horizon = self::HORIZON;
        $tag = self::TAG_GST_PAYMENT;

        $this->db->statement(<<<SQL
            WITH gst AS (
                SELECT farm_id,
                       max(accountid) FILTER (WHERE role = 'gst') AS gst,
                       max(accountid) FILTER (WHERE role = 'bank') AS bank
                FROM {$s}.seed_system_accounts GROUP BY farm_id
            ),
            monthly AS (
                SELECT tl.farm_id, tl.basis, date_trunc('month', tl.date)::date AS ms, SUM(tl.net_amount) AS net
                FROM {$s}.transaction_lines tl
                JOIN gst g ON g.farm_id = tl.farm_id AND g.gst = tl.account_id
                WHERE tl.type = 'actuals' AND tl.tag IS NULL
                GROUP BY 1, 2, 3
            ),
            pay AS (
                SELECT p.farm_id, m::date AS pm,
                       CASE
                           WHEN EXTRACT(MONTH FROM m) = 12 THEN make_date(EXTRACT(YEAR FROM m)::int + 1, 1, 15)
                           WHEN EXTRACT(MONTH FROM m) = 4 THEN make_date(EXTRACT(YEAR FROM m)::int, 5, 7)
                           ELSE make_date(EXTRACT(YEAR FROM m)::int, EXTRACT(MONTH FROM m)::int, 28)
                       END AS pay_date
                FROM {$s}.seed_farm_profile p
                CROSS JOIN generate_series(DATE '{$this->firstMonth()}' + INTERVAL '2 months', DATE '{$horizon}', INTERVAL '1 month') m
                WHERE EXTRACT(MONTH FROM m)::int % 2 = (p.fy_end_month + 1) % 2
            ),
            returns AS (
                SELECT pay.farm_id, b.basis, pay.pay_date, pay.pm, COALESCE(SUM(mo.net), 0) AS net
                FROM pay
                CROSS JOIN (VALUES ('cash'), ('accrual')) b(basis)
                LEFT JOIN monthly mo ON mo.farm_id = pay.farm_id AND mo.basis = b.basis
                     AND mo.ms >= (pay.pm - INTERVAL '2 months')::date AND mo.ms < pay.pm
                WHERE pay.pay_date <= DATE '{$horizon}'
                GROUP BY 1, 2, 3, 4
            ),
            ins_txn AS (
                INSERT INTO {$s}.transactions (id, transaction_number, farm_id, type, date, cash_date, accrual_date, tags)
                SELECT DISTINCT r.farm_id::bigint * 10000000000 + 9000000000 + EXTRACT(YEAR FROM r.pm)::bigint * 100 + EXTRACT(MONTH FROM r.pm)::bigint,
                       'GST-' || r.farm_id || '-' || to_char(r.pm, 'YYYY-MM'), r.farm_id, 'actuals', r.pay_date, r.pay_date, r.pay_date, ARRAY['{$tag}']
                FROM returns r
                RETURNING 1
            )
            INSERT INTO {$s}.transaction_lines (transaction_id, farm_id, type, basis, date, account_id, net_amount, tag)
            SELECT r.farm_id::bigint * 10000000000 + 9000000000 + EXTRACT(YEAR FROM r.pm)::bigint * 100 + EXTRACT(MONTH FROM r.pm)::bigint,
                   r.farm_id, 'actuals', r.basis, r.pay_date, leg.account_id, leg.net_amount, '{$tag}'
            FROM returns r
            JOIN gst g ON g.farm_id = r.farm_id
            CROSS JOIN LATERAL (VALUES (g.gst, -r.net), (g.bank, r.net)) leg(account_id, net_amount)
            WHERE r.net <> 0
            SQL);

        return (int) $this->db->selectOne(
            "SELECT count(*) AS n FROM {$s}.transactions WHERE farm_id BETWEEN ? AND ? AND tags @> ARRAY[?]",
            [$this->firstFarmId, $this->lastFarmId(), $tag],
        )->n;
    }

    private function firstMonth(): string
    {
        return self::FIRST_MONTH;
    }

    private function lastMonth(): string
    {
        return self::LAST_MONTH;
    }

    private function lastFarmId(): int
    {
        return $this->firstFarmId + $this->farmCount - 1;
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
    private function textArray(array $values): string
    {
        return 'ARRAY['.implode(', ', array_map(fn (string $v): string => $this->quote($v), $values)).']::text[]';
    }

    private function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
