<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CashFlow\ReportPeriod;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportPeriodTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: int, 2: string, 3: string}>
     */
    public static function financialYears(): array
    {
        return [
            'May balance date' => [2027, 5, '2026-06-01', '2027-05-31'],
            'March balance date' => [2027, 3, '2026-04-01', '2027-03-31'],
            'December balance date is the calendar year' => [2027, 12, '2027-01-01', '2027-12-31'],
            'February balance date in a leap year' => [2028, 2, '2027-03-01', '2028-02-29'],
        ];
    }

    #[DataProvider('financialYears')]
    public function testAFinancialYearIsTheTwelveMonthsEndingOnTheBalanceDate(int $year, int $endMonth, string $from, string $to): void
    {
        $period = ReportPeriod::financialYear($year, $endMonth);

        self::assertSame([$from, $to], [$period->from, $period->to]);
    }

    public function testAnyRangeIsAllowedIncludingPartialMonthsAndSeveralYears(): void
    {
        $period = ReportPeriod::between('2023-06-15', '2027-05-10');

        self::assertSame(['2023-06-15', '2027-05-10'], [$period->from, $period->to]);
    }

    public function testASingleDayIsAPeriod(): void
    {
        $period = ReportPeriod::between('2026-07-01', '2026-07-01');

        self::assertSame('2026-07-01', $period->to);
    }

    public function testFromAfterToIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReportPeriod::between('2027-01-01', '2026-12-31');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedDates(): array
    {
        return [
            'not a date' => ['yesterday'],
            'rolls over' => ['2026-02-30'],
            'no leading zeros' => ['2026-6-1'],
            'empty' => [''],
        ];
    }

    #[DataProvider('malformedDates')]
    public function testAMalformedDateIsRefusedRatherThanGuessed(string $date): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReportPeriod::between($date, '2027-05-31');
    }
}
