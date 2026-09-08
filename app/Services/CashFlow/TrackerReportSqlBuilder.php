<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use App\Services\CashFlow\Definition\ReportSection;
use App\Services\CashFlow\Definition\SectionAmountExpression;
use App\Services\CashFlow\Definition\TrackerCashFlowReportDefinition;

/**
 * Turns the tracker Cash Flow definition into one DuckDB query.
 *
 * The claim this file exists to make good on: **tracker count is a GROUP BY
 * cardinality, not a query multiplier.** The generated SQL is byte-identical
 * whether the farm has 1 tracker or 50 — nothing in here interpolates a
 * tracker id, a tracker count, or a per-tracker formula. Compare against
 * Figured, where `LivestockStructureBuilder` loops per tracker to build
 * sections and `LivestockQuantities` issues a query per tracker.
 *
 * `report_lines` is declared MATERIALIZED on purpose. It is read twice — once
 * grouped by (month, tracker), once grouped by month over untagged lines —
 * and without the hint DuckDB is free to inline the CTE and scan the fact
 * table twice, which would quietly undermine the single-scan claim this
 * report is meant to demonstrate. The hint makes the intent explicit rather
 * than depending on the optimiser choosing well.
 */
final class TrackerReportSqlBuilder
{
    /** Matches Figured's TEN_THOUSAND fixed-point convention. */
    private const int FIXED_POINT = 10000;

    public function __construct(
        private readonly TrackerCashFlowReportDefinition $definition,
        /** The DuckLake catalog — fact data. */
        private readonly string $alias,
        /** The attached MySQL database — dimension data. */
        private readonly string $appAlias,
    ) {
    }

    public function build(): string
    {
        $ctes = [
            'months' => $this->monthSpineCte(),
            'report_lines' => $this->reportLinesCte(),
            'tracker_months' => $this->trackerMonthsCte(),
        ];

        // Per-tracker derived rows, evaluated inside each (month, tracker)
        // group. One CTE per row, same chaining as the consolidated side.
        $previous = 'tracker_months';
        foreach ($this->definition->trackerCalculationRows() as $row) {
            $name = 'tracker_calc_'.$row->field;

            $ctes[$name] = sprintf(
                "    SELECT *, %s AS %s\n    FROM %s",
                $row->formula,
                $row->field,
                $previous,
            );

            $previous = $name;
        }

        $ctes['tracker_rollup'] = $this->trackerRollupCte($previous);
        $ctes['farm_months'] = $this->farmMonthsCte();
        $ctes['sections'] = $this->sectionsCte();

        $previous = 'sections';
        foreach ($this->definition->calculationRows() as $row) {
            $name = 'calc_'.$row->field;

            $ctes[$name] = sprintf(
                "    SELECT *, %s AS %s\n    FROM %s",
                $row->formula,
                $row->field,
                $previous,
            );

            $previous = $name;
        }

        $ctes['with_balances'] = $this->runningBalanceCte($previous);

        $parts = [];
        foreach ($ctes as $name => $body) {
            $materialized = $name === 'report_lines' ? ' AS MATERIALIZED' : ' AS';
            $parts[] = "{$name}{$materialized} (\n{$body}\n)";
        }

        return "WITH\n".implode(",\n\n", $parts)
            ."\n\nSELECT *\nFROM with_balances\nORDER BY interval_index";
    }

    private function monthSpineCte(): string
    {
        return <<<SQL
                SELECT
                    m.month_start::DATE AS month_start,
                    (m.month_start + INTERVAL 1 MONTH - INTERVAL 1 DAY)::DATE AS month_end,
                    row_number() OVER (ORDER BY m.month_start) AS interval_index
                FROM generate_series(
                    CAST(\$period_from AS DATE),
                    CAST(\$last_month_start AS DATE),
                    INTERVAL 1 MONTH
                ) AS m(month_start)
            SQL;
    }

    /**
     * The single scan. `tracker_id` rides along so the tracker grouping below
     * needs no second read of the fact table.
     */
    private function reportLinesCte(): string
    {
        return <<<SQL
                SELECT
                    tl.date,
                    tl.tracker_id,
                    a.account_class,
                    a.account_category,
                    tl.amount
                FROM {$this->alias}.transaction_lines tl
                JOIN {$this->appAlias}.accounts a ON a.account_id = tl.account_id
                {$this->inScopePredicate()}
            SQL;
    }

    private function inScopePredicate(): string
    {
        return <<<SQL
            WHERE tl.farm_id   = \$farm_id
                  AND tl.farm_type = \$farm_type
                  AND tl.region    = \$region
                  AND tl.basis     = \$basis
                  AND tl.date BETWEEN CAST(\$period_from AS DATE) AND CAST(\$period_to AS DATE)
                  AND (
                        (tl.date <= CAST(\$horizon AS DATE) AND tl.type = 'actuals')
                     OR (tl.date >  CAST(\$horizon AS DATE) AND tl.type = 'forecast')
                  )
            SQL;
    }

    /**
     * One row per (month, tracker) — the replacement for Figured's per-tracker
     * loop. An inner join, not a left join: a tracker with no lines in a month
     * contributes nothing to the rollup, and `COALESCE` on the consolidated
     * side covers months with no tracker activity at all.
     */
    private function trackerMonthsCte(): string
    {
        $expressions = [];
        foreach ($this->definition->trackerSections() as $section) {
            $expressions[] = '        '.$this->sectionExpression($section);
        }

        return "        SELECT\n"
            ."            m.interval_index,\n"
            ."            l.tracker_id,\n"
            .implode(",\n", $expressions)."\n"
            ."        FROM months m\n"
            ."        JOIN report_lines l\n"
            ."             ON l.date BETWEEN m.month_start AND m.month_end\n"
            ."            AND l.tracker_id IS NOT NULL\n"
            .'        GROUP BY m.interval_index, l.tracker_id';
    }

    /**
     * Figured's `implode(' + ', $trackerGrossProfitIds)` — a formula string
     * that grows one term per tracker — expressed as a single SUM over the
     * tracker groups.
     */
    private function trackerRollupCte(string $from): string
    {
        $field = $this->definition->trackerRollupField();
        $total = $this->definition->trackerRollupTotalField();

        return <<<SQL
                SELECT
                    t.interval_index,
                    SUM(t.{$field}) AS {$total},
                    COUNT(*) AS tracker_count
                FROM {$from} t
                GROUP BY t.interval_index
            SQL;
    }

    /**
     * Farm-level sections, over lines belonging to no tracker. LEFT JOIN so
     * empty months survive for the running balance to carry through.
     */
    private function farmMonthsCte(): string
    {
        $expressions = [];
        foreach ($this->definition->farmSections() as $section) {
            $expressions[] = '        '.$this->sectionExpression($section);
        }

        return "        SELECT\n"
            ."            m.interval_index,\n"
            ."            strftime(m.month_start, '%Y-%m') AS month,\n"
            .implode(",\n", $expressions)."\n"
            ."        FROM months m\n"
            ."        LEFT JOIN report_lines l\n"
            ."               ON l.date BETWEEN m.month_start AND m.month_end\n"
            ."              AND l.tracker_id IS NULL\n"
            .'        GROUP BY m.interval_index, m.month_start';
    }

    private function sectionsCte(): string
    {
        $total = $this->definition->trackerRollupTotalField();

        return <<<SQL
                SELECT
                    f.*,
                    COALESCE(r.{$total}, 0) AS {$total},
                    COALESCE(r.tracker_count, 0) AS tracker_count
                FROM farm_months f
                LEFT JOIN tracker_rollup r ON r.interval_index = f.interval_index
            SQL;
    }

    private function sectionExpression(ReportSection $section): string
    {
        $fp = self::FIXED_POINT;

        return sprintf(
            "    COALESCE(SUM(CASE WHEN l.account_category = '%s' THEN %s END), 0) / %d.0 AS %s",
            $section->category,
            SectionAmountExpression::for($section->signRule),
            $fp,
            $section->field,
        );
    }

    private function runningBalanceCte(string $from): string
    {
        $fp = self::FIXED_POINT;
        $source = $this->definition->runningBalanceSource();

        return <<<SQL
                SELECT
                    c.*,
                    f.opening_balance / {$fp}.0
                        + COALESCE(SUM(c.{$source}) OVER months_before, 0) AS opening,
                    f.opening_balance / {$fp}.0
                        + SUM(c.{$source}) OVER months_through AS closing
                FROM {$from} c
                CROSS JOIN {$this->appAlias}.farms f
                WHERE f.farm_id = \$farm_id
                WINDOW
                    months_before  AS (ORDER BY c.interval_index
                                       ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING),
                    months_through AS (ORDER BY c.interval_index
                                       ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
            SQL;
    }

    /**
     * The individual transaction lines the report consumed, for display.
     *
     * Same in-scope predicate as the report, so this is genuinely what fed the
     * numbers rather than a re-derived approximation. Carries `tracker_id` and
     * the tracker name from MySQL — on this page the interesting question is
     * which lines are tracker-attributed and which are farm-level, since that
     * split is what decides whether a line lands in a per-tracker section or
     * the consolidated one.
     *
     * A LEFT JOIN to `trackers`, not an inner one: most lines legitimately
     * have no tracker, and an inner join would silently hide exactly the
     * farm-level rows this listing exists to make visible.
     */
    public function buildSourceRowsSql(): string
    {
        $fp = self::FIXED_POINT;

        return <<<SQL
            SELECT
                tl.line_id,
                tl.date,
                tl.type,
                tl.basis,
                a.account_id,
                a.account_name,
                a.account_class,
                a.account_category,
                tl.tracker_id,
                t.tracker_name,
                t.stock_type,
                tl.amount AS amount_raw,
                tl.amount / {$fp}.0 AS amount_dollars
            FROM {$this->alias}.transaction_lines tl
            JOIN {$this->appAlias}.accounts a ON a.account_id = tl.account_id
            LEFT JOIN {$this->appAlias}.trackers t ON t.tracker_id = tl.tracker_id
            {$this->inScopePredicate()}
            ORDER BY tl.date, tl.line_id
            LIMIT \$row_limit
            SQL;
    }

    /**
     * Totals across everything in scope, so the viewer can say how much it is
     * not showing when the listing truncates — split by tracker-attributed vs
     * farm-level, which is the division this report turns on.
     */
    public function buildSourceRowCountSql(): string
    {
        $fp = self::FIXED_POINT;

        return <<<SQL
            SELECT
                count(*) AS n,
                count(tl.tracker_id) AS n_tracker_tagged,
                count(DISTINCT tl.tracker_id) AS n_trackers,
                sum(tl.amount) / {$fp}.0 AS net_dollars
            FROM {$this->alias}.transaction_lines tl
            JOIN {$this->appAlias}.accounts a ON a.account_id = tl.account_id
            {$this->inScopePredicate()}
            SQL;
    }

    /**
     * The per-tracker breakdown, for display under the consolidated report.
     *
     * A second query, and deliberately so: it returns months x trackers rows
     * where the report proper returns months, and forcing both shapes through
     * one result set would mean a discriminator column and a union that made
     * neither readable. It is still O(1) in tracker count — two queries for
     * any farm, against Figured's four plus one per tracker.
     *
     * Cross-joined against MySQL `trackers` so every tracker appears in every
     * month even where it has no lines, giving the viewer a complete grid
     * rather than a ragged one. Tracker names come from MySQL too — the lake
     * holds only the id.
     */
    public function buildTrackerDetailSql(): string
    {
        $fp = self::FIXED_POINT;
        $sections = [];
        foreach ($this->definition->trackerSections() as $section) {
            $sections[] = sprintf(
                "        COALESCE(SUM(CASE WHEN l.account_category = '%s' THEN %s END), 0) / %d.0 AS %s",
                $section->category,
                SectionAmountExpression::for($section->signRule),
                $fp,
                $section->field,
            );
        }

        $rollup = $this->definition->trackerCalculationRows()[0];

        return "WITH\nmonths AS (\n".$this->monthSpineCte()."\n),\n\n"
            ."report_lines AS (\n".$this->reportLinesCte()."\n),\n\n"
            ."grid AS (\n"
            ."    SELECT\n"
            ."        m.interval_index,\n"
            ."        strftime(m.month_start, '%Y-%m') AS month,\n"
            ."        t.tracker_id,\n"
            ."        t.tracker_name,\n"
            ."        t.stock_type,\n"
            .implode(",\n", $sections)."\n"
            ."    FROM months m\n"
            ."    CROSS JOIN {$this->appAlias}.trackers t\n"
            ."    LEFT JOIN report_lines l\n"
            ."           ON l.date BETWEEN m.month_start AND m.month_end\n"
            ."          AND l.tracker_id = t.tracker_id\n"
            ."    WHERE t.farm_id = \$farm_id\n"
            ."    GROUP BY m.interval_index, m.month_start, t.tracker_id, t.tracker_name, t.stock_type, t.display_order\n"
            .")\n\n"
            ."SELECT *, {$rollup->formula} AS {$rollup->field}\n"
            ."FROM grid\n"
            .'ORDER BY tracker_id, interval_index';
    }
}
