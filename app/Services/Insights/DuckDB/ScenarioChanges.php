<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use InvalidArgumentException;

/**
 * A what-if as the farmer describes it, checked against the farm's plan and
 * chart and put in the one shape ForecastEditsSqlBuilder reads. Two kinds:
 *
 *   - `account`: a category's forecast over a run of months, the way Magic
 *     Planner's account overrides take it: `values` (one for every month, or
 *     one per month) added or replacing, or a `percentage` change; on one
 *     enterprise's share of the category if it names one;
 *   - `asset_purchase`: buying an asset in a month, with an optional trade-in
 *     and loan, and depreciation at a diminishing-value rate.
 *
 * Changes are to the forecast, so every month must come after actuals_to
 * and inside the plan's season. Milk income and the livestock valuation are
 * not ledger lines here (they come from production and the herd), so they
 * cannot be changed this way.
 */
final readonly class ScenarioChanges
{
    public const float DEFAULT_DEPRECIATION_RATE_PCT = 20.0;

    private const int MAX_CHANGES = 20;

    /**
     * @param list<array<string, mixed>> $changes
     */
    private function __construct(public array $changes)
    {
    }

    /**
     * @param string $first the first forecast month, YYYY-MM
     * @param string $last the season's last month, YYYY-MM
     * @param list<string> $categories the categories this farm's forecast can change
     * @param list<string> $enterprises the farm's enterprises
     */
    public static function fromInput(mixed $changes, string $first, string $last, array $categories, array $enterprises = []): self
    {
        if (!is_array($changes) || !array_is_list($changes) || $changes === []) {
            throw new InvalidArgumentException('changes must be a list of at least one change.');
        }
        if (count($changes) > self::MAX_CHANGES) {
            throw new InvalidArgumentException('A scenario has at most '.self::MAX_CHANGES.' changes.');
        }

        $normalised = [];
        foreach ($changes as $i => $change) {
            $at = 'changes['.$i.']';
            if (!is_array($change)) {
                throw new InvalidArgumentException("{$at} must be an object.");
            }
            $normalised[] = match ($change['type'] ?? null) {
                'account' => self::account($change, $at, $categories, $enterprises, $first, $last),
                'asset_purchase' => self::assetPurchase($change, $at, $first, $last),
                default => throw new InvalidArgumentException("{$at}.type must be account or asset_purchase."),
            };
        }

        return new self($normalised);
    }

    public function json(): string
    {
        return json_encode($this->changes, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** The changes in a sentence each, for the plan note an adoption leaves. */
    public function describe(): string
    {
        $money = static fn (float $v): string => ($v < 0 ? '-$' : '$').number_format(abs($v), 0);

        return implode('; ', array_map(static function (array $c) use ($money): string {
            if ($c['type'] === 'asset_purchase') {
                $text = sprintf('%s in %s for %s', $c['description'], $c['month'], $money($c['cost']));
                if ($c['trade_in'] > 0) {
                    $text .= sprintf(' less a %s trade-in', $money($c['trade_in']));
                }
                if ($c['loan'] !== null) {
                    $text .= sprintf(', %s borrowed at %s%% over %d years', $money($c['loan']['amount']), $c['loan']['annual_rate_pct'], $c['loan']['years']);
                }

                return $text;
            }
            $months = $c['start_month'] === $c['end_month'] ? $c['start_month'] : "{$c['start_month']} to {$c['end_month']}";
            $how = match (true) {
                $c['percentage'] !== null => sprintf('%+g%%', $c['percentage']),
                count($c['values']) === 1 => ($c['application_mode'] === 'replace' ? 'set to ' : '').$money($c['values'][0]).' a month',
                default => ($c['application_mode'] === 'replace' ? 'set month by month' : 'changed month by month'),
            };

            return sprintf('%s%s %s, %s', $c['enterprise'] === null ? '' : $c['enterprise'].' ', $c['category'], $how, $months);
        }, $this->changes));
    }

    /**
     * @param array<string, mixed> $c
     * @param list<string> $categories
     * @param list<string> $enterprises
     * @return array<string, mixed>
     */
    private static function account(array $c, string $at, array $categories, array $enterprises, string $first, string $last): array
    {
        $category = $c['category'] ?? null;
        if (!is_string($category) || !in_array($category, $categories, true)) {
            throw new InvalidArgumentException(sprintf('%s.category must be one of: %s.', $at, implode(', ', $categories)));
        }
        $enterprise = $c['enterprise'] ?? null;
        if ($enterprise !== null && !in_array($enterprise, $enterprises, true)) {
            throw new InvalidArgumentException(sprintf('%s.enterprise must be one of this farm\'s: %s.', $at, $enterprises === [] ? 'none recorded' : implode(', ', $enterprises)));
        }
        $start = self::month($c['start_month'] ?? null, "{$at}.start_month", $first, $last);
        $end = self::month($c['end_month'] ?? $start, "{$at}.end_month", $first, $last);
        if ($end < $start) {
            throw new InvalidArgumentException("{$at}.end_month is before its start_month.");
        }
        $months = self::monthsBetween($start, $end);

        $mode = $c['application_mode'] ?? 'add';
        if (!in_array($mode, ['add', 'replace'], true)) {
            throw new InvalidArgumentException("{$at}.application_mode must be add or replace.");
        }

        $values = $c['values'] ?? null;
        $percentage = $c['percentage'] ?? null;
        if (($values === null) === ($percentage === null)) {
            throw new InvalidArgumentException("{$at} needs values or a percentage: one of them, not both.");
        }
        if ($percentage !== null) {
            if (!is_numeric($percentage) || $percentage < -100 || $percentage > 1000) {
                throw new InvalidArgumentException("{$at}.percentage must be a number from -100 to 1000.");
            }
            if ($mode === 'replace') {
                throw new InvalidArgumentException("{$at}: a percentage changes the forecast; it cannot replace it.");
            }
        } else {
            if (!is_array($values) || !array_is_list($values) || !in_array(count($values), [1, $months], true)
                || array_filter($values, static fn (mixed $v): bool => !is_numeric($v)) !== []) {
                throw new InvalidArgumentException("{$at}.values must be one number for every month, or one per month ({$months}).");
            }
        }

        return [
            'type' => 'account',
            'category' => $category,
            'enterprise' => $enterprise,
            'start_month' => $start,
            'end_month' => $end,
            'values' => $values === null ? null : array_map('floatval', $values),
            'percentage' => $percentage === null ? null : (float) $percentage,
            'application_mode' => $mode,
        ];
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private static function assetPurchase(array $c, string $at, string $first, string $last): array
    {
        $cost = self::amount($c['cost'] ?? null, "{$at}.cost", 1, 100_000_000);
        $tradeIn = self::amount($c['trade_in'] ?? 0, "{$at}.trade_in", 0, $cost);
        $rate = self::amount($c['depreciation_rate_pct'] ?? self::DEFAULT_DEPRECIATION_RATE_PCT, "{$at}.depreciation_rate_pct", 0, 100);

        $loan = null;
        if (($c['loan'] ?? null) !== null) {
            if (!is_array($c['loan'])) {
                throw new InvalidArgumentException("{$at}.loan must be an object with amount, annual_rate_pct and years.");
            }
            $years = $c['loan']['years'] ?? null;
            if (!is_numeric($years) || (int) $years != $years || $years < 1 || $years > 30) {
                throw new InvalidArgumentException("{$at}.loan.years must be a whole number from 1 to 30.");
            }
            $loan = [
                'amount' => self::amount($c['loan']['amount'] ?? null, "{$at}.loan.amount", 1, $cost * (1 + InsightsDuckDbPracticeSeeder::GST_RATE)),
                'annual_rate_pct' => self::amount($c['loan']['annual_rate_pct'] ?? null, "{$at}.loan.annual_rate_pct", 0, 30),
                'years' => (int) $years,
            ];
        }

        $description = trim((string) ($c['description'] ?? 'Asset purchase'));

        return [
            'type' => 'asset_purchase',
            'description' => $description === '' ? 'Asset purchase' : mb_substr($description, 0, 100),
            'month' => self::month($c['month'] ?? null, "{$at}.month", $first, $last),
            'cost' => $cost,
            'trade_in' => $tradeIn,
            'depreciation_rate_pct' => $rate,
            'loan' => $loan,
        ];
    }

    private static function month(mixed $month, string $at, string $first, string $last): string
    {
        if (!is_string($month) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new InvalidArgumentException("{$at} must be a month as YYYY-MM.");
        }
        if ($month < $first) {
            throw new InvalidArgumentException("{$at} must be a forecast month, {$first} to {$last}: {$month} is already actuals.");
        }
        if ($month > $last) {
            throw new InvalidArgumentException("{$at} must be in this plan's season, {$first} to {$last}.");
        }

        return $month;
    }

    private static function amount(mixed $value, string $at, float $min, float $max): float
    {
        if (!is_numeric($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException(sprintf('%s must be a number from %s to %s.', $at, $min, round($max, 2)));
        }

        return (float) $value;
    }

    private static function monthsBetween(string $start, string $end): int
    {
        [$sy, $sm] = array_map('intval', explode('-', $start));
        [$ey, $em] = array_map('intval', explode('-', $end));

        return ($ey - $sy) * 12 + $em - $sm + 1;
    }
}
