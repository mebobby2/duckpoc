<?php

declare(strict_types=1);

namespace App\Services\Insights;

/**
 * The single-farm actuals-plus-forecast cash flow over the `insights` tables,
 * built on the same report lines as Portfolio Modelling.
 *
 * Its only job is presentation: the report lines ReportLinesSqlBuilder
 * produces, bucketed by month into the cash flow's sections, with the
 * calculation rows, the opening/closing running balance and a Total column.
 * Every rule about which lines count and how virtual journals are made is in
 * the shared builder, so this report and the portfolio cannot drift apart.
 *
 * Output is long: one row per (row, month) — a category, a section total, a
 * calculated row or a balance — and the Total column as interval
 * `max + 1`. Amounts are in display sign: income and costs positive,
 * movements as their effect on cash.
 */
final class InsightsCashFlowSqlBuilder
{
    private const int FIXED_POINT = 10000;

    /**
     * [group, label, sign]: the section's lines are summed and multiplied by
     * the sign to show them the way the cash flow does. Income is stored
     * credit-negative; movements show their effect on cash, the inverse of
     * the ledger.
     */
    public const array SECTIONS = [
        ['income', 'Income', -1],
        ['operating_expenses', 'Operating Expenses', 1],
        ['non_operating_income', 'Non Operating Income', -1],
        ['non_operating_expenses', 'Non Operating Expenses', 1],
        ['non_operating_movements', 'Non Operating Movements', -1],
        ['equity_movements', 'Equity Movements', -1],
        ['gst', 'GST', -1],
    ];

    public const array CALCULATIONS = [
        ['operating_surplus', 'Operating Surplus', 'income - operating_expenses'],
        ['total_surplus', 'Total Surplus', 'operating_surplus + non_operating_income - non_operating_expenses'],
        ['net_cash_movement', 'Net Cash Movement', 'total_surplus + non_operating_movements + equity_movements + gst'],
    ];

    public function __construct(
        private readonly ReportLinesSqlBuilder $reportLines = new ReportLinesSqlBuilder(),
    ) {
    }

    public function build(): string
    {
        $ctes = $this->reportLines->ctes($this->windowCte()) + [
            'months' => $this->monthsCte(),
            'category_months' => $this->categoryMonthsCte(),
            'section_months' => $this->sectionMonthsCte(),
            'calculated' => $this->calculatedCte(),
            'balances' => $this->balancesCte(),
        ];

        return ReportLinesSqlBuilder::statement($ctes, $this->finalSelect());
    }

    private function windowCte(): string
    {
        return <<<SQL
            SELECT farm_id, CAST(:period_from AS DATE) AS window_start, CAST(:period_to AS DATE) AS window_end
            FROM farm
            SQL;
    }

    private function monthsCte(): string
    {
        return <<<SQL
            SELECT row_number() OVER (ORDER BY g.m) AS interval_index,
                   g.m::DATE AS month_start,
                   to_char(g.m, 'YYYY-MM') AS month,
                   CASE
                       WHEN (g.m + INTERVAL '1 month' - INTERVAL '1 day')::DATE <= CAST(:horizon AS DATE) THEN 'actuals'
                       WHEN g.m::DATE > CAST(:horizon AS DATE) THEN 'forecast'
                       ELSE 'actualsForecast'
                   END AS column_type
            FROM generate_series(date_trunc('month', CAST(:period_from AS DATE)), CAST(:period_to AS DATE), INTERVAL '1 month') AS g(m)
            SQL;
    }

    private function signCase(string $group): string
    {
        $cases = [];
        foreach (self::SECTIONS as [$g, , $sign]) {
            $cases[] = "WHEN '{$g}' THEN {$sign}";
        }

        return "CASE {$group} ".implode(' ', $cases).' ELSE 0 END';
    }

    private function categoryMonthsCte(): string
    {
        return <<<SQL
            SELECT date_trunc('month', l.date)::DATE AS month_start, l.grp, l.category,
                   SUM(l.amount) * ({$this->signCase('l.grp')}) AS amount
            FROM report_lines l
            GROUP BY 1, 2, 3
            SQL;
    }

    private function sectionMonthsCte(): string
    {
        $columns = [];
        foreach (self::SECTIONS as [$group]) {
            $columns[] = "COALESCE(SUM(c.amount) FILTER (WHERE c.grp = '{$group}'), 0) AS {$group}";
        }
        $selected = implode(",\n       ", $columns);

        return <<<SQL
            SELECT m.interval_index, m.month_start, m.month, m.column_type,
                   {$selected}
            FROM months m
            LEFT JOIN category_months c ON c.month_start = m.month_start
            GROUP BY m.interval_index, m.month_start, m.month, m.column_type
            SQL;
    }

    private function calculatedCte(): string
    {
        // Each calculation may read the ones before it, so they are inlined
        // in order rather than referenced by name.
        $inlined = [];
        $select = [];
        foreach (self::CALCULATIONS as [$field, , $formula]) {
            $expression = $formula;
            foreach ($inlined as $name => $body) {
                $expression = preg_replace('/\b'.$name.'\b/', "({$body})", $expression);
            }
            $inlined[$field] = $expression;
            $select[] = "{$expression} AS {$field}";
        }
        $columns = implode(",\n       ", $select);

        return <<<SQL
            SELECT s.*, {$columns}
            FROM section_months s
            SQL;
    }

    private function balancesCte(): string
    {
        return <<<SQL
            SELECT c.*,
                   o.opening_balance + COALESCE(SUM(c.net_cash_movement) OVER months_before, 0) AS opening,
                   o.opening_balance + SUM(c.net_cash_movement) OVER months_through AS closing
            FROM calculated c
            CROSS JOIN opening o
            WINDOW
                months_before AS (ORDER BY c.interval_index ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING),
                months_through AS (ORDER BY c.interval_index ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
            SQL;
    }

    private function finalSelect(): string
    {
        $fp = self::FIXED_POINT;

        $rows = [];
        foreach (self::SECTIONS as [$group, $label]) {
            $rows[] = "('section', '{$group}', '{$label}', b.{$group})";
        }
        foreach (self::CALCULATIONS as [$field, $label]) {
            $rows[] = "('calculation', '{$field}', '{$label}', b.{$field})";
        }
        $rows[] = "('balance', 'opening', 'Opening Balance', b.opening)";
        $rows[] = "('balance', 'closing', 'Closing Balance', b.closing)";
        $values = implode(",\n        ", $rows);

        return <<<SQL
            SELECT kind, field, label, category, interval_index, month, column_type, amount FROM (
                SELECT 'category' AS kind, c.grp AS field, c.grp AS label, c.category, m.interval_index, m.month, m.column_type,
                       round(c.amount / {$fp}.0, 2) AS amount
                FROM category_months c
                JOIN months m ON m.month_start = c.month_start
                UNION ALL
                SELECT 'category', c.grp, c.grp, c.category, (SELECT max(interval_index) + 1 FROM months), 'Total', 'total',
                       round(SUM(c.amount) / {$fp}.0, 2)
                FROM category_months c
                GROUP BY c.grp, c.category
                UNION ALL
                SELECT v.kind, v.field, v.label, CAST(NULL AS TEXT), b.interval_index, b.month, b.column_type,
                       round(v.amount / {$fp}.0, 2)
                FROM balances b
                CROSS JOIN LATERAL (VALUES
                    {$values}
                ) AS v(kind, field, label, amount)
                UNION ALL
                SELECT v.kind, v.field, v.label, CAST(NULL AS TEXT), (SELECT max(interval_index) + 1 FROM months), 'Total', 'total',
                       round(v.amount / {$fp}.0, 2)
                FROM (
                    SELECT {$this->totalColumns()}
                    FROM balances b
                ) b
                CROSS JOIN LATERAL (VALUES
                    {$values}
                ) AS v(kind, field, label, amount)
            ) report
            ORDER BY interval_index, kind, field, category
            SQL;
    }

    /**
     * The Total column: flows summed, opening from the first month, closing
     * from the last.
     */
    private function totalColumns(): string
    {
        $columns = [];
        foreach (self::SECTIONS as [$group]) {
            $columns[] = "SUM(b.{$group}) AS {$group}";
        }
        foreach (self::CALCULATIONS as [$field]) {
            $columns[] = "SUM(b.{$field}) AS {$field}";
        }
        $columns[] = '(array_agg(b.opening ORDER BY b.interval_index))[1] AS opening';
        $columns[] = '(array_agg(b.closing ORDER BY b.interval_index DESC))[1] AS closing';

        return implode(', ', $columns);
    }
}
