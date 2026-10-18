<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;

/**
 * The months ahead in cash, in each version compared: each month's closing
 * bank balance, net cash movement and overdraft interest, against the farm's
 * overdraft limit, with when each version first goes overdrawn, first goes
 * past the limit, and how low it gets. From the cash flow comparison itself,
 * so it is the same bank balance every other report shows.
 */
final class CashOutlookSqlBuilder
{
    /**
     * @param non-empty-list<ReportSide> $sides
     */
    public function __construct(
        private readonly int $farmId,
        private readonly array $sides,
    ) {
    }

    public function build(): string
    {
        $comparison = (new ReportComparisonSqlBuilder($this->farmId, ReportBasis::Cash, $this->sides, totalLabel: 'Total'))->build();
        $facilities = InsightsDuckDb::table('bank_facilities');
        $interest = ReportLinesSqlBuilder::OVERDRAFT_INTEREST;

        $byMonth = [];
        $summary = [];
        foreach ($this->sides as $side) {
            $k = $side->key;
            array_push(
                $byMonth,
                "c.{$k} AS {$k}_closing",
                "n.{$k} AS {$k}_net_cash",
                "COALESCE(oi.{$k}, 0) AS {$k}_overdraft_interest",
            );
            array_push(
                $summary,
                "min(m.{$k}_closing) OVER () AS {$k}_lowest",
                "arg_min(m.month, m.{$k}_closing) OVER () AS {$k}_lowest_month",
                "min(m.month) FILTER (WHERE m.{$k}_closing < 0) OVER () AS {$k}_first_overdrawn",
                "min(m.month) FILTER (WHERE m.{$k}_closing < -f.overdraft_limit) OVER () AS {$k}_first_over_limit",
                "round(f.overdraft_limit + m.{$k}_closing, 2) AS {$k}_headroom",
                "SUM(m.{$k}_overdraft_interest) OVER () AS {$k}_overdraft_interest_total",
            );
        }
        $byMonth = implode(",\n           ", $byMonth);
        $summary = implode(",\n           ", $summary);

        return <<<SQL
            WITH
            comparison AS (
            {$comparison}
            ),
            months AS (
                SELECT c.period AS month, c.status,
                       {$byMonth}
                FROM comparison c
                LEFT JOIN comparison n ON n.period = c.period AND n.kind = 'calculation' AND n.field = 'net_cash_movement'
                LEFT JOIN comparison oi ON oi.period = c.period AND oi.kind = 'category' AND oi.category = '{$interest}'
                WHERE c.kind = 'balance' AND c.field = 'closing' AND c.period_type = 'month'
            ),
            facility AS (
                SELECT CAST(overdraft_limit AS DOUBLE) AS overdraft_limit, CAST(rate_pct AS DOUBLE) AS rate_pct
                FROM {$facilities}
                WHERE farm_id = {$this->farmId}
            )
            SELECT m.*, f.overdraft_limit, f.rate_pct,
                   {$summary}
            FROM months m
            LEFT JOIN facility f ON true
            ORDER BY m.month
            SQL;
    }
}
