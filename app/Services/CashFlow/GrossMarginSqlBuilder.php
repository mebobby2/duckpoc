<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use App\Services\CashFlow\Definition\GrossMarginReportDefinition;
use App\Services\CashFlow\Definition\ReportSection;
use App\Services\CashFlow\Definition\SectionAmountExpression;

/**
 * Turns the Gross Margin definition into one DuckDB query.
 *
 * The claim this file exists to make good on: **the per-tracker stateful stock
 * chain is one window function, not a loop.** Figured computes opening stock by
 * calling `TrackerQuantityService` once per tracker (twice per report, via
 * `Types::getTrackerQtys()`), because a document store cannot express a running
 * total ordered within a group. Here:
 *
 *     opening_head = trackers.opening_stock
 *                  + SUM(net_movement) OVER (PARTITION BY tracker_id
 *                                            ORDER BY month
 *                                            ... UNBOUNDED PRECEDING TO 1 PRECEDING)
 *
 * One pass computes it for every tracker at once, so the SQL is identical
 * whether the farm has 1 tracker or 50.
 *
 * THE CORRECTNESS TRAP, and it is a quiet one: the running total must run over
 * a tracker's **entire movement history**, not just the reporting period.
 * Opening stock for January 2021 is every movement since the tracker began. If
 * the movement CTE were filtered to the period, opening stock would start from
 * the seed value instead and every head count would be wrong — while still
 * balancing internally, which is exactly the failure this project has hit
 * before. So `stock_history` is deliberately unfiltered by date, and the period
 * filter is applied only when joining to the month spine.
 */
final class GrossMarginSqlBuilder
{
    private const int FIXED_POINT = 10000;

    public function __construct(
        private readonly GrossMarginReportDefinition $definition,
        /** The DuckLake catalog — journal facts. */
        private readonly string $alias,
        /** The attached MySQL database — trackers and stock movements. */
        private readonly string $appAlias,
    ) {
    }

    public function build(): string
    {
        $ctes = [
            'months' => $this->monthSpineCte(),
            'report_lines' => $this->reportLinesCte(),
            'financials' => $this->financialsCte(),
            'stock_history' => $this->stockHistoryCte(),
            'grid' => $this->gridCte(),
        ];

        $previous = 'grid';
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

        $parts = [];
        foreach ($ctes as $name => $body) {
            $materialized = $name === 'report_lines' ? ' AS MATERIALIZED' : ' AS';
            $parts[] = "{$name}{$materialized} (\n{$body}\n)";
        }

        return "WITH\n".implode(",\n\n", $parts)
            ."\n\nSELECT *\nFROM {$previous}\nORDER BY display_order, interval_index";
    }

    private function monthSpineCte(): string
    {
        return <<<SQL
                SELECT
                    m.month_start::DATE AS month_start,
                    row_number() OVER (ORDER BY m.month_start) AS interval_index
                FROM generate_series(
                    date_trunc('month', CAST(\$period_from AS DATE)),
                    date_trunc('month', CAST(\$period_to AS DATE)),
                    INTERVAL 1 MONTH
                ) AS m(month_start)
            SQL;
    }

    /**
     * Journal lines in scope. Only tracker-attributed lines matter here — a
     * Gross Margin report is per operating entity, so farm-level overheads
     * have no tracker to belong to.
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

    /**
     * The one definition of "which journal lines feed this report".
     *
     * Shared with the source-row diagnostics below, so the listing cannot show
     * rows the report did not consume. Note `tracker_id IS NOT NULL`: a Gross
     * Margin report is per operating entity, so farm-level overheads have no
     * tracker to belong to and are out of scope by definition — which is a
     * real difference from Cash Flow, where they are the bulk of the report.
     */
    private function inScopePredicate(): string
    {
        return <<<SQL
            WHERE tl.farm_id   = \$farm_id
                  AND tl.farm_type = \$farm_type
                  AND tl.region    = \$region
                  AND tl.basis     = \$basis
                  AND tl.tracker_id IS NOT NULL
                  AND tl.date BETWEEN CAST(\$period_from AS DATE) AND CAST(\$period_to AS DATE)
                  AND (
                        (tl.date <= CAST(\$horizon AS DATE) AND tl.type = 'actuals')
                     OR (tl.date >  CAST(\$horizon AS DATE) AND tl.type = 'forecast')
                  )
            SQL;
    }

    /**
     * The journal lines the report consumed, for display.
     */
    public function buildSourceRowsSql(): string
    {
        $fp = self::FIXED_POINT;

        return <<<SQL
            SELECT
                tl.line_id,
                tl.date,
                tl.type,
                a.account_name,
                a.account_class,
                a.account_category,
                tl.tracker_id,
                t.tracker_name,
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

    public function buildSourceRowCountSql(): string
    {
        $fp = self::FIXED_POINT;

        return <<<SQL
            SELECT
                count(*) AS n,
                count(DISTINCT tl.tracker_id) AS n_trackers,
                sum(tl.amount) / {$fp}.0 AS net_dollars
            FROM {$this->alias}.transaction_lines tl
            JOIN {$this->appAlias}.accounts a ON a.account_id = tl.account_id
            {$this->inScopePredicate()}
            SQL;
    }

    private function financialsCte(): string
    {
        $expressions = [];
        foreach ($this->definition->financialSections() as $section) {
            $expressions[] = '        '.$this->sectionExpression($section);
        }

        return "        SELECT\n"
            ."            date_trunc('month', l.date)::DATE AS month_start,\n"
            ."            l.tracker_id,\n"
            .implode(",\n", $expressions)."\n"
            ."        FROM report_lines l\n"
            ."        GROUP BY 1, 2";
    }

    /**
     * The running stock chain — every tracker, one pass.
     *
     * Unfiltered by date on purpose: see the class docblock. The window is
     * PARTITION BY tracker_id, which is what replaces Figured's per-tracker
     * loop.
     */
    private function stockHistoryCte(): string
    {
        return <<<SQL
                SELECT
                    mv.tracker_id,
                    mv.month::DATE AS month_start,
                    mv.purchases,
                    mv.births,
                    mv.sales,
                    mv.deaths,
                    t.opening_stock
                        + COALESCE(SUM(mv.purchases + mv.births - mv.sales - mv.deaths)
                                   OVER tracker_before, 0) AS opening_head,
                    t.opening_stock
                        + SUM(mv.purchases + mv.births - mv.sales - mv.deaths)
                          OVER tracker_through AS closing_head
                FROM {$this->appAlias}.tracker_stock_movements mv
                JOIN {$this->appAlias}.trackers t ON t.tracker_id = mv.tracker_id
                WHERE t.farm_id = \$farm_id
                WINDOW
                    tracker_before  AS (PARTITION BY mv.tracker_id ORDER BY mv.month
                                        ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING),
                    tracker_through AS (PARTITION BY mv.tracker_id ORDER BY mv.month
                                        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
            SQL;
    }

    /**
     * Every tracker in every month of the period, with financials and stock
     * attached.
     *
     * CROSS JOIN against `trackers` (a handful of rows) so a tracker with no
     * activity in a month still appears — a ragged Gross Margin table is worse
     * than one with zeroes, because a missing row reads as missing data rather
     * than as no movement.
     */
    private function gridCte(): string
    {
        $financial = [];
        foreach ($this->definition->financialSections() as $section) {
            $financial[] = "            COALESCE(f.{$section->field}, 0) AS {$section->field}";
        }

        $movements = [];
        foreach ($this->definition->movementRows() as $row) {
            $movements[] = "            COALESCE(s.{$row['field']}, 0) AS {$row['field']}";
        }

        return "        SELECT\n"
            ."            m.interval_index,\n"
            ."            strftime(m.month_start, '%Y-%m') AS month,\n"
            ."            t.tracker_id,\n"
            ."            t.tracker_name,\n"
            ."            t.stock_type,\n"
            ."            t.display_order,\n"
            .implode(",\n", $financial).",\n"
            .implode(",\n", $movements).",\n"
            // Carry the last known stock forward when a month has no movement
            // row, rather than dropping to zero head.
            ."            COALESCE(s.opening_head, t.opening_stock) AS opening_head,\n"
            ."            COALESCE(s.closing_head, t.opening_stock) AS closing_head\n"
            ."        FROM months m\n"
            ."        CROSS JOIN {$this->appAlias}.trackers t\n"
            ."        LEFT JOIN financials f\n"
            ."               ON f.month_start = m.month_start\n"
            ."              AND f.tracker_id = t.tracker_id\n"
            ."        LEFT JOIN stock_history s\n"
            ."               ON s.month_start = m.month_start\n"
            ."              AND s.tracker_id = t.tracker_id\n"
            .'        WHERE t.farm_id = $farm_id';
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
}
