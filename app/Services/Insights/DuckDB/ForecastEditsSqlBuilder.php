<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

/**
 * Changes to the forecast as journal lines, in the lake's shape, for the
 * shared report SQL to read alongside the forecast: the scenarios the farm
 * has adopted (stored lines), as things stand or as they stood at a time,
 * and one proposed scenario (generated here from the changes bound to the
 * proposal's parameter).
 *
 * A proposed change is booked the way Figured books a forecast, so every
 * rule downstream applies to it unchanged:
 *
 *   - an account change adds to, scales or replaces a category's forecast
 *     for a run of months, split across the category's accounts in
 *     proportion to what each already forecasts, and is paid like any other
 *     forecast line (incurred mid-month, paid 20 days later, GST where the
 *     account carries it), so the GST return and the bank balance follow;
 *   - an asset purchase books the purchase and any trade-in against capital
 *     purchases, with GST, then depreciation each month to the season's end,
 *     and optionally a table loan: drawn when the purchase is paid, repaid
 *     monthly, each repayment split into interest and principal.
 *
 * Account changes keep the forecast's enterprise split, or go to one
 * enterprise when the change names it; asset purchases and loans are
 * whole-farm.
 *
 * Every line is dated after the horizon, so the report treats it as
 * forecast; the budget, as agreed before any change, includes none of it.
 */
final class ForecastEditsSqlBuilder
{
    public const string PROPOSAL = 'scenario';

    public const string CAPITAL_PURCHASES = 'Capital Purchases';
    public const string DEPRECIATION = 'Depreciation';
    public const string INTEREST = 'Interest';
    public const string LOAN_REPAYMENTS = 'Loan Repayments';

    /** How the changes are booked, in words, for whoever explains a scenario's numbers. */
    public const array ASSUMPTIONS = [
        'Changes are booked as forecast in the month named: incurred mid-month and paid 20 days later, like the rest of the forecast.',
        'GST at 15% applies where the account carries it, and is claimed or paid in the next two-monthly GST return.',
        'Depreciation is the cost times the rate, a twelfth each month from the purchase month (diminishing value, first year).',
        'A trade-in is booked as a sale of plant at its value. There is no asset register here, so no gain or loss on disposal is worked out and the old asset\'s depreciation carries on.',
        'A loan is drawn when the purchase is paid and repaid monthly as a table loan from a month later: the same payment each month, interest on the balance still owed.',
        'Proportional and replacing changes work from the forecast as it stands now, adopted scenarios included.',
    ];

    /** Forecast lines are incurred on the 15th and paid 20 days later, the seeded forecast's terms. */
    private const int INCURRED_DAY_OFFSET = 14;
    private const int PAYMENT_DAYS = 20;

    /**
     * @param string $horizon a DuckDB date expression for the report's horizon
     */
    public function __construct(
        private readonly ReportLinesSqlBuilder $lines,
        private readonly ForecastEdits $edits,
        private readonly string $horizon,
        private readonly ?int $proposalFarmId,
    ) {
    }

    /**
     * @return array<string, string> name => body, in dependency order; they
     *     read the report's `accounts`, and end in `forecast_edits`
     */
    public function ctes(): array
    {
        if (!$this->edits->any()) {
            return [];
        }

        $ctes = ['adopted_edits' => $this->adoptedCte()];
        if ($this->edits->proposal !== null) {
            $ctes += [
                'edit_changes' => $this->changesCte(),
                'edit_forecast' => $this->forecastCte(),
                'edit_accounts' => $this->accountsCte(),
                'edit_account_months' => $this->accountMonthsCte(),
                'edit_account_lines' => $this->accountLinesCte(),
                'edit_assets' => $this->assetsCte(),
                'edit_repayments' => $this->repaymentsCte(),
                'edit_events' => $this->eventsCte(),
                'edit_legs' => $this->legsCte(),
            ];
        }
        $ctes['forecast_edits'] = $this->forecastEditsCte();

        return $ctes;
    }

    /**
     * What each proposed change books, per kind and category: the dates it
     * runs between, the amount inside the season and in all, and its GST.
     * Reads `edit_events` and `edit_accounts`.
     */
    public static function workingsSelect(): string
    {
        return <<<'SQL'
            SELECT e.change_no, e.kind, a.category,
                   strftime(min(e.accrual_date), '%Y-%m') AS first_month,
                   strftime(max(e.accrual_date), '%Y-%m') AS last_month,
                   count(*) AS entries,
                   round(CAST(SUM(CASE WHEN e.kind = 'adjustment' THEN e.net * a.sign ELSE abs(e.net) END)
                         FILTER (WHERE e.accrual_date <= CAST($period_to AS DATE)) AS DOUBLE) / 10000, 2) AS in_season,
                   round(CAST(SUM(CASE WHEN e.kind = 'adjustment' THEN e.net * a.sign ELSE abs(e.net) END) AS DOUBLE) / 10000, 2) AS in_all,
                   round(CAST(SUM(e.tax) AS DOUBLE) / 10000, 2) AS gst
            FROM edit_events e
            JOIN edit_accounts a ON a.account_id = e.account_id
            GROUP BY e.change_no, e.kind, a.category
            ORDER BY e.change_no, min(e.accrual_date), e.kind
            SQL;
    }

    /**
     * The adopted scenarios' lines in the forecast as known: adopted by then
     * and not withdrawn by then, or, as things stand, adopted and not
     * withdrawn. None when the report reads no adopted edits.
     */
    private function adoptedCte(): string
    {
        $lines = InsightsDuckDb::table('forecast_edit_lines');
        $scenarios = InsightsDuckDb::table('plan_scenarios');
        $known = match (true) {
            !$this->edits->adopted => 'false',
            $this->edits->knownAt === null => 's.withdrawn_at IS NULL',
            default => "s.adopted_at <= CAST(\${$this->edits->knownAt} AS TIMESTAMP)
                  AND (s.withdrawn_at IS NULL OR s.withdrawn_at > CAST(\${$this->edits->knownAt} AS TIMESTAMP))",
        };

        return <<<SQL
            SELECT e.farm_id, e.basis, CAST(e.date AS DATE) AS date, e.account_id, e.net_amount, e.tax_amount, e.enterprise
            FROM {$lines} e
            JOIN {$scenarios} s ON s.id = e.scenario_id
            WHERE {$this->lines->farmFilter('e.farm_id')} AND s.adopted_at IS NOT NULL
              AND {$known}
            SQL;
    }

    private function changesCte(): string
    {
        $proposal = $this->edits->proposal;

        return <<<SQL
            SELECT generate_subscripts(changes, 1) AS change_no, unnest(changes) AS c
            FROM (SELECT CAST(CAST(\${$proposal} AS JSON) AS JSON[]) AS changes)
            SQL;
    }

    /**
     * The farm's accrual forecast by account and month after the horizon,
     * adopted changes included, which proportional and replacing changes
     * work from; and whether each account carries GST.
     */
    private function forecastCte(): string
    {
        $lake = InsightsDuckDb::lines();
        $farm = $this->proposalFarmId;

        return <<<SQL
            SELECT l.account_id, l.month, l.enterprise,
                   COALESCE(SUM(l.net_amount) FILTER (WHERE l.forecast), 0) AS net,
                   bool_or(l.tax_amount <> 0) AS taxed
            FROM (
                SELECT tl.account_id, tl.month, tl.enterprise, tl.net_amount, tl.tax_amount,
                       tl.type = 'forecast' AND tl.date > {$this->horizon} AS forecast
                FROM {$lake} tl
                WHERE {$this->lines->farmFilter('tl.farm_id')}
                  AND tl.basis = 'accrual'
                  AND tl.date BETWEEN CAST(\$period_from AS DATE) AND CAST(\$period_to AS DATE)
                UNION ALL
                SELECT account_id, CAST(date_trunc('month', date) AS DATE), enterprise, net_amount, tax_amount, date > {$this->horizon}
                FROM adopted_edits
                WHERE farm_id = {$farm} AND basis = 'accrual'
            ) l
            GROUP BY l.account_id, l.month, l.enterprise
            SQL;
    }

    /** The farm's accounts, with the sign that turns a change as spoken (income up, costs up) into the ledger's. */
    private function accountsCte(): string
    {
        return <<<SQL
            SELECT a.accountid AS account_id, a.category, a.grp,
                   CASE WHEN a.grp IN ('income', 'non_operating_income') THEN -1 ELSE 1 END AS sign,
                   COALESCE(t.taxed, false) AS taxable
            FROM accounts a
            LEFT JOIN (SELECT account_id, bool_or(taxed) AS taxed FROM edit_forecast GROUP BY account_id) t
              ON t.account_id = a.accountid
            WHERE a.farm_id = {$this->proposalFarmId}
            SQL;
    }

    private function accountMonthsCte(): string
    {
        return <<<'SQL'
            SELECT p.change_no, p.c->>'category' AS category, p.c->>'enterprise' AS enterprise,
                   COALESCE(p.c->>'application_mode', 'add') AS mode,
                   CAST(p.c->>'percentage' AS DOUBLE) AS pct,
                   CAST(p.c->'values' AS DOUBLE[]) AS vals,
                   CAST(m.m AS DATE) AS ms,
                   row_number() OVER (PARTITION BY p.change_no ORDER BY m.m) AS i
            FROM (SELECT * FROM edit_changes WHERE c->>'type' = 'account') p
            CROSS JOIN LATERAL generate_series(
                CAST(CAST((p.c->>'start_month') || '-01' AS DATE) AS TIMESTAMP),
                CAST(CAST((p.c->>'end_month') || '-01' AS DATE) AS TIMESTAMP),
                INTERVAL '1 month'
            ) AS m(m)
            SQL;
    }

    /**
     * Each change's month on each of its category's accounts and their
     * enterprises (only the one the change names, if it names one), with
     * what each already forecasts, in dollars as spoken.
     */
    private function accountLinesCte(): string
    {
        return <<<'SQL'
            SELECT m.change_no, m.ms, m.mode, m.pct,
                   CASE WHEN len(m.vals) = 1 THEN m.vals[1] ELSE m.vals[m.i] END AS value,
                   a.account_id, a.sign, a.taxable,
                   CASE WHEN f.account_id IS NULL THEN m.enterprise ELSE f.enterprise END AS enterprise,
                   COALESCE(f.net, 0) * a.sign / 10000.0 AS base
            FROM edit_account_months m
            JOIN edit_accounts a ON a.category = m.category
            LEFT JOIN edit_forecast f ON f.account_id = a.account_id AND f.month = m.ms
                 AND (m.enterprise IS NULL OR f.enterprise = m.enterprise)
            SQL;
    }

    private function assetsCte(): string
    {
        $account = static fn (string $category): string => "(SELECT min(account_id) FROM edit_accounts WHERE category = '{$category}')";
        $incurred = self::INCURRED_DAY_OFFSET;
        $paid = self::INCURRED_DAY_OFFSET + self::PAYMENT_DAYS;

        return <<<SQL
            SELECT a.*,
                   CASE WHEN a.loan > 0 AND a.r = 0 THEN a.loan / a.n
                        WHEN a.loan > 0 THEN a.loan * a.r / (1 - pow(1 + a.r, -a.n)) END AS payment,
                   (SELECT taxable FROM edit_accounts WHERE account_id = a.asset_id) AS taxable
            FROM (
                SELECT p.change_no,
                       CAST(CAST((p.c->>'month') || '-01' AS DATE) + {$incurred} AS DATE) AS incurred,
                       CAST(CAST((p.c->>'month') || '-01' AS DATE) + {$paid} AS DATE) AS paid,
                       CAST((p.c->>'month') || '-01' AS DATE) AS ms,
                       CAST(p.c->>'cost' AS DOUBLE) AS cost,
                       COALESCE(CAST(p.c->>'trade_in' AS DOUBLE), 0) AS trade_in,
                       COALESCE(CAST(p.c->>'depreciation_rate_pct' AS DOUBLE), 0) / 100 AS depreciation_rate,
                       COALESCE(CAST(p.c->'loan'->>'amount' AS DOUBLE), 0) AS loan,
                       COALESCE(CAST(p.c->'loan'->>'annual_rate_pct' AS DOUBLE), 0) / 1200 AS r,
                       COALESCE(CAST(p.c->'loan'->>'years' AS INTEGER), 0) * 12 AS n,
                       {$account(self::CAPITAL_PURCHASES)} AS asset_id,
                       {$account(self::DEPRECIATION)} AS depreciation_id,
                       {$account(self::INTEREST)} AS interest_id,
                       {$account(self::LOAN_REPAYMENTS)} AS loan_id
                FROM edit_changes p
                WHERE p.c->>'type' = 'asset_purchase'
            ) a
            SQL;
    }

    /**
     * A table loan's repayments: the same payment every month, the interest
     * on the balance still owed, the rest off the principal.
     */
    private function repaymentsCte(): string
    {
        return <<<'SQL'
            SELECT a.change_no, a.loan_id, a.interest_id,
                   CAST(a.paid + to_months(CAST(k.k AS INTEGER)) AS DATE) AS date,
                   a.payment,
                   CASE WHEN a.r = 0 THEN 0
                        ELSE a.r * (a.loan * pow(1 + a.r, k.k - 1) - a.payment * (pow(1 + a.r, k.k - 1) - 1) / a.r)
                   END AS interest
            FROM edit_assets a
            CROSS JOIN LATERAL generate_series(1, a.n) AS k(k)
            WHERE a.loan > 0
            SQL;
    }

    /**
     * Every proposed economic event, in fixed point: what it is, the account,
     * when it is incurred and paid, and how it settles: through payables like
     * any forecast purchase, directly through the bank (loan flows), or as a
     * journal with no cash at all (depreciation, against the asset).
     */
    private function eventsCte(): string
    {
        $incurred = self::INCURRED_DAY_OFFSET;
        $paid = self::INCURRED_DAY_OFFSET + self::PAYMENT_DAYS;
        $gst = InsightsDuckDbPracticeSeeder::GST_RATE;
        $adjustment = $this->cents(<<<'SQL'
            sign * CASE
                WHEN pct IS NOT NULL THEN base * pct / 100
                ELSE (CASE WHEN mode = 'replace' THEN value - category_base ELSE value END)
                     * CASE WHEN category_base <> 0 THEN base / category_base ELSE 1.0 / category_accounts END
            END
            SQL);

        return <<<SQL
            SELECT e.*, CASE WHEN e.taxable THEN CAST(round(e.net * {$gst}) AS BIGINT) ELSE CAST(0 AS BIGINT) END AS tax
            FROM (
                SELECT change_no, 'adjustment' AS kind, account_id, CAST(NULL AS VARCHAR) AS contra_id,
                       CAST(ms + {$incurred} AS DATE) AS accrual_date, CAST(ms + {$paid} AS DATE) AS cash_date,
                       {$adjustment} AS net, taxable, 'payables' AS settlement, enterprise
                FROM (
                    SELECT *, SUM(base) OVER (PARTITION BY change_no, ms) AS category_base,
                           count(*) OVER (PARTITION BY change_no, ms) AS category_accounts
                    FROM edit_account_lines
                )
                UNION ALL
                SELECT change_no, 'purchase', asset_id, NULL, incurred, paid, {$this->cents('cost')}, taxable, 'payables', NULL
                FROM edit_assets
                UNION ALL
                SELECT change_no, 'trade_in', asset_id, NULL, incurred, paid, -{$this->cents('trade_in')}, taxable, 'payables', NULL
                FROM edit_assets
                WHERE trade_in > 0
                UNION ALL
                SELECT a.change_no, 'depreciation', a.depreciation_id, a.asset_id,
                       CAST(d.m + INTERVAL '1 month' - INTERVAL '1 day' AS DATE), NULL,
                       {$this->cents('a.cost * a.depreciation_rate / 12')}, false, 'journal', NULL
                FROM edit_assets a
                CROSS JOIN LATERAL generate_series(CAST(a.ms AS TIMESTAMP), CAST(CAST(\$period_to AS DATE) AS TIMESTAMP), INTERVAL '1 month') AS d(m)
                WHERE a.depreciation_rate > 0
                UNION ALL
                SELECT change_no, 'loan_drawdown', loan_id, NULL, paid, paid, -{$this->cents('loan')}, false, 'direct', NULL
                FROM edit_assets
                WHERE loan > 0
                UNION ALL
                SELECT change_no, 'loan_interest', interest_id, NULL, date, date, {$this->cents('interest')}, false, 'direct', NULL
                FROM edit_repayments
                UNION ALL
                SELECT change_no, 'loan_principal', loan_id, NULL, date, date, {$this->cents('payment')} - {$this->cents('interest')}, false, 'direct', NULL
                FROM edit_repayments
            ) e
            WHERE e.net <> 0
            SQL;
    }

    /** Each event's legs on both bases, the way the ledger writes a transaction. */
    private function legsCte(): string
    {
        return <<<SQL
            SELECT e.change_no, e.kind, s.gst, unnest(CASE e.settlement
                WHEN 'payables' THEN [
                    {'basis': 'cash',    'date': e.cash_date,    'account_id': e.account_id, 'net_amount': e.net,             'tax_amount': e.tax,              'enterprise': e.enterprise},
                    {'basis': 'cash',    'date': e.cash_date,    'account_id': s.gst,        'net_amount': e.tax,             'tax_amount': CAST(0 AS BIGINT), 'enterprise': CAST(NULL AS VARCHAR)},
                    {'basis': 'cash',    'date': e.cash_date,    'account_id': s.bank,       'net_amount': -(e.net + e.tax),  'tax_amount': CAST(0 AS BIGINT), 'enterprise': CAST(NULL AS VARCHAR)},
                    {'basis': 'accrual', 'date': e.accrual_date, 'account_id': e.account_id, 'net_amount': e.net,             'tax_amount': e.tax,              'enterprise': e.enterprise},
                    {'basis': 'accrual', 'date': e.accrual_date, 'account_id': s.gst,        'net_amount': e.tax,             'tax_amount': CAST(0 AS BIGINT), 'enterprise': CAST(NULL AS VARCHAR)},
                    {'basis': 'accrual', 'date': e.accrual_date, 'account_id': s.payables,   'net_amount': -(e.net + e.tax),  'tax_amount': CAST(0 AS BIGINT), 'enterprise': CAST(NULL AS VARCHAR)},
                    {'basis': 'accrual', 'date': e.cash_date,    'account_id': s.payables,   'net_amount': e.net + e.tax,     'tax_amount': CAST(0 AS BIGINT), 'enterprise': CAST(NULL AS VARCHAR)},
                    {'basis': 'accrual', 'date': e.cash_date,    'account_id': s.bank,       'net_amount': -(e.net + e.tax),  'tax_amount': CAST(0 AS BIGINT), 'enterprise': CAST(NULL AS VARCHAR)}
                ]
                WHEN 'direct' THEN [
                    {'basis': 'cash',    'date': e.cash_date,    'account_id': e.account_id, 'net_amount': e.net,             'tax_amount': CAST(0 AS BIGINT), 'enterprise': e.enterprise},
                    {'basis': 'cash',    'date': e.cash_date,    'account_id': s.bank,       'net_amount': -e.net,            'tax_amount': CAST(0 AS BIGINT), 'enterprise': CAST(NULL AS VARCHAR)},
                    {'basis': 'accrual', 'date': e.accrual_date, 'account_id': e.account_id, 'net_amount': e.net,             'tax_amount': CAST(0 AS BIGINT), 'enterprise': e.enterprise},
                    {'basis': 'accrual', 'date': e.accrual_date, 'account_id': s.bank,       'net_amount': -e.net,            'tax_amount': CAST(0 AS BIGINT), 'enterprise': CAST(NULL AS VARCHAR)}
                ]
                ELSE [
                    {'basis': 'accrual', 'date': e.accrual_date, 'account_id': e.account_id, 'net_amount': e.net,             'tax_amount': CAST(0 AS BIGINT), 'enterprise': e.enterprise},
                    {'basis': 'accrual', 'date': e.accrual_date, 'account_id': e.contra_id,  'net_amount': -e.net,            'tax_amount': CAST(0 AS BIGINT), 'enterprise': CAST(NULL AS VARCHAR)}
                ]
            END) AS leg
            FROM edit_events e
            CROSS JOIN (
                SELECT max(accountid) FILTER (WHERE account_type = 'BANK') AS bank,
                       max(accountid) FILTER (WHERE system_account = 'GST') AS gst,
                       max(accountid) FILTER (WHERE system_account = 'CREDITORS') AS payables
                FROM accounts
                WHERE farm_id = {$this->proposalFarmId}
            ) s
            SQL;
    }

    private function forecastEditsCte(): string
    {
        $proposed = $this->edits->proposal !== null
            ? <<<SQL
                UNION ALL
                SELECT {$this->proposalFarmId}, leg.basis, leg.date, leg.account_id, leg.net_amount, leg.tax_amount, leg.enterprise,
                       'proposed', change_no, kind
                FROM edit_legs
                WHERE NOT (leg.account_id = gst AND leg.net_amount = 0)
                SQL
            : '';

        return <<<SQL
            SELECT farm_id, basis, date, CAST(date_trunc('month', date) AS DATE) AS month, account_id,
                   CAST(NULL AS VARCHAR) AS tag, net_amount, tax_amount, enterprise, source, change_no, kind
            FROM (
                SELECT farm_id, basis, date, account_id, net_amount, tax_amount, enterprise,
                       'adopted' AS source, CAST(NULL AS BIGINT) AS change_no, CAST(NULL AS VARCHAR) AS kind
                FROM adopted_edits
            {$proposed}
            )
            SQL;
    }

    /** Dollars as fixed point, to the cent. */
    private function cents(string $dollars): string
    {
        return "CAST(round(({$dollars}) * 100) * 100 AS BIGINT)";
    }
}
