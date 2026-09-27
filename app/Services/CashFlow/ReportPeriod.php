<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The date range a report covers, inclusive at both ends.
 *
 * A financial year is one way to make one. Any other range is allowed too,
 * including a partial first or last month, which the report shows as a
 * short column rather than rounding out to whole months.
 */
final readonly class ReportPeriod
{
    private function __construct(
        public string $from,
        public string $to,
    ) {
    }

    public static function between(string $from, string $to): self
    {
        $fromDate = self::parse($from, 'from');
        $toDate = self::parse($to, 'to');

        if ($fromDate > $toDate) {
            throw new InvalidArgumentException("The from date {$from} is after the to date {$to}.");
        }

        return new self($fromDate->format('Y-m-d'), $toDate->format('Y-m-d'));
    }

    /**
     * The twelve months ending on the balance date in `$year`: a May balance
     * date makes 2027 run 2026-06-01 .. 2027-05-31, a December one the
     * calendar year.
     */
    public static function financialYear(int $year, int $financialYearEndMonth): self
    {
        $end = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $financialYearEndMonth)))->modify('last day of this month');
        $start = $end->modify('first day of this month')->modify('-11 months');

        return new self($start->format('Y-m-d'), $end->format('Y-m-d'));
    }

    public function label(): string
    {
        return "{$this->from} → {$this->to}";
    }

    private static function parse(string $value, string $name): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("The {$name} date must be YYYY-MM-DD, got '{$value}'.");
        }

        return $date;
    }
}
