<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

/**
 * Figured's `DataPipeline`, one CTE per pipe, in `DataPipelineService::$pipes`
 * order.
 *
 * The question this answers is Richard's, not a speed one: can an
 * ordering-dependent pipeline of twenty-four stages — three of which run a
 * nested report of their own — be written as composable SQL, or does the
 * ordering force materialisation between stages? So the shape is deliberately
 * the pipeline's shape. Every stage is a CTE named for its pipe number, every
 * stage reads the one before it, and the data flowing between them is
 * Figured's array — `[account_id][interval] => amount` — as a long table
 * `(farm_id, account_id, interval_index, amount)`. A pipe whose gate is off
 * emits `SELECT * FROM <previous>` rather than being omitted, so the chain
 * always has every name and `duckdb:pipeline` can diff any stage against the
 * PHP transliteration of the same pipe. An ordering mistake is then caught at
 * the stage that made it.
 *
 * `farm_id` is the entity. A single-farm report has one; a reporting-group
 * report has one per child, and every child runs the whole chain side by side
 * in the one statement — Figured instead fans out an HTTP request per child
 * and sums the responses in `CombineReports`, which is `p25_combine` here.
 * Pipe 11 is the only stage that reads across entities.
 *
 * Gates are the same conditions Figured's `shouldHandle()` methods test,
 * evaluated in PHP because they are report options, not data. Pipes 12 and
 * 17 are pass-throughs because they are pass-throughs in Figured: both open
 * with `return false;` (FIG-16282) and the GST logic they held now arrives as
 * journals at pipe 8. Pipe 3 widens tracking-option filters for multi-farm
 * trackers; the scan here filters no tracking, so it has nothing to widen.
 *
 * Amounts are the lake's stored signs — revenue credit-negative, expense
 * debit-positive — until pipe 24 deflates. The three nested reports are
 * inner CTE chains over the same scan with their own fixed options, which is
 * precisely how Figured nests them (`OverdraftCalculationService` runs a
 * cash-basis consolidated sub-report; `CurrentYearEarnings` and
 * `RetainedEarnings` each run a `CurrentYearEarningsReport`).
 *
 * The GST payment schedule is NZ two-monthly with the exception-month *dates*
 * applied but the exception-month *windows* unverified against
 * `PaymentsDates::getSchedule()` — see the README.
 */
final class DataPipelineSqlBuilder
{
    private const int FIXED_POINT = 10000;

    public const string TAG_EOY = 'eoy_adjust_manual';
    public const string TAG_GST_PAYMENT = 'gst_payment';

    /** @var list<string> every stage name, in pipeline order */
    public const array STAGES = [
        'p01_empty', 'p02_scan', 'p03_mf_trackers', 'p04_mapped_in', 'p05_nesting',
        'p06_query', 'p07_cells', 'p08_merge_vj', 'p09_opening_bank', 'p10_opening_gst',
        'p11_offsets', 'p12_gst_payments', 'p13_merge_mapped', 'p14_cye', 'p15_retained',
        'p16_ytd', 'p17_contra_gst', 'p18_expected_sign', 'p19_inverse', 'p20_consolidate',
        'p21_dynamic_bank', 'p22_hide_empty', 'p23_hide_accounts', 'p24_format',
        'p25_combine',
    ];

    /** Stages whose rows are lines, not cells; projected onto intervals for the diff. */
    private const array SCAN_STAGES = ['p02_scan', 'p03_mf_trackers', 'p04_mapped_in', 'p05_nesting', 'p06_query'];

    public function __construct(
        private readonly string $alias,
        private readonly string $appAlias,
        private readonly PipelineOptions $options,
        private readonly ?int $entitiesPerBranch = null,
    ) {
    }

    /**
     * The whole chain, ending at `$stage` (default: the last). Every stage but
     * `p25_combine` returns `(farm_id, account_id, interval_index, amount)`.
     */
    public function build(string $stage = 'p25_combine'): string
    {
        $ctes = [];

        $ctes['farm'] = $this->farmCte();
        $ctes['entities'] = $this->entitiesCte();
        $ctes['months'] = $this->monthSpineCte();
        $ctes['days'] = $this->daySpineCte();
        $ctes['lines_by_day'] = $this->linesByDayCte();
        $ctes['sys_accounts'] = $this->systemAccountsCte();
        $ctes['posted_accounts'] = $this->postedAccountsCte();
        $ctes['report_accounts'] = $this->reportAccountsCte();
        $ctes['entity_accounts'] = $this->entityAccountsCte();

        $ctes['p01_empty'] = $this->p01Empty();
        $ctes['p02_scan'] = $this->scanCte($this->options);
        $ctes['p03_mf_trackers'] = $this->passThrough('p02_scan');
        $ctes['p04_mapped_in'] = $this->passThrough('p03_mf_trackers');
        $ctes['p05_nesting'] = $this->passThrough('p04_mapped_in');
        $ctes['p06_query'] = $this->passThrough('p05_nesting');
        $ctes['p07_cells'] = $this->cellsCte('p01_empty', 'p06_query');

        // Pipe 8's inputs: the two virtual journal handlers that fire for a
        // cash flow, each over its own nested report.
        // Read three times below, so DuckDB materialises it: collapsed to days
        // it is thousands of rows rather than every line in the period.
        $inner = $this->options->forOverdraftSubReport();
        $ctes['inner_scan'] = "SELECT farm_id, account_id, date, tag, SUM(amount) AS amount\n"
            ."FROM (\n{$this->scanCte($inner)}\n) l\n"
            .'GROUP BY farm_id, account_id, date, tag';
        $ctes['inner_cells'] = $this->cellsCte('p01_empty', 'inner_scan');
        $ctes['gst_vj'] = $this->gstPaymentsRefundsVjCte();
        $ctes['inner_with_gst'] = $this->addJournalsCte('inner_cells', 'gst_vj');
        // The sub-report is a DataPipeline of its own, so a reporting-group
        // request runs its pipes 11 and 20 too; nothing between 8 and 20
        // changes a cash flow's net movement, so they apply here directly.
        $ctes['inner_offsets'] = $this->offsetsCte('inner_with_gst', $inner);
        $ctes['inner_consolidated'] = $this->consolidateCte('inner_offsets', $inner);
        $ctes['inner_cashflow'] = $this->innerCashFlowCte('inner_consolidated');
        $ctes['od'] = $this->overdraftConfigCte();
        $ctes['od_accrual'] = $this->overdraftAccrualCte('inner_cashflow');
        $ctes['overdraft_vj'] = $this->overdraftVjCte();
        $ctes['p08_merge_vj'] = $this->mergeVjCte('p07_cells');

        $ctes['p09_opening_bank'] = $this->openingBankCte('p08_merge_vj');
        $ctes['p10_opening_gst'] = $this->openingGstCte('p09_opening_bank');
        $ctes['p11_offsets'] = $this->offsetsCte('p10_opening_gst', $this->options);
        $ctes['p12_gst_payments'] = $this->passThrough('p11_offsets');
        $ctes['p13_merge_mapped'] = $this->mergeMappedCte('p12_gst_payments');
        $ctes['p14_cye'] = $this->currentYearEarningsCte('p13_merge_mapped');
        $ctes['p15_retained'] = $this->retainedEarningsCte('p14_cye');
        $ctes['p16_ytd'] = $this->ytdCte('p15_retained');
        $ctes['p17_contra_gst'] = $this->passThrough('p16_ytd');
        $ctes['p18_expected_sign'] = $this->expectedSignCte('p17_contra_gst');
        $ctes['p19_inverse'] = $this->inverseCte('p18_expected_sign');
        $ctes['p20_consolidate'] = $this->consolidateCte('p19_inverse', $this->options);
        $ctes['p21_dynamic_bank'] = $this->dynamicBankCte('p20_consolidate');
        $ctes['p22_hide_empty'] = $this->passThrough('p21_dynamic_bank');
        $ctes['p23_hide_accounts'] = $this->passThrough('p22_hide_empty');
        $ctes['p24_format'] = $this->formatCte('p23_hide_accounts');
        $ctes['p25_combine'] = $this->combineCte('p24_format');

        $parts = [];
        foreach ($ctes as $name => $body) {
            $materialized = $name === 'lines_by_day' ? ' MATERIALIZED' : '';
            $parts[] = "{$name} AS{$materialized} (\n{$body}\n)";
        }

        // Pipes 2–6 are the scan — lines, not cells. Project them onto
        // intervals the way pipe 7 will, so every stage diffs the same shape.
        if (in_array($stage, self::SCAN_STAGES, true)) {
            $select = "SELECT l.farm_id, l.account_id, COALESCE(m.interval_index, 1) AS interval_index, SUM(l.amount) AS amount\n"
                ."FROM ({$this->byDay($stage)}) l LEFT JOIN days m ON m.date = l.date\n"
                .'GROUP BY 1, 2, 3 ORDER BY 1, 2, 3';
        } elseif ($stage === 'p25_combine') {
            $select = 'SELECT account_id, interval_index, amount FROM p25_combine ORDER BY account_id, interval_index';
        } else {
            $select = "SELECT farm_id, account_id, interval_index, amount FROM {$stage} ORDER BY farm_id, account_id, interval_index";
        }

        return "WITH RECURSIVE\n".implode(",\n\n", $parts)."\n\n".$select;
    }

    /**
     * Lines collapsed to one row per entity, account and day before they meet
     * the month spine. A LEFT range join cannot use DuckDB's IEJoin, so
     * bucketing raw lines compares every line against every month — 500M
     * lines over 348 months is ~174 billion comparisons. Days are bounded
     * (~11k over 30 years), so the range join then runs on thousands of rows
     * at any volume.
     */
    private function byDay(string $lines): string
    {
        return "SELECT farm_id, account_id, date, SUM(amount) AS amount FROM {$lines} GROUP BY farm_id, account_id, date";
    }

    private function passThrough(string $previous): string
    {
        return "    SELECT * FROM {$previous}";
    }

    /**
     * The report's farm — the reporting-group parent for a group. Its
     * financial year is every entity's (`normaliseParentChildEndDates`).
     */
    private function farmCte(): string
    {
        return <<<SQL
            SELECT farm_id, financial_year_end_month, country_code
            FROM {$this->appAlias}.farms
            WHERE farm_id = \$farm_id
            SQL;
    }

    private function entitiesCte(): string
    {
        if (!$this->options->isReportingGroup()) {
            return 'SELECT CAST($farm_id AS VARCHAR) AS farm_id';
        }

        $rows = implode(', ', array_map(fn (string $id): string => "({$this->quote($id)})", $this->options->reportingGroupEntities));

        return "SELECT farm_id FROM (VALUES {$rows}) AS e(farm_id)";
    }

    /**
     * A predicate restricting `$column` to the report's entities. A literal
     * list rather than a join to `entities`, so DuckLake prunes other farms'
     * files from the catalog before reading any.
     */
    private function entityPredicate(string $column): string
    {
        if (!$this->options->isReportingGroup()) {
            return "{$column} = \$farm_id";
        }

        return "{$column} IN (".implode(', ', array_map($this->quote(...), $this->options->reportingGroupEntities)).')';
    }

    private function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * Every month in the period, with the financial year each belongs to —
     * the thing pipes 9, 10, 15 and 16 all reset on.
     */
    private function monthSpineCte(): string
    {
        return <<<SQL
            SELECT
                row_number() OVER (ORDER BY m) AS interval_index,
                CAST(m AS DATE) AS month_start,
                (CAST(m AS DATE) + INTERVAL 1 MONTH - INTERVAL 1 DAY)::DATE AS month_end,
                year(CAST(m AS DATE)) + CASE WHEN month(CAST(m AS DATE)) > f.financial_year_end_month THEN 1 ELSE 0 END AS fy,
                month(CAST(m AS DATE)) = (f.financial_year_end_month % 12) + 1 AS is_fy_start,
                f.financial_year_end_month
            FROM generate_series(
                CAST(\$period_from AS DATE),
                CAST(\$period_to AS DATE),
                INTERVAL 1 MONTH
            ) AS g(m)
            CROSS JOIN farm f
            SQL;
    }

    /**
     * Every day of the period with the interval it falls in — at most ~11k
     * rows over thirty years. Lines and journals meet the month spine through
     * this, on an equality, rather than by `date BETWEEN month_start AND
     * month_end` directly: DuckDB runs a LEFT range join as a nested loop, so
     * each of the three that bucket lines compared every (entity, account,
     * day) row with every month — 2.9M rows × 360 months at 50 × 10M, about
     * 5 s of CPU of the statement's 41. The range join now runs once, on the
     * day spine. Lines before the first interval (a YTD scan) find no day and
     * fall into interval 1, as they did.
     */
    private function daySpineCte(): string
    {
        return <<<SQL
            SELECT CAST(d AS DATE) AS date, m.interval_index
            FROM generate_series(
                CAST(\$period_from AS DATE),
                (SELECT MAX(month_end) FROM months),
                INTERVAL 1 DAY
            ) AS g(d)
            JOIN months m ON CAST(d AS DATE) BETWEEN m.month_start AND m.month_end
            SQL;
    }

    /**
     * The entities' lines, read once and collapsed to one row per entity,
     * account, day, basis, type and tag. Every scan below — the report's, the
     * overdraft sub-report's, the CYE and retained-earnings sub-reports' and
     * the account list — used to read transaction_lines itself, six passes
     * over the same files; 500M lines collapse to ~59k rows here, so the rest
     * read those. Bounded to the period's financial year unless retained
     * earnings needs the whole history — see readsWholeHistory().
     *
     * The LIMIT is an optimiser fence. Without it DuckDB pushes the OR of
     * every consumer's predicate into the CTE and evaluates it per line —
     * 21 s of CPU over 500M lines that all pass, since the consumers between
     * them want everything. Each consumer filters the ~59k day rows instead.
     */
    private function linesByDayCte(): string
    {
        $bound = $this->readsWholeHistory()
            ? ''
            : "AND date >= {$this->financialYearStartOfPeriod()} AND date <= CAST(\$period_to AS DATE)";

        // farm_id and basis are partition columns, constant within a file, yet
        // grouping on them hashes both strings for every line: ~0.7 s of a
        // 3.2 s pass at 1B lines. So farm_id is a literal — the parameter for
        // one farm, one UNION ALL branch per entity for a reporting group, each
        // pruned to its own farm's files — and basis a literal unless an
        // accrual report also needs the cash sub-report.
        $bases = array_values(array_unique([$this->options->basis, 'cash']));
        $basis = count($bases) === 1 ? "'{$bases[0]}' AS basis" : 'basis';
        $basisGroup = count($bases) === 1 ? '' : ', basis';
        $basisIn = "'".implode("', '", $bases)."'";

        $branch = fn (?array $ids): string => <<<SQL
            SELECT {$this->branchFarm('farm_id', $ids)} AS farm_id, account_id, date, {$basis}, type, tag, SUM(amount) AS amount
            FROM {$this->alias}.transaction_lines
            WHERE {$this->branchPredicate('farm_id', $ids)}
              AND basis IN ({$basisIn})
              {$bound}
            GROUP BY {$this->branchKey('farm_id', $ids)}account_id, date, type, tag{$basisGroup}
            SQL;

        return "SELECT * FROM (\n"
            .implode("\nUNION ALL\n", array_map($branch, $this->branches()))
            ."\n) l\nLIMIT 9223372036854775807";
    }

    /**
     * Only retained earnings needs every season the farm has; without it no
     * scan reads before the period's financial year, so the one pass stops
     * at its start.
     */
    private function readsWholeHistory(): bool
    {
        return $this->options->calculateRetained;
    }

    /**
     * The account list and the bank opening balance reach past the period on
     * both sides. They read lines_by_day when it holds the whole history, and
     * otherwise the table itself, as they did before it existed: each reads
     * one or two columns, which is cheaper than widening the one pass.
     */
    private function farmHistory(): string
    {
        return $this->readsWholeHistory() ? 'lines_by_day' : "{$this->alias}.transaction_lines";
    }

    private function financialYearStartOfPeriod(): string
    {
        return "(SELECT MIN(CASE WHEN month(CAST(\$period_from AS DATE)) > financial_year_end_month
                                THEN make_date(year(CAST(\$period_from AS DATE)), financial_year_end_month + 1, 1)
                                ELSE make_date(year(CAST(\$period_from AS DATE)) - 1, financial_year_end_month + 1, 1) END)
               FROM farm)";
    }

    /**
     * Each entity's system accounts, one per kind. A farm's own account wins
     * over a global one: in Figured every entity has its own (Xero ids are
     * per organisation), and a child entity's pipes must write to its own
     * retained earnings, not a sibling's or a shared one.
     */
    private function systemAccountsCte(): string
    {
        return <<<SQL
            SELECT farm_id, system_account, account_id FROM (
                SELECT
                    e.farm_id,
                    a.system_account,
                    a.account_id,
                    row_number() OVER (PARTITION BY e.farm_id, a.system_account ORDER BY a.farm_id IS NULL, a.account_id) AS rn
                FROM entities e
                JOIN {$this->appAlias}.accounts a
                  ON a.system_account IS NOT NULL AND (a.farm_id IS NULL OR a.farm_id = e.farm_id)
            ) s
            WHERE rn = 1
            SQL;
    }

    /**
     * Every account each entity has ever posted to. Distinct on account alone
     * per entity, then the entity as a constant: see linesByDayCte().
     */
    private function postedAccountsCte(): string
    {
        $branch = fn (?array $ids): string => <<<SQL
            SELECT {$this->branchFarm('farm_id', $ids)} AS farm_id, account_id FROM (
                SELECT DISTINCT {$this->branchKey('tl.farm_id', $ids)}tl.account_id
                FROM {$this->farmHistory()} tl
                WHERE {$this->branchPredicate('tl.farm_id', $ids)}
            ) x
            SQL;

        return implode("\nUNION ALL\n", array_map($branch, $this->branches()));
    }

    /**
     * The entities each branch of the pass covers: null for a single farm,
     * which binds the parameter; otherwise the reporting group split in two.
     *
     * A branch of one entity filters and tags with a literal `farm_id` and
     * never hashes it; a branch of several groups by it. Measured over 1 to
     * 50 branches on three groups — 50 × 10M, 10 × 1M, and a 1B beside a 500M
     * child — two was fastest every time, and every branch past two slower
     * (50 × 10M, twelve months: 1,500 ms as one grouped scan, 1,338 ms as two
     * branches, 1,461 as three, 1,984 as fifty). Why two and not more is not
     * established; the count is the measurement, not a model of DuckDB's
     * scheduler. `$entitiesPerBranch` overrides it, for measuring again.
     *
     * @return list<list<string>|null>
     */
    private function branches(): array
    {
        if (!$this->options->isReportingGroup()) {
            return [null];
        }

        $entities = $this->options->reportingGroupEntities;
        $size = $this->entitiesPerBranch ?? (int) ceil(count($entities) / 2);

        return array_chunk($entities, max(1, $size));
    }

    /** @param list<string>|null $ids */
    private function branchPredicate(string $column, ?array $ids): string
    {
        return match (true) {
            $ids === null => "{$column} = \$farm_id",
            count($ids) === 1 => "{$column} = {$this->quote($ids[0])}",
            default => "{$column} IN (".implode(', ', array_map($this->quote(...), $ids)).')',
        };
    }

    /** @param list<string>|null $ids */
    private function branchFarm(string $column, ?array $ids): string
    {
        return match (true) {
            $ids === null => 'CAST($farm_id AS VARCHAR)',
            count($ids) === 1 => "CAST({$this->quote($ids[0])} AS VARCHAR)",
            default => $column,
        };
    }

    /** The grouping key a branch needs for `farm_id`: none when it holds one entity. */
    private function branchKey(string $column, ?array $ids): string
    {
        return $ids !== null && count($ids) > 1 ? "{$column}, " : '';
    }

    /**
     * The report's account list per entity: PrepareEmptyArray zeroes a cell
     * for each.
     *
     * Figured is handed the list by the structure builder. Here it is every
     * account the entity has ever posted to, plus the Xero accounts its
     * internal accounts fold into, plus the system accounts the pipes write to.
     */
    private function reportAccountsCte(): string
    {
        return <<<SQL
            SELECT DISTINCT farm_id, account_id FROM (
                SELECT farm_id, account_id FROM posted_accounts
                UNION ALL
                SELECT p.farm_id, a.mapped_to_account_id
                FROM posted_accounts p
                JOIN {$this->appAlias}.accounts a ON a.account_id = p.account_id
                WHERE a.mapped_to_account_id IS NOT NULL
                UNION ALL
                SELECT farm_id, account_id FROM sys_accounts
            ) x
            SQL;
    }

    /**
     * The accounts each pipe writes to, per entity. The default bank is chosen
     * among the entity's own report accounts, farm-specific first: several
     * farms' seeds leave a global default bank the entity never posts to.
     */
    private function entityAccountsCte(): string
    {
        return <<<SQL
            WITH bank AS (
                SELECT farm_id, account_id FROM (
                    SELECT
                        ra.farm_id,
                        ra.account_id,
                        row_number() OVER (PARTITION BY ra.farm_id ORDER BY a.farm_id IS NULL, ra.account_id) AS rn
                    FROM report_accounts ra
                    JOIN {$this->appAlias}.accounts a ON a.account_id = ra.account_id
                    WHERE a.is_default_bank_account
                ) b
                WHERE rn = 1
            )
            SELECT
                e.farm_id,
                MAX(CASE WHEN s.system_account = 'GST' THEN s.account_id END) AS gst_id,
                MAX(CASE WHEN s.system_account = 'GSTPAYMENTS' THEN s.account_id END) AS payments_id,
                MAX(CASE WHEN s.system_account = 'RETAINED_EARNINGS' THEN s.account_id END) AS re_id,
                MAX(CASE WHEN s.system_account = 'CURRENT_YEAR_EARNINGS' THEN s.account_id END) AS cye_id,
                MAX(CASE WHEN s.system_account = 'LIABILITY' THEN s.account_id END) AS liability_id,
                MAX(CASE WHEN s.system_account = 'OVERDRAFT' THEN s.account_id END) AS overdraft_id,
                MAX(b.account_id) AS bank_id
            FROM entities e
            LEFT JOIN sys_accounts s ON s.farm_id = e.farm_id
            LEFT JOIN bank b ON b.farm_id = e.farm_id
            GROUP BY e.farm_id
            SQL;
    }

    private function p01Empty(): string
    {
        return <<<SQL
            SELECT ra.farm_id, ra.account_id, m.interval_index, CAST(0 AS BIGINT) AS amount
            FROM report_accounts ra
            CROSS JOIN months m
            SQL;
    }

    /**
     * Pipes 2, 5, 6: the Mongo `$match`, as a predicate.
     *
     * YTD widens the scan back to the start of the period's first financial
     * year; those earlier lines fall into the first interval (Figured's
     * `getUpdatedIntervalStart`). The horizon split is the same one every
     * report here uses. EOY exclusion is a tag `$nin`.
     */
    private function scanCte(PipelineOptions $o): string
    {
        $from = $o->ytd ? $this->financialYearStartOfPeriod() : 'CAST($period_from AS DATE)';

        $eoy = $o->excludeEoyJournals
            ? "AND (tl.tag IS NULL OR tl.tag <> '".self::TAG_EOY."')"
            : '';

        return <<<SQL
            SELECT
                tl.farm_id,
                tl.account_id,
                tl.date,
                tl.amount,
                tl.tag,
                tl.type
            FROM lines_by_day tl
            WHERE tl.basis = '{$o->basis}'
              AND tl.date >= {$from}
              AND tl.date <= CAST(\$period_to AS DATE)
              AND (
                    (tl.date <= CAST(\$horizon AS DATE) AND tl.type = 'actuals')
                 OR (tl.date >  CAST(\$horizon AS DATE) AND tl.type = 'forecast')
              )
              {$eoy}
            SQL;
    }

    /**
     * Pipe 7: results onto the empty array. Lines before the first interval
     * (a YTD scan) land in it.
     */
    private function cellsCte(string $empty, string $scan): string
    {
        return <<<SQL
            SELECT
                e.farm_id,
                e.account_id,
                e.interval_index,
                e.amount + COALESCE(s.amount, 0) AS amount
            FROM {$empty} e
            LEFT JOIN (
                SELECT
                    l.farm_id,
                    l.account_id,
                    COALESCE(m.interval_index, 1) AS interval_index,
                    SUM(l.amount) AS amount
                FROM ({$this->byDay($scan)}) l
                LEFT JOIN days m ON m.date = l.date
                GROUP BY 1, 2, 3
            ) s ON s.farm_id = e.farm_id AND s.account_id = e.account_id AND s.interval_index = e.interval_index
            SQL;
    }

    /**
     * The GST payments/refunds virtual journal (priority 145).
     *
     * NZ, two-monthly, payments basis: a settlement on the 28th of each
     * payment month covering the two calendar months before it. Three journal
     * kinds, as `createJournalsForGstMovements` emits them:
     *
     * 1. the predicted payment, on the payments/refunds account at the payment
     *    date — minus any settlement already made inside that window, so an
     *    actual payment is not also predicted;
     * 2. a reversal on the GST account for each actual settlement, so the net
     *    GST line shows tax components only;
     * 3. the actual settlement itself moved onto the payments line.
     *
     * Sign: net GST owed is credit-negative; a payment is a cash outflow, so
     * the predicted amount is the window's net negated.
     */
    private function gstPaymentsRefundsVjCte(): string
    {
        $pay = self::TAG_GST_PAYMENT;

        return <<<SQL
            WITH gst_accounts AS (
                SELECT farm_id, gst_id, payments_id FROM entity_accounts
                WHERE gst_id IS NOT NULL AND payments_id IS NOT NULL
            ),
            -- Net GST per calendar month, tax components only, across the
            -- whole scan (the window for a July payment reaches back to May).
            net_by_month AS (
                SELECT l.farm_id, date_trunc('month', l.date)::DATE AS ms, SUM(l.amount) AS net
                FROM inner_scan l
                JOIN gst_accounts g ON g.farm_id = l.farm_id AND l.account_id = g.gst_id
                WHERE l.tag IS NULL OR l.tag <> '{$pay}'
                GROUP BY 1, 2
            ),
            settlements AS (
                SELECT l.farm_id, l.date, date_trunc('month', l.date)::DATE AS ms, l.amount
                FROM inner_scan l
                JOIN gst_accounts g ON g.farm_id = l.farm_id AND l.account_id = g.gst_id
                WHERE l.tag = '{$pay}'
            ),
            -- Payment dates in the period: 28th of the payment months; the
            -- December and April payments are pushed to 15 Jan and 7 May.
            payment_dates AS (
                SELECT
                    CASE
                        WHEN month(m.month_start) = 1 AND (SELECT financial_year_end_month FROM farm) % 2 = 1 THEN make_date(year(m.month_start), 1, 15)
                        WHEN month(m.month_start) = 5 AND (SELECT financial_year_end_month FROM farm) % 2 = 1 THEN make_date(year(m.month_start), 5, 7)
                        ELSE make_date(year(m.month_start), month(m.month_start), 28)
                    END AS pay_date,
                    (m.month_start - INTERVAL 2 MONTH)::DATE AS window_start,
                    (m.month_start - INTERVAL 1 DAY)::DATE AS window_end
                FROM months m
                WHERE (month(m.month_start) % 2) = ((SELECT financial_year_end_month FROM farm) + 1) % 2
            ),
            predicted AS (
                SELECT
                    g.farm_id,
                    g.payments_id AS account_id,
                    pd.pay_date AS date,
                    -(COALESCE((SELECT SUM(net) FROM net_by_month n WHERE n.farm_id = g.farm_id AND n.ms BETWEEN pd.window_start AND pd.window_end), 0))
                    - COALESCE((SELECT SUM(amount) FROM settlements s WHERE s.farm_id = g.farm_id AND s.date BETWEEN pd.window_start AND pd.window_end), 0) AS amount
                FROM payment_dates pd, gst_accounts g
            )
            SELECT farm_id, account_id, date, amount FROM predicted WHERE amount <> 0
            UNION ALL
            SELECT s.farm_id, g.gst_id, s.date, -s.amount FROM settlements s JOIN gst_accounts g ON g.farm_id = s.farm_id
            UNION ALL
            SELECT s.farm_id, g.payments_id, s.date, s.amount FROM settlements s JOIN gst_accounts g ON g.farm_id = s.farm_id
            SQL;
    }

    /**
     * `MergeVirtualJournals` for one handler: add each journal to the cell of
     * the interval its date falls in. Journals outside the period are dropped,
     * as Figured drops them.
     */
    private function addJournalsCte(string $cells, string $journals): string
    {
        return <<<SQL
            SELECT
                c.farm_id,
                c.account_id,
                c.interval_index,
                c.amount + COALESCE(j.amount, 0) AS amount
            FROM {$cells} c
            LEFT JOIN (
                SELECT j.farm_id, j.account_id, m.interval_index, SUM(j.amount) AS amount
                FROM {$journals} j
                JOIN days m ON m.date = j.date
                GROUP BY 1, 2, 3
            ) j ON j.farm_id = c.farm_id AND j.account_id = c.account_id AND j.interval_index = c.interval_index
            SQL;
    }

    /**
     * The overdraft handler's sub-report: `OverdraftCashFlowStructureBuilder`.
     *
     * `net_cash_movement = income - expense + gst`, with bank accounts captured
     * into a hidden section and excluded, and GST its own inverted section.
     * Opening balance is `Balance::getReport()`'s actuals/forecast branch —
     * the bank accounts' own balance up to the day before the period.
     */
    private function innerCashFlowCte(string $cells): string
    {
        return <<<SQL
            WITH classified AS (
                SELECT
                    c.farm_id,
                    c.interval_index,
                    c.amount,
                    a.account_class,
                    a.account_type,
                    a.system_account,
                    a.is_gst_account
                FROM {$cells} c
                JOIN {$this->appAlias}.accounts a ON a.account_id = c.account_id
            ),
            movement AS (
                SELECT
                    farm_id,
                    interval_index,
                    -- income: revenue is credit-negative, so negate; expense:
                    -- everything else that is not bank and not gst, debit-positive.
                    -SUM(CASE WHEN account_class = 'REVENUE' THEN amount ELSE 0 END)
                    - SUM(CASE WHEN account_class <> 'REVENUE'
                                AND COALESCE(account_type, '') <> 'BANK'
                                AND NOT is_gst_account
                                AND COALESCE(system_account, '') NOT IN ('GST', 'GSTPAYMENTS')
                               THEN amount ELSE 0 END)
                    - SUM(CASE WHEN is_gst_account OR COALESCE(system_account, '') IN ('GST', 'GSTPAYMENTS')
                               THEN amount ELSE 0 END) AS net_cash_movement
                FROM classified
                GROUP BY 1, 2
            ),
            opening AS (
                SELECT tl.farm_id, SUM(tl.amount) AS opening_balance
                FROM {$this->farmHistory()} tl
                JOIN {$this->appAlias}.accounts a ON a.account_id = tl.account_id
                WHERE {$this->entityPredicate('tl.farm_id')}
                  AND tl.basis = 'cash'
                  AND a.account_type = 'BANK'
                  AND tl.date < CAST(\$period_from AS DATE)
                  AND (
                        (tl.date <= CAST(\$horizon AS DATE) AND tl.type = 'actuals')
                     OR (tl.date >  CAST(\$horizon AS DATE) AND tl.type = 'forecast')
                  )
                GROUP BY tl.farm_id
            )
            SELECT
                mv.farm_id,
                m.interval_index,
                m.month_end,
                COALESCE(o.opening_balance, 0)
                    + COALESCE(SUM(mv.net_cash_movement) OVER (PARTITION BY mv.farm_id ORDER BY m.interval_index
                        ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING), 0) AS opening,
                mv.net_cash_movement,
                COALESCE(o.opening_balance, 0)
                    + SUM(mv.net_cash_movement) OVER (PARTITION BY mv.farm_id ORDER BY m.interval_index
                        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS closing
            FROM movement mv
            JOIN months m ON m.interval_index = mv.interval_index
            LEFT JOIN opening o ON o.farm_id = mv.farm_id
            SQL;
    }

    /**
     * The overdraft in force for the period, per entity — the latest row
     * starting on or before the period end, as Phase 3 resolves it.
     */
    private function overdraftConfigCte(): string
    {
        return <<<SQL
            SELECT farm_id, rate FROM (
                SELECT o.farm_id, o.rate, row_number() OVER (PARTITION BY o.farm_id ORDER BY o.start_date DESC) AS rn
                FROM {$this->appAlias}.overdrafts o
                WHERE {$this->entityPredicate('o.farm_id')} AND o.start_date <= CAST(\$period_to AS DATE)
            ) x
            WHERE rn = 1
            SQL;
    }

    /**
     * The overdraft handler (priority 150): Phase 3's recurrence, unchanged,
     * over the inner cash flow's closing row. A top-level CTE rather than a
     * nested one because `WITH RECURSIVE` only reaches the outermost chain —
     * the first version nested it and DuckDB refused the self-reference.
     * Every entity's recurrence advances in the same iteration, joined on
     * `farm_id`: months deep, not entities × months. Monthly term only.
     */
    private function overdraftAccrualCte(string $cashflow): string
    {
        return <<<SQL
            SELECT
                cf.farm_id,
                cf.interval_index,
                cf.month_end,
                CAST(cf.closing AS DOUBLE) AS principal,
                CASE WHEN cf.closing < 0
                     THEN -cf.closing * (od.rate / 10000.0 / 100.0) / 12.0
                     ELSE 0 END AS interest,
                CASE WHEN cf.closing < 0
                     THEN -cf.closing * (od.rate / 10000.0 / 100.0) / 12.0
                     ELSE 0 END AS cum
            FROM {$cashflow} cf
            JOIN od ON od.farm_id = cf.farm_id
            WHERE cf.interval_index = 1
            UNION ALL
            SELECT
                cf.farm_id,
                cf.interval_index,
                cf.month_end,
                cf.closing - a.cum AS principal,
                CASE WHEN (cf.closing - a.cum) < 0
                     THEN -(cf.closing - a.cum) * (od.rate / 10000.0 / 100.0) / 12.0
                     ELSE 0 END AS interest,
                a.cum + CASE WHEN (cf.closing - a.cum) < 0
                             THEN -(cf.closing - a.cum) * (od.rate / 10000.0 / 100.0) / 12.0
                             ELSE 0 END AS cum
            FROM od_accrual a
            JOIN {$cashflow} cf ON cf.farm_id = a.farm_id AND cf.interval_index = a.interval_index + 1
            JOIN od ON od.farm_id = a.farm_id
            SQL;
    }

    /** The accrual as journals on each entity's overdraft account, posted at each interval's end. */
    private function overdraftVjCte(): string
    {
        return <<<SQL
            SELECT
                a.farm_id,
                ea.overdraft_id AS account_id,
                a.month_end AS date,
                CAST(floor(a.interest) AS BIGINT) AS amount
            FROM od_accrual a
            JOIN entity_accounts ea ON ea.farm_id = a.farm_id
            WHERE a.interest > 0 AND ea.overdraft_id IS NOT NULL
            SQL;
    }

    /**
     * Pipe 8 proper: both handlers' journals onto the outer cells, filtered
     * to the report's basis (both handlers emit per basis; the PoC lake is
     * one basis per line, so the filter is the scan's).
     */
    private function mergeVjCte(string $cells): string
    {
        return <<<SQL
            SELECT
                c.farm_id,
                c.account_id,
                c.interval_index,
                c.amount + COALESCE(j.amount, 0) AS amount
            FROM {$cells} c
            LEFT JOIN (
                SELECT j.farm_id, j.account_id, m.interval_index, SUM(j.amount) AS amount
                FROM (
                    SELECT farm_id, account_id, date, amount FROM gst_vj
                    UNION ALL
                    SELECT farm_id, account_id, date, amount FROM overdraft_vj
                ) j
                JOIN days m ON m.date = j.date
                GROUP BY 1, 2, 3
            ) j ON j.farm_id = c.farm_id AND j.account_id = c.account_id AND j.interval_index = c.interval_index
            SQL;
    }

    /**
     * Pipe 9: budget periods only. Opening bank onto the default bank account
     * and its contra onto retained earnings, first interval of each financial
     * year in the period.
     */
    private function openingBankCte(string $previous): string
    {
        if ($this->options->type !== 'budget') {
            return $this->passThrough($previous);
        }

        return <<<SQL
            SELECT
                p.farm_id,
                p.account_id,
                p.interval_index,
                p.amount
                    + CASE WHEN p.account_id = ea.bank_id AND fy_first.interval_index IS NOT NULL THEN COALESCE(ob.opening_bank, 0)
                           WHEN p.account_id = ea.re_id AND fy_first.interval_index IS NOT NULL THEN -COALESCE(ob.opening_bank, 0)
                           ELSE 0 END AS amount
            FROM {$previous} p
            JOIN entity_accounts ea ON ea.farm_id = p.farm_id
            JOIN months m ON m.interval_index = p.interval_index
            LEFT JOIN (
                SELECT fy, MIN(interval_index) AS interval_index FROM months GROUP BY fy
            ) fy_first ON fy_first.fy = m.fy AND fy_first.interval_index = p.interval_index
            LEFT JOIN {$this->appAlias}.opening_balances ob ON ob.farm_id = p.farm_id AND ob.financial_year = m.fy
            SQL;
    }

    /**
     * Pipe 10: budget, YTD, `includeOpeningBudgetGst`. Opening GST onto the
     * GST account, contra onto retained earnings, on each FY's first month
     * (only when the period actually starts a financial year).
     */
    private function openingGstCte(string $previous): string
    {
        $o = $this->options;
        if ($o->type !== 'budget' || !$o->ytd || !$o->includeOpeningBudgetGst) {
            return $this->passThrough($previous);
        }

        return <<<SQL
            SELECT
                p.farm_id,
                p.account_id,
                p.interval_index,
                p.amount
                    + CASE WHEN p.account_id = ea.gst_id AND m.is_fy_start THEN COALESCE(ob.opening_gst, 0)
                           WHEN p.account_id = ea.re_id AND m.is_fy_start THEN -COALESCE(ob.opening_gst, 0)
                           ELSE 0 END AS amount
            FROM {$previous} p
            JOIN entity_accounts ea ON ea.farm_id = p.farm_id
            JOIN months m ON m.interval_index = p.interval_index
            LEFT JOIN {$this->appAlias}.opening_balances ob ON ob.farm_id = p.farm_id AND ob.financial_year = m.fy
            SQL;
    }

    /**
     * Pipe 11: inter-entity transfers (`MergedAccount`). Reporting groups only.
     *
     * The `to` account gains the `from` entity's balance for the `from`
     * account; the `from` account is zeroed, and so is every internal account
     * mapped onto it, because pipe 13 has not folded them yet.
     *
     * Figured takes the balance from a nested `AccountBalances` report on the
     * `from` farm with offsets, consolidation, YTD accumulation, expected sign
     * and inverse all off. On a transfer account — balance sheet, not a system
     * account — the only pipes left that could move it before pipe 11 are
     * 1–10 and the fold at 13, so it is that entity's own cells at this stage
     * with its mapped accounts summed in. Two known differences from Figured,
     * neither exercised by the seed: that nested run takes the `from` farm's
     * own financial year, not the parent's; and transfers are applied at once
     * rather than in Figured's loop order, which only differs when an account
     * is both a `from` and a `to`.
     */
    private function offsetsCte(string $previous, PipelineOptions $o): string
    {
        if (!$o->isReportingGroup() || !$o->mergedAccounts) {
            return $this->passThrough($previous);
        }

        return <<<SQL
            WITH transfers AS (
                SELECT from_farm_id, from_account_id, to_farm_id, to_account_id
                FROM {$this->appAlias}.merged_accounts
                WHERE parent_farm_id = \$farm_id
                  AND {$this->entityPredicate('from_farm_id')}
                  AND {$this->entityPredicate('to_farm_id')}
            ),
            -- The from side: only when the from account is in that entity's list.
            sources AS (
                SELECT t.*, p.account_id AS source_account_id
                FROM transfers t
                JOIN (SELECT DISTINCT farm_id, account_id FROM {$previous}) p ON p.farm_id = t.from_farm_id
                JOIN {$this->appAlias}.accounts a ON a.account_id = p.account_id
                WHERE (p.account_id = t.from_account_id OR a.mapped_to_account_id = t.from_account_id)
                  AND EXISTS (SELECT 1 FROM {$previous} q WHERE q.farm_id = t.from_farm_id AND q.account_id = t.from_account_id)
            ),
            from_balance AS (
                SELECT s.to_farm_id AS farm_id, s.to_account_id AS account_id, p.interval_index, SUM(p.amount) AS amount
                FROM sources s
                JOIN {$previous} p ON p.farm_id = s.from_farm_id AND p.account_id = s.source_account_id
                GROUP BY 1, 2, 3
            ),
            zeroed AS (
                SELECT DISTINCT from_farm_id AS farm_id, source_account_id AS account_id FROM sources
            )
            SELECT
                p.farm_id,
                p.account_id,
                p.interval_index,
                CASE WHEN z.account_id IS NOT NULL THEN 0 ELSE p.amount + COALESCE(fb.amount, 0) END AS amount
            FROM {$previous} p
            LEFT JOIN zeroed z ON z.farm_id = p.farm_id AND z.account_id = p.account_id
            LEFT JOIN from_balance fb ON fb.farm_id = p.farm_id AND fb.account_id = p.account_id AND fb.interval_index = p.interval_index
            SQL;
    }

    /**
     * Pipe 13: each internal account's cells onto the Xero account it maps
     * to, then the internal row dropped. Always on.
     */
    private function mergeMappedCte(string $previous): string
    {
        return <<<SQL
            SELECT
                p.farm_id,
                COALESCE(a.mapped_to_account_id, p.account_id) AS account_id,
                p.interval_index,
                SUM(p.amount) AS amount
            FROM {$previous} p
            JOIN {$this->appAlias}.accounts a ON a.account_id = p.account_id
            GROUP BY 1, 2, 3
            SQL;
    }

    /**
     * Pipe 14: a nested YTD sub-report of `allincome - allexpenses`, its
     * total inverted and written onto the current-year-earnings account.
     * The sub-report's scan is the outer one widened to the FY start.
     */
    private function currentYearEarningsCte(string $previous): string
    {
        if (!$this->options->calculateCurrentYearEarnings) {
            return $this->passThrough($previous);
        }

        $ytdScan = $this->scanCte($this->options->withYtd());

        return <<<SQL
            WITH cye_scan AS (
            {$ytdScan}
            ),
            cye_by_month AS (
                SELECT
                    l.farm_id,
                    COALESCE(m.interval_index, 1) AS interval_index,
                    SUM(CASE WHEN a.account_class = 'REVENUE' THEN l.amount ELSE 0 END) AS income,
                    SUM(CASE WHEN a.account_class = 'EXPENSE' THEN l.amount ELSE 0 END) AS expenses
                FROM ({$this->byDay('cye_scan')}) l
                JOIN {$this->appAlias}.accounts a ON a.account_id = l.account_id
                LEFT JOIN days m ON m.date = l.date
                GROUP BY 1, 2
            ),
            cye AS (
                SELECT
                    e.farm_id,
                    m.interval_index,
                    -(SUM(COALESCE(b.income, 0)) OVER (PARTITION BY e.farm_id ORDER BY m.interval_index ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
                      - SUM(COALESCE(b.expenses, 0)) OVER (PARTITION BY e.farm_id ORDER BY m.interval_index ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)) AS amount
                FROM entities e
                CROSS JOIN months m
                LEFT JOIN cye_by_month b ON b.farm_id = e.farm_id AND b.interval_index = m.interval_index
            )
            SELECT
                p.farm_id,
                p.account_id,
                p.interval_index,
                CASE WHEN p.account_id = ea.cye_id THEN c.amount ELSE p.amount END AS amount
            FROM {$previous} p
            JOIN entity_accounts ea ON ea.farm_id = p.farm_id
            LEFT JOIN cye c ON c.farm_id = p.farm_id AND c.interval_index = p.interval_index
            SQL;
    }

    /**
     * Pipe 15: retained earnings. A nested yearly sub-report of net profit
     * per season from the farm's first transaction to the period's last
     * season; at each interval, the seasons before its FY are summed and
     * inverted, added to a running total of what was posted directly to the
     * RE account. Mirrors `RetainedEarnings::handle()` term for term.
     */
    private function retainedEarningsCte(string $previous): string
    {
        if (!$this->options->calculateRetained) {
            return $this->passThrough($previous);
        }

        $eoy = $this->options->excludeEoyJournals
            ? "AND (tl.tag IS NULL OR tl.tag <> '".self::TAG_EOY."')"
            : '';

        return <<<SQL
            WITH season_profit AS (
                SELECT
                    tl.farm_id,
                    year(tl.date) + CASE WHEN month(tl.date) > f.financial_year_end_month THEN 1 ELSE 0 END AS season,
                    SUM(CASE WHEN a.account_class = 'REVENUE' THEN tl.amount ELSE 0 END)
                      - SUM(CASE WHEN a.account_class = 'EXPENSE' THEN tl.amount ELSE 0 END) AS net_profit
                FROM lines_by_day tl
                JOIN {$this->appAlias}.accounts a ON a.account_id = tl.account_id
                CROSS JOIN farm f
                WHERE tl.basis = '{$this->options->basis}'
                  AND tl.date <= CAST(\$period_to AS DATE)
                  AND (
                        (tl.date <= CAST(\$horizon AS DATE) AND tl.type = 'actuals')
                     OR (tl.date >  CAST(\$horizon AS DATE) AND tl.type = 'forecast')
                  )
                  {$eoy}
                GROUP BY 1, 2
            ),
            per_interval AS (
                SELECT
                    e.farm_id,
                    m.interval_index,
                    COALESCE((SELECT SUM(net_profit) FROM season_profit s WHERE s.farm_id = e.farm_id AND s.season < m.fy), 0) AS retained_before_fy
                FROM entities e
                CROSS JOIN months m
            ),
            re_running AS (
                SELECT
                    p.farm_id,
                    p.interval_index,
                    SUM(p.amount) OVER (PARTITION BY p.farm_id ORDER BY p.interval_index ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS posted_running
                FROM {$previous} p
                JOIN entity_accounts ea ON ea.farm_id = p.farm_id
                WHERE p.account_id = ea.re_id
            )
            SELECT
                p.farm_id,
                p.account_id,
                p.interval_index,
                CASE WHEN p.account_id = ea.re_id
                     THEN CAST(r.posted_running - pi.retained_before_fy AS BIGINT)
                     ELSE p.amount END AS amount
            FROM {$previous} p
            JOIN entity_accounts ea ON ea.farm_id = p.farm_id
            LEFT JOIN per_interval pi ON pi.farm_id = p.farm_id AND pi.interval_index = p.interval_index
            LEFT JOIN re_running r ON r.farm_id = p.farm_id AND r.interval_index = p.interval_index
            SQL;
    }

    /**
     * Pipe 16: YTD — a running sum per account across intervals, reset at
     * each season start when `ytd_type = season`; retained and current-year
     * earnings are skipped because they are already balances.
     */
    private function ytdCte(string $previous): string
    {
        if (!$this->options->ytd) {
            return $this->passThrough($previous);
        }

        $partition = $this->options->ytdType === 'season' ? 'p.farm_id, p.account_id, m.fy' : 'p.farm_id, p.account_id';

        return <<<SQL
            SELECT
                p.farm_id,
                p.account_id,
                p.interval_index,
                CASE WHEN p.account_id = ea.re_id OR p.account_id = ea.cye_id THEN p.amount
                     ELSE SUM(p.amount) OVER (PARTITION BY {$partition} ORDER BY p.interval_index
                                              ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
                END AS amount
            FROM {$previous} p
            JOIN entity_accounts ea ON ea.farm_id = p.farm_id
            JOIN months m ON m.interval_index = p.interval_index
            SQL;
    }

    /** Pipe 18. */
    private function expectedSignCte(string $previous): string
    {
        if (!$this->options->showExpectedSign) {
            return $this->passThrough($previous);
        }

        return <<<SQL
            SELECT
                p.farm_id,
                p.account_id,
                p.interval_index,
                CASE WHEN a.inverted_for_user THEN -p.amount ELSE p.amount END AS amount
            FROM {$previous} p
            JOIN {$this->appAlias}.accounts a ON a.account_id = p.account_id
            SQL;
    }

    /** Pipe 19. */
    private function inverseCte(string $previous): string
    {
        if (!$this->options->inverse) {
            return $this->passThrough($previous);
        }

        return "    SELECT farm_id, account_id, interval_index, -amount AS amount FROM {$previous}";
    }

    /**
     * Pipe 20: several child accounts presented as one (`ConsolidatedAccounts`).
     * Reporting groups only. The old account's cells are zeroed — the row
     * stays — and added onto the new account, created if the entity has no
     * row for it. Old account ids are per entity, as Xero's are, so the
     * mapping needs no farm column to know whose account it moves.
     */
    private function consolidateCte(string $previous, PipelineOptions $o): string
    {
        if (!$o->isReportingGroup() || !$o->consolidateAccounts) {
            return $this->passThrough($previous);
        }

        return <<<SQL
            WITH consolidations AS (
                SELECT DISTINCT old_account_id, new_account_id
                FROM {$this->appAlias}.consolidated_accounts
                WHERE parent_farm_id = \$farm_id
            )
            SELECT farm_id, account_id, interval_index, SUM(amount) AS amount FROM (
                SELECT
                    p.farm_id,
                    p.account_id,
                    p.interval_index,
                    CASE WHEN c.old_account_id IS NOT NULL THEN 0 ELSE p.amount END AS amount
                FROM {$previous} p
                LEFT JOIN consolidations c ON c.old_account_id = p.account_id
                UNION ALL
                SELECT p.farm_id, c.new_account_id, p.interval_index, p.amount
                FROM {$previous} p
                JOIN consolidations c ON c.old_account_id = p.account_id
            ) x
            GROUP BY 1, 2, 3
            SQL;
    }

    /**
     * Pipe 21: a negative default-bank cell becomes zero, and the Figured
     * liability account carries its inverse. Off for reporting groups:
     * "Farms can have different default bank accounts and having combined
     * Liability line is not an option" (`DynamicBankBalance::shouldHandle`).
     */
    private function dynamicBankCte(string $previous): string
    {
        if (!$this->options->dynamicBankAccount || $this->options->isReportingGroup()) {
            return $this->passThrough($previous);
        }

        return <<<SQL
            WITH applies AS (
                SELECT ea.farm_id, ea.bank_id, ea.liability_id
                FROM entity_accounts ea
                WHERE ea.bank_id IS NOT NULL AND ea.liability_id IS NOT NULL
            ),
            bank_negatives AS (
                SELECT p.farm_id, p.interval_index, p.amount
                FROM {$previous} p
                JOIN applies x ON x.farm_id = p.farm_id AND p.account_id = x.bank_id
                WHERE p.amount < 0
            )
            SELECT
                p.farm_id,
                p.account_id,
                p.interval_index,
                CASE WHEN p.account_id = x.bank_id AND p.amount < 0 THEN 0
                     WHEN p.account_id = x.liability_id THEN -COALESCE(bn.amount, 0)
                     ELSE p.amount END AS amount
            FROM {$previous} p
            LEFT JOIN applies x ON x.farm_id = p.farm_id
            LEFT JOIN bank_negatives bn ON bn.farm_id = p.farm_id AND bn.interval_index = p.interval_index
            SQL;
    }

    /** Pipe 24: last, always. */
    private function formatCte(string $previous): string
    {
        $fp = self::FIXED_POINT;

        return "    SELECT farm_id, account_id, interval_index, amount / {$fp}.0 AS amount FROM {$previous}";
    }

    /**
     * `CombineReports`: each entity's finished report summed by account and
     * interval. Outside `DataPipeline` in Figured — it runs on the parent
     * after every child's response is back. For one entity it changes nothing.
     */
    private function combineCte(string $previous): string
    {
        return "    SELECT account_id, interval_index, SUM(amount) AS amount FROM {$previous} GROUP BY account_id, interval_index";
    }
}
