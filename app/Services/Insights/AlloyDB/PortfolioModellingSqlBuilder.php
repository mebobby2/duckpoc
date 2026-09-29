<?php

declare(strict_types=1);

namespace App\Services\Insights\AlloyDB;

use App\Services\Insights\PortfolioLine;
use App\Services\Insights\ReportBasis;

/**
 * FIP's Portfolio Modelling page as ONE AlloyDB statement over raw journals.
 *
 * FIP answers this page from report output the webapp synced into its Mongo:
 * dozens of per-line aggregation pipelines over every farm, then the
 * assumption maths in PHP. This computes the same thing from source rows, for
 * the whole portfolio at once.
 *
 * It holds no report logic of its own. The report lines come from
 * ReportLinesSqlBuilder, the same CTEs the single-farm cash flow is built on;
 * all this adds is the portfolio's presentation:
 *
 *   1. six seasons per farm, on each farm's own balance date, as the window
 *      the report lines are cut to;
 *   2. original values per farm per season, from the lines' categories;
 *   3. the assumptions applied, the calculated lines rebuilt from their
 *      modelled parts, and closing cash carried forward season to season.
 *
 * Seasons assume a balance date on the last day of the month, which is every
 * NZ farm. Output is long: one row per (farm, season, line), and with a null
 * farm, one per (season, line) for the whole portfolio.
 */
final class PortfolioModellingSqlBuilder
{
    private const int FIXED_POINT = 10000;

    private readonly ReportLinesSqlBuilder $reportLines;

    public function __construct(
        private readonly bool $withBreakdown = true,
        private readonly ReportBasis $basis = ReportBasis::Cash,
    ) {
        $this->reportLines = new ReportLinesSqlBuilder($basis);
    }

    public function build(): string
    {
        $ctes = ['seasons' => $this->seasonsCte()] + $this->reportLines->ctes($this->windowCte()) + [
            'by_season' => $this->bySeasonCte(),
            'original' => $this->originalCte(),
            'assumptions' => $this->assumptionsCte(),
            'applied' => $this->appliedCte(),
            'modelled' => $this->modelledCte(),
            'with_flow_on' => $this->flowOnCte(),
            'farm_lines' => $this->farmLinesCte(),
        ];

        // `seasons` reads `farm`, so it has to follow it.
        $ordered = ['farm' => $ctes['farm'], 'seasons' => $ctes['seasons']] + $ctes;

        return ReportLinesSqlBuilder::statement($ordered, $this->finalSelect());
    }

    private function seasonsCte(): string
    {
        return <<<SQL
            SELECT fm.farm_id, g.season,
                   make_date(g.season - 1, fm.fy_month, fm.fy_day) + 1 AS season_start,
                   make_date(g.season, fm.fy_month, fm.fy_day) AS season_end
            FROM farm fm
            CROSS JOIN generate_series(CAST(:first_season AS INTEGER), CAST(:last_season AS INTEGER)) AS g(season)
            SQL;
    }

    private function windowCte(): string
    {
        return <<<SQL
            SELECT farm_id, min(season_start) AS window_start, max(season_end) AS window_end
            FROM seasons
            GROUP BY farm_id
            SQL;
    }

    /**
     * Income is -amount, a cost is amount, and cash movement is -amount over
     * every report line, because the lines keep the ledger's signs.
     */
    private function bySeasonCte(): string
    {
        return <<<SQL
            SELECT l.farm_id,
                   EXTRACT(YEAR FROM l.date)::int + CASE WHEN EXTRACT(MONTH FROM l.date)::int > f.fy_month THEN 1 ELSE 0 END AS season,
                   -SUM(l.amount) FILTER (WHERE l.category = 'Milk Income') AS milk_income,
                   -SUM(l.amount) FILTER (WHERE l.grp = 'income') AS total_income,
                   SUM(l.amount) FILTER (WHERE l.category = 'Fertiliser') AS fertiliser,
                   SUM(l.amount) FILTER (WHERE l.grp = 'operating_expenses') AS total_operating_expenses,
                   -SUM(l.amount) AS bottom_line
            FROM report_lines l
            JOIN farm f ON f.farm_id = l.farm_id
            GROUP BY 1, 2
            SQL;
    }

    /**
     * The report's bottom line is -amount over every report line: net cash
     * movement on cash basis, where the lines are every non-bank movement,
     * and net profit on accrual, where they are the P&L. Only cash carries a
     * balance from season to season.
     */
    private function originalCte(): string
    {
        $common = <<<SQL
            SELECT s.farm_id, s.season,
                   COALESCE(b.milk_income, 0) AS milk_income,
                   COALESCE(b.total_income, 0) - COALESCE(b.milk_income, 0) AS other_income,
                   COALESCE(b.fertiliser, 0) AS fertiliser,
                   COALESCE(b.total_operating_expenses, 0) - COALESCE(b.fertiliser, 0) AS other_operating_expenses,
            SQL;

        if ($this->basis === ReportBasis::Accrual) {
            return $common.<<<SQL

                   COALESCE(b.bottom_line, 0) AS net_profit
            FROM seasons s
            LEFT JOIN by_season b ON b.farm_id = s.farm_id AND b.season = s.season
            SQL;
        }

        return $common.<<<SQL

                   COALESCE(b.bottom_line, 0) AS net_cash_movement,
                   o.opening_balance
                       + SUM(COALESCE(b.bottom_line, 0)) OVER (PARTITION BY s.farm_id ORDER BY s.season) AS closing_cash
            FROM seasons s
            LEFT JOIN by_season b ON b.farm_id = s.farm_id AND b.season = s.season
            JOIN opening o ON o.farm_id = s.farm_id
            SQL;
    }

    private function assumptionsCte(): string
    {
        return <<<SQL
            SELECT a.line, a.season, a.percent
            FROM jsonb_to_recordset(CAST(:assumptions AS JSONB)) AS a(line TEXT, season INTEGER, percent NUMERIC)
            SQL;
    }

    private function appliedCte(): string
    {
        return <<<SQL
            SELECT o.*,
                   o.milk_income * (1 + COALESCE(am.percent, 0) / 100) AS milk_income_m,
                   o.other_income * (1 + COALESCE(ao.percent, 0) / 100) AS other_income_m,
                   o.fertiliser * (1 + COALESCE(af.percent, 0) / 100) AS fertiliser_m,
                   o.other_operating_expenses * (1 + COALESCE(ax.percent, 0) / 100) AS other_operating_expenses_m
            FROM original o
            LEFT JOIN assumptions am ON am.line = 'milk_income' AND am.season = o.season
            LEFT JOIN assumptions ao ON ao.line = 'other_income' AND ao.season = o.season
            LEFT JOIN assumptions af ON af.line = 'fertiliser' AND af.season = o.season
            LEFT JOIN assumptions ax ON ax.line = 'other_operating_expenses' AND ax.season = o.season
            SQL;
    }

    private function modelledCte(): string
    {
        return <<<SQL
            SELECT a.*,
                   a.milk_income + a.other_income AS total_income,
                   a.milk_income_m + a.other_income_m AS total_income_m,
                   a.fertiliser + a.other_operating_expenses AS total_operating_expenses,
                   a.fertiliser_m + a.other_operating_expenses_m AS total_operating_expenses_m,
                   (a.milk_income + a.other_income) - (a.fertiliser + a.other_operating_expenses) AS operating_surplus,
                   (a.milk_income_m + a.other_income_m) - (a.fertiliser_m + a.other_operating_expenses_m) AS operating_surplus_m
            FROM applied a
            SQL;
    }

    /**
     * The surplus change is the only thing an assumption moves below the
     * surplus. On cash it moves net cash, and closing cash carries every
     * earlier season's change forward; on accrual it moves net profit.
     */
    private function flowOnCte(): string
    {
        if ($this->basis === ReportBasis::Accrual) {
            return <<<SQL
                SELECT m.*,
                       m.net_profit + (m.operating_surplus_m - m.operating_surplus) AS net_profit_m
                FROM modelled m
                SQL;
        }

        return <<<SQL
            SELECT m.*,
                   m.net_cash_movement + (m.operating_surplus_m - m.operating_surplus) AS net_cash_movement_m,
                   m.closing_cash + SUM(m.operating_surplus_m - m.operating_surplus)
                       OVER (PARTITION BY m.farm_id ORDER BY m.season) AS closing_cash_m
            FROM modelled m
            SQL;
    }

    private function farmLinesCte(): string
    {
        $columns = [
            PortfolioLine::MilkIncome->value => 'milk_income',
            PortfolioLine::OtherIncome->value => 'other_income',
            PortfolioLine::TotalIncome->value => 'total_income',
            PortfolioLine::Fertiliser->value => 'fertiliser',
            PortfolioLine::OtherOperatingExpenses->value => 'other_operating_expenses',
            PortfolioLine::TotalOperatingExpenses->value => 'total_operating_expenses',
            PortfolioLine::OperatingSurplus->value => 'operating_surplus',
            PortfolioLine::NetCashMovement->value => 'net_cash_movement',
            PortfolioLine::ClosingCash->value => 'closing_cash',
            PortfolioLine::NetProfit->value => 'net_profit',
        ];

        $rows = [];
        foreach (PortfolioLine::forBasis($this->basis) as $i => $line) {
            $column = $columns[$line->value];
            $rows[] = sprintf("                ('%s', %d, CAST(w.%s AS NUMERIC), CAST(w.%s_m AS NUMERIC))", $line->value, $i, $column, $column);
        }
        $values = implode(",\n", $rows);

        return <<<SQL
            SELECT w.farm_id, w.season, v.line, v.line_order, v.original, v.modelled
            FROM with_flow_on w
            CROSS JOIN LATERAL (VALUES
            {$values}
            ) AS v(line, line_order, original, modelled)
            SQL;
    }

    private function finalSelect(): string
    {
        $fp = self::FIXED_POINT;

        $farmRows = $this->withBreakdown ? <<<SQL
            SELECT farm_id, season, line, line_order,
                   1 AS farms,
                   round(original / {$fp}.0, 2) AS original,
                   round(modelled / {$fp}.0, 2) AS modelled,
                   round((modelled - original) / {$fp}.0, 2) AS variance
            FROM farm_lines
            UNION ALL

            SQL : '';

        return <<<SQL
            {$farmRows}SELECT CAST(NULL AS INTEGER) AS farm_id, season, line, line_order,
                   count(*) AS farms,
                   round(SUM(original) / {$fp}.0, 2) AS original,
                   round(SUM(modelled) / {$fp}.0, 2) AS modelled,
                   round(SUM(modelled - original) / {$fp}.0, 2) AS variance
            FROM farm_lines
            GROUP BY season, line, line_order
            ORDER BY farm_id NULLS FIRST, season, line_order
            SQL;
    }
}
