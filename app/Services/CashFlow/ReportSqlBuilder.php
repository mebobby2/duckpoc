<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use App\Services\CashFlow\Definition\CashFlowReportDefinition;
use App\Services\CashFlow\Definition\ReportSection;
use App\Services\CashFlow\Definition\SectionAmountExpression;

/**
 * Turns a report definition into one DuckDB query.
 *
 * Nothing about Cash Flow's *meaning* lives here — that is all in
 * `CashFlowReportDefinition`. This class only knows how to express a
 * definition as SQL:
 *
 *   - each section becomes one aggregate expression, its sign rule applied;
 *   - each calculation row becomes one chained CTE, so its formula appears
 *     exactly once instead of being re-expanded into every row built on it;
 *   - the running balance becomes two window functions over a named window.
 *
 * Every runtime value is a named parameter (`$farm_id`, `$horizon`, …) bound
 * by `CashFlowQuery`. Only definition-supplied identifiers — category names,
 * field names — are written into the SQL text, and those are developer
 * constants, not request input.
 */
final class ReportSqlBuilder
{
    /** Matches Figured's TEN_THOUSAND fixed-point convention. */
    private const int FIXED_POINT = 10000;

    public function __construct(
        private readonly CashFlowReportDefinition $definition,
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
            'sections' => $this->sectionsCte(),
        ];

        // Chain one CTE per calculation row, each reading the previous.
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
            $parts[] = "{$name} AS (\n{$body}\n)";
        }

        return "WITH\n".implode(",\n\n", $parts)
            ."\n\nSELECT *\nFROM with_balances\nORDER BY interval_index";
    }

    /**
     * Every month in the period, whether or not it has transactions — the
     * running balance has to carry through the empty ones.
     */
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
     * The rows in scope, with the actuals/forecast horizon applied.
     *
     * farm_type and region are named so DuckDB can prune to this cohort's
     * Parquet files rather than scanning every partition.
     */
    private function reportLinesCte(): string
    {
        return <<<SQL
                SELECT
                    tl.date,
                    a.account_class,
                    a.account_category,
                    tl.amount
                FROM {$this->alias}.transaction_lines tl
                JOIN {$this->appAlias}.accounts a ON a.account_id = tl.account_id
                {$this->inScopePredicate()}
            SQL;
    }

    /**
     * The one definition of "which rows feed this report".
     *
     * Shared by the report itself and by the source-row listing the viewer
     * shows, so the two cannot drift — a copy would risk the UI displaying
     * rows the report never actually consumed.
     */
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
     * The individual transaction lines the report consumed, for display.
     *
     * Same predicate as the report, more columns — including the raw
     * fixed-point amount, since the stored sign (revenue as a credit) is the
     * single easiest thing to get wrong here and worth being able to see.
     */
    public function buildSourceRowsSql(): string
    {
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
                tl.amount AS amount_raw,
                tl.amount / {$this->fixedPoint()}.0 AS amount_dollars
            FROM {$this->alias}.transaction_lines tl
            JOIN {$this->appAlias}.accounts a ON a.account_id = tl.account_id
            {$this->inScopePredicate()}
            ORDER BY tl.date, tl.line_id
            LIMIT \$row_limit
            SQL;
    }

    public function buildSourceRowCountSql(): string
    {
        return <<<SQL
            SELECT count(*) AS n, sum(tl.amount) / {$this->fixedPoint()}.0 AS net_dollars
            FROM {$this->alias}.transaction_lines tl
            JOIN {$this->appAlias}.accounts a ON a.account_id = tl.account_id
            {$this->inScopePredicate()}
            SQL;
    }

    private function fixedPoint(): int
    {
        return self::FIXED_POINT;
    }

    private function sectionsCte(): string
    {
        $expressions = [];
        foreach ($this->definition->sections() as $section) {
            $expressions[] = '        '.$this->sectionExpression($section);
        }

        return "        SELECT\n"
            ."            m.interval_index,\n"
            ."            strftime(m.month_start, '%Y-%m') AS month,\n"
            .implode(",\n", $expressions)."\n"
            ."        FROM months m\n"
            ."        LEFT JOIN report_lines l\n"
            ."               ON l.date BETWEEN m.month_start AND m.month_end\n"
            .'        GROUP BY m.interval_index, m.month_start';
    }

    private function sectionExpression(ReportSection $section): string
    {
        $fp = self::FIXED_POINT;

        $amount = SectionAmountExpression::for($section->signRule);

        return sprintf(
            "    COALESCE(SUM(CASE WHEN l.account_category = '%s' THEN %s END), 0) / %d.0 AS %s",
            $section->category,
            $amount,
            $fp,
            $section->field,
        );
    }

    /**
     * opening is the prior month's closing; closing is opening plus this
     * month's movement. Both are the same running total over the source row,
     * differing only in whether the current row is included — hence one named
     * window per frame rather than two inline OVER clauses.
     */
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
}
