<?php

declare(strict_types=1);

namespace App\Services\Insights\AlloyDB;

use App\Services\Insights\PortfolioAssumption;
use App\Services\Insights\ReportBasis;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * The Portfolio Modelling numbers recomputed in PHP, one farm at a time, from
 * raw lines — no SQL aggregation, no shared code with the statement.
 *
 * It exists so the statement has something independent to agree with. Every
 * rule the statement applies (horizon split, bank exclusion, milk and GST
 * virtual journals, categorisation, assumptions, flow-on) is written out
 * again here, as loops over rows. A difference between the two is a bug in
 * one of them, found at a size small enough to read.
 */
final class PortfolioModellingOracle
{
    private const int FIXED_POINT = 10000;

    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
    }

    /**
     * @param list<PortfolioAssumption> $assumptions
     * @return array<int, array<int, array<string, array{original: float, modelled: float}>>> farm => season => line => values, in dollars
     */
    public function compute(PortfolioScope $scope, array $assumptions): array
    {
        [$periodFrom, $periodTo] = $scope->period($this->db);

        $percent = [];
        foreach ($assumptions as $a) {
            $percent[$a->line->value][$a->season] = $a->percent;
        }

        $result = [];
        foreach ($scope->farmIds as $farmId) {
            $result[$farmId] = $scope->basis === ReportBasis::Accrual
                ? $this->accrualFarm($farmId, $scope, $periodFrom, $periodTo, $percent)
                : $this->farm($farmId, $scope, $periodFrom, $periodTo, $percent);
        }

        return $result;
    }

    /**
     * @param array<string, array<int, float>> $percent
     * @return array<int, array<string, array{original: float, modelled: float}>>
     */
    private function farm(int $farmId, PortfolioScope $scope, string $periodFrom, string $periodTo, array $percent): array
    {
        $s = InsightsSchema::SCHEMA;
        $tag = InsightsPracticeSeeder::TAG_GST_PAYMENT;

        $farm = $this->db->selectOne("SELECT financial_year_end_month AS m, financial_year_end_day AS d FROM {$s}.farms WHERE id = ? AND _valid_to IS NULL", [$farmId]);
        $fyMonth = (int) $farm->m;
        $fyDay = (int) $farm->d;

        $windowStart = (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $scope->firstSeason - 1, $fyMonth, $fyDay)))->modify('+1 day')->format('Y-m-d');
        $windowEnd = sprintf('%04d-%02d-%02d', $scope->lastSeason, $fyMonth, $fyDay);

        $accounts = [];
        foreach ($this->db->select(
            "SELECT xa.accountid, xa.type, xa.system_account, c.\"group\" AS grp, c.system_category_name AS category
             FROM {$s}.xero_accounts xa
             JOIN {$s}.category_xero_account cxa ON cxa.farm_id = xa.farm_id AND cxa.xero_account_id = xa.accountid
             JOIN {$s}.categories c ON c.id = cxa.category_id
             WHERE xa.farm_id = ?",
            [$farmId],
        ) as $a) {
            $accounts[(string) $a->accountid] = ['type' => (string) $a->type, 'system' => $a->system_account, 'grp' => (string) $a->grp, 'category' => (string) $a->category];
        }

        $bankIds = array_keys(array_filter($accounts, static fn (array $a): bool => $a['type'] === 'BANK'));
        $gstId = array_key_first(array_filter($accounts, static fn (array $a): bool => $a['system'] === 'GST'));
        $paymentsId = array_key_first(array_filter($accounts, static fn (array $a): bool => $a['system'] === 'GSTPAYMENTS'));

        $inScope = static fn (object $l): bool => ((string) $l->date <= $scope->horizon && $l->type === 'actuals')
            || ((string) $l->date > $scope->horizon && $l->type === 'forecast');

        $opening = 0;
        foreach ($this->db->select(
            "SELECT date, type, account_id, net_amount FROM {$s}.transaction_lines WHERE farm_id = ? AND basis = 'cash' AND date < ?",
            [$farmId, $windowStart],
        ) as $l) {
            if (in_array((string) $l->account_id, $bankIds, true) && $inScope($l)) {
                $opening += (int) $l->net_amount;
            }
        }

        // [date, account, amount, tag]
        $raw = [];
        foreach ($this->db->select(
            "SELECT date, type, account_id, net_amount, tag FROM {$s}.transaction_lines WHERE farm_id = ? AND basis = 'cash' AND date BETWEEN ? AND ?",
            [$farmId, $periodFrom, $periodTo],
        ) as $l) {
            if ($inScope($l)) {
                $raw[] = [(string) $l->date, (string) $l->account_id, (int) $l->net_amount, $l->tag];
            }
        }

        $virtual = [];

        foreach ($this->db->select(
            "SELECT mt.income_accountid, mp.transaction_date, mp.production, pr.price
             FROM {$s}.milk_productions mp
             JOIN {$s}.milk_trackers mt ON mt.id = mp.milk_tracker_id
             JOIN {$s}.milk_tracker_prices pr ON pr.milk_tracker_id = mt.id AND pr.month = mp.transaction_date
             WHERE mp.farm_id = ? AND mp.production > 0 AND mp.budget_id = 0",
            [$farmId],
        ) as $m) {
            $paid = (new DateTimeImmutable((string) $m->transaction_date))->modify('+1 month')->modify('+19 days')->format('Y-m-d');
            if ($paid > $scope->horizon && $paid <= $periodTo) {
                $virtual[] = [$paid, (string) $m->income_accountid, -((int) $m->production * (int) $m->price)];
            }
        }

        $gstByMonth = [];
        $settlements = [];
        foreach ($raw as [$date, $account, $amount, $lineTag]) {
            if ($account !== $gstId) {
                continue;
            }
            if ($lineTag === $tag) {
                $settlements[] = [$date, $amount];
            } else {
                $month = substr($date, 0, 7);
                $gstByMonth[$month] = ($gstByMonth[$month] ?? 0) + $amount;
            }
        }

        $cursor = new DateTimeImmutable(substr($windowStart, 0, 7).'-01');
        while ($cursor->format('Y-m-d') <= $windowEnd) {
            $month = (int) $cursor->format('n');
            if ($month % 2 === ($fyMonth + 1) % 2) {
                $year = (int) $cursor->format('Y');
                $payDate = match ($month) {
                    12 => sprintf('%04d-01-15', $year + 1),
                    4 => sprintf('%04d-05-07', $year),
                    default => sprintf('%04d-%02d-28', $year, $month),
                };
                $from = $cursor->modify('-2 months')->format('Y-m');
                $to = $cursor->modify('-1 month')->format('Y-m');
                $windowEndDate = $cursor->modify('-1 day')->format('Y-m-d');

                if ($payDate > $scope->horizon) {
                    $net = ($gstByMonth[$from] ?? 0) + ($gstByMonth[$to] ?? 0);
                    $paid = 0;
                    foreach ($settlements as [$date, $amount]) {
                        if ($date > $windowEndDate && $date <= $payDate) {
                            $paid += $amount;
                        }
                    }
                    $predicted = -$net - $paid;
                    if ($predicted !== 0) {
                        $virtual[] = [$payDate, $paymentsId, $predicted];
                    }
                }
            }
            $cursor = $cursor->modify('+1 month');
        }

        foreach ($settlements as [$date, $amount]) {
            $virtual[] = [$date, $gstId, -$amount];
            $virtual[] = [$date, $paymentsId, $amount];
        }

        $sums = [];
        for ($season = $scope->firstSeason; $season <= $scope->lastSeason; $season++) {
            $sums[$season] = ['milk_income' => 0, 'total_income' => 0, 'fertiliser' => 0, 'total_operating_expenses' => 0, 'net_cash_movement' => 0];
        }

        $all = array_merge(
            array_map(static fn (array $r): array => [$r[0], $r[1], $r[2]], $raw),
            $virtual,
        );

        foreach ($all as [$date, $account, $amount]) {
            if (!isset($accounts[$account]) || $accounts[$account]['type'] === 'BANK' || $date < $windowStart || $date > $windowEnd) {
                continue;
            }
            $season = (int) substr($date, 0, 4) + ((int) substr($date, 5, 2) > $fyMonth ? 1 : 0);
            $a = $accounts[$account];

            if ($a['category'] === 'Milk Income') {
                $sums[$season]['milk_income'] -= $amount;
            }
            if ($a['grp'] === 'income') {
                $sums[$season]['total_income'] -= $amount;
            }
            if ($a['category'] === 'Fertiliser') {
                $sums[$season]['fertiliser'] += $amount;
            }
            if ($a['grp'] === 'operating_expenses') {
                $sums[$season]['total_operating_expenses'] += $amount;
            }
            $sums[$season]['net_cash_movement'] -= $amount;
        }

        $fp = self::FIXED_POINT;
        $closing = $opening;
        $carried = 0.0;
        $out = [];

        foreach ($sums as $season => $v) {
            $milk = $v['milk_income'];
            $otherIncome = $v['total_income'] - $milk;
            $fert = $v['fertiliser'];
            $otherOpex = $v['total_operating_expenses'] - $fert;
            $closing += $v['net_cash_movement'];

            $apply = static fn (string $line, int $original): float => $original * (1 + ($percent[$line][$season] ?? 0) / 100);

            $milkM = $apply('milk_income', $milk);
            $otherIncomeM = $apply('other_income', $otherIncome);
            $fertM = $apply('fertiliser', $fert);
            $otherOpexM = $apply('other_operating_expenses', $otherOpex);

            $surplus = ($milk + $otherIncome) - ($fert + $otherOpex);
            $surplusM = ($milkM + $otherIncomeM) - ($fertM + $otherOpexM);
            $carried += $surplusM - $surplus;

            $pairs = [
                'milk_income' => [$milk, $milkM],
                'other_income' => [$otherIncome, $otherIncomeM],
                'total_income' => [$milk + $otherIncome, $milkM + $otherIncomeM],
                'fertiliser' => [$fert, $fertM],
                'other_operating_expenses' => [$otherOpex, $otherOpexM],
                'total_operating_expenses' => [$fert + $otherOpex, $fertM + $otherOpexM],
                'operating_surplus' => [$surplus, $surplusM],
                'net_cash_movement' => [$v['net_cash_movement'], $v['net_cash_movement'] + ($surplusM - $surplus)],
                'closing_cash' => [$closing, $closing + $carried],
            ];

            foreach ($pairs as $line => [$original, $modelled]) {
                $out[$season][$line] = ['original' => round($original / $fp, 2), 'modelled' => round($modelled / $fp, 2)];
            }
        }

        return $out;
    }

    /**
     * The accrual profit and loss for one farm, from raw rows: accrual lines
     * on P&L accounts, milk production not yet invoiced, and the livestock
     * valuation change, each rule written out again as a loop.
     *
     * @param array<string, array<int, float>> $percent
     * @return array<int, array<string, array{original: float, modelled: float}>>
     */
    private function accrualFarm(int $farmId, PortfolioScope $scope, string $periodFrom, string $periodTo, array $percent): array
    {
        $s = InsightsSchema::SCHEMA;

        $farm = $this->db->selectOne("SELECT financial_year_end_month AS m, financial_year_end_day AS d FROM {$s}.farms WHERE id = ? AND _valid_to IS NULL", [$farmId]);
        $fyMonth = (int) $farm->m;
        $fyDay = (int) $farm->d;
        $windowStart = (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $scope->firstSeason - 1, $fyMonth, $fyDay)))->modify('+1 day')->format('Y-m-d');
        $windowEnd = sprintf('%04d-%02d-%02d', $scope->lastSeason, $fyMonth, $fyDay);
        $seasonOf = static fn (string $date): int => (int) substr($date, 0, 4) + ((int) substr($date, 5, 2) > $fyMonth ? 1 : 0);

        $accounts = [];
        foreach ($this->db->select(
            "SELECT xa.accountid, c.\"group\" AS grp, c.system_category_name AS category
             FROM {$s}.xero_accounts xa
             JOIN {$s}.category_xero_account cxa ON cxa.farm_id = xa.farm_id AND cxa.xero_account_id = xa.accountid
             JOIN {$s}.categories c ON c.id = cxa.category_id
             WHERE xa.farm_id = ?",
            [$farmId],
        ) as $a) {
            $accounts[(string) $a->accountid] = ['grp' => (string) $a->grp, 'category' => (string) $a->category];
        }
        $isPl = static fn (string $account): bool => isset($accounts[$account]) && in_array($accounts[$account]['grp'], ReportBasis::PROFIT_AND_LOSS_GROUPS, true);
        $valuationAccount = array_key_first(array_filter($accounts, static fn (array $a): bool => $a['category'] === 'Livestock Valuation Change'));

        // [date, account, amount]
        $lines = [];
        foreach ($this->db->select(
            "SELECT date, type, account_id, net_amount FROM {$s}.transaction_lines WHERE farm_id = ? AND basis = 'accrual' AND date BETWEEN ? AND ?",
            [$farmId, $periodFrom, $periodTo],
        ) as $l) {
            $inScope = ((string) $l->date <= $scope->horizon && $l->type === 'actuals') || ((string) $l->date > $scope->horizon && $l->type === 'forecast');
            if ($inScope && $isPl((string) $l->account_id)) {
                $lines[] = [substr((string) $l->date, 0, 7).'-01', (string) $l->account_id, (int) $l->net_amount];
            }
        }

        foreach ($this->db->select(
            "SELECT mt.income_accountid, mp.transaction_date, mp.production, pr.price
             FROM {$s}.milk_productions mp
             JOIN {$s}.milk_trackers mt ON mt.id = mp.milk_tracker_id
             JOIN {$s}.milk_tracker_prices pr ON pr.milk_tracker_id = mt.id AND pr.month = mp.transaction_date
             WHERE mp.farm_id = ? AND mp.production > 0 AND mp.budget_id = 0",
            [$farmId],
        ) as $m) {
            $month = (string) $m->transaction_date;
            $monthEnd = (new DateTimeImmutable($month))->modify('last day of this month')->format('Y-m-d');
            if ($monthEnd > $scope->horizon && $month <= $periodTo) {
                $lines[] = [$month, (string) $m->income_accountid, -((int) $m->production * (int) $m->price)];
            }
        }

        // Livestock: net movement per class per month, heads carried month to
        // month, valued at the season's rate; the change in value is income.
        $net = [];
        foreach ($this->db->select(
            "SELECT tracker_id, stock_class_uuid, transaction_date, type, transition, quantity FROM {$s}.stock_transactions
             WHERE farm_id = ? AND budget_id = 0 AND transaction_date <= ?",
            [$farmId, $periodTo],
        ) as $st) {
            $date = (string) $st->transaction_date;
            if (!(($date <= $scope->horizon && $st->type === 'actual') || ($date > $scope->horizon && $st->type === 'forecast'))) {
                continue;
            }
            $key = $st->tracker_id.'|'.$st->stock_class_uuid;
            $month = substr($date, 0, 7).'-01';
            $sign = in_array($st->transition, ['opening', 'purchase', 'birth'], true) ? 1 : -1;
            $net[$key][$month] = ($net[$key][$month] ?? 0) + $sign * (float) $st->quantity;
        }

        $rates = [];
        foreach ($this->db->select("SELECT tracker_id, stock_class_uuid, season, value_per_head FROM {$s}.stock_class_valuations WHERE farm_id = ?", [$farmId]) as $v) {
            $rates[$v->tracker_id.'|'.$v->stock_class_uuid][(int) $v->season] = (int) $v->value_per_head;
        }

        $values = [];
        foreach ($net as $key => $byMonth) {
            ksort($byMonth);
            $head = 0.0;
            $cursor = new DateTimeImmutable(array_key_first($byMonth));
            while ($cursor->format('Y-m-d') <= $windowEnd) {
                $month = $cursor->format('Y-m-d');
                $head += $byMonth[$month] ?? 0;
                $rate = $rates[$key][$seasonOf($month)] ?? null;
                if ($rate !== null) {
                    $values[$month] = ($values[$month] ?? 0) + $head * $rate;
                }
                $cursor = $cursor->modify('+1 month');
            }
        }
        ksort($values);
        $previous = null;
        foreach ($values as $month => $value) {
            if ($previous !== null && $valuationAccount !== null) {
                $lines[] = [$month, $valuationAccount, (int) round(-($value - $previous))];
            }
            $previous = $value;
        }

        $sums = [];
        for ($season = $scope->firstSeason; $season <= $scope->lastSeason; $season++) {
            $sums[$season] = ['milk_income' => 0, 'total_income' => 0, 'fertiliser' => 0, 'total_operating_expenses' => 0, 'net_profit' => 0];
        }
        $windowFrom = substr($windowStart, 0, 7).'-01';
        foreach ($lines as [$date, $account, $amount]) {
            if (!isset($accounts[$account]) || $date < $windowFrom || $date > $windowEnd) {
                continue;
            }
            $season = $seasonOf($date);
            $a = $accounts[$account];
            if ($a['category'] === 'Milk Income') {
                $sums[$season]['milk_income'] -= $amount;
            }
            if ($a['grp'] === 'income') {
                $sums[$season]['total_income'] -= $amount;
            }
            if ($a['category'] === 'Fertiliser') {
                $sums[$season]['fertiliser'] += $amount;
            }
            if ($a['grp'] === 'operating_expenses') {
                $sums[$season]['total_operating_expenses'] += $amount;
            }
            $sums[$season]['net_profit'] -= $amount;
        }

        $fp = self::FIXED_POINT;
        $out = [];
        foreach ($sums as $season => $v) {
            $milk = $v['milk_income'];
            $otherIncome = $v['total_income'] - $milk;
            $fert = $v['fertiliser'];
            $otherOpex = $v['total_operating_expenses'] - $fert;

            $apply = static fn (string $line, int $original): float => $original * (1 + ($percent[$line][$season] ?? 0) / 100);
            $milkM = $apply('milk_income', $milk);
            $otherIncomeM = $apply('other_income', $otherIncome);
            $fertM = $apply('fertiliser', $fert);
            $otherOpexM = $apply('other_operating_expenses', $otherOpex);

            $surplus = ($milk + $otherIncome) - ($fert + $otherOpex);
            $surplusM = ($milkM + $otherIncomeM) - ($fertM + $otherOpexM);

            $pairs = [
                'milk_income' => [$milk, $milkM],
                'other_income' => [$otherIncome, $otherIncomeM],
                'total_income' => [$milk + $otherIncome, $milkM + $otherIncomeM],
                'fertiliser' => [$fert, $fertM],
                'other_operating_expenses' => [$otherOpex, $otherOpexM],
                'total_operating_expenses' => [$fert + $otherOpex, $fertM + $otherOpexM],
                'operating_surplus' => [$surplus, $surplusM],
                'net_profit' => [$v['net_profit'], $v['net_profit'] + ($surplusM - $surplus)],
            ];
            foreach ($pairs as $line => [$original, $modelled]) {
                $out[$season][$line] = ['original' => round($original / $fp, 2), 'modelled' => round($modelled / $fp, 2)];
            }
        }

        return $out;
    }
}
