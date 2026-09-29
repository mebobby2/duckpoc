<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use App\Services\Insights\ReportBasis;
use InvalidArgumentException;

/**
 * Which farms, which seasons, which basis, and where actuals stop. The farm
 * list is resolved from the practice in MySQL, as FIP resolves it from the
 * user's access, and the statement never decides visibility itself.
 */
final readonly class PortfolioScope
{
    public const int CURRENT_SEASON = 2027;

    public const string HORIZON = '2026-06-30';

    /**
     * @param list<int> $farmIds
     */
    public function __construct(
        public array $farmIds,
        public string $horizon = self::HORIZON,
        public int $firstSeason = self::CURRENT_SEASON - 3,
        public int $lastSeason = self::CURRENT_SEASON + 2,
        public ReportBasis $basis = ReportBasis::Cash,
    ) {
        if ($farmIds === []) {
            throw new InvalidArgumentException('A portfolio needs at least one farm.');
        }
        if ($lastSeason < $firstSeason) {
            throw new InvalidArgumentException('The last season comes before the first.');
        }
    }

    public static function forPractice(int $practiceId, ?int $limit = null, ReportBasis $basis = ReportBasis::Cash): self
    {
        $sql = 'SELECT farm_id FROM farm_practice WHERE practice_id = ? AND view = 1 ORDER BY farm_id';
        if ($limit !== null) {
            $sql .= ' LIMIT '.max(1, $limit);
        }

        $ids = array_map(static fn (object $r): int => (int) $r->farm_id, InsightsDuckDb::mysql()->select($sql, [$practiceId]));
        if ($ids === []) {
            throw new InvalidArgumentException("Practice {$practiceId} has no farms.");
        }

        return new self($ids, basis: $basis);
    }

    /**
     * The widest date range any farm's window covers, so the lake scan's date
     * filter is one range; and the latest window start, which bounds the
     * opening-balance scan the same way.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function period(): array
    {
        $rows = InsightsDuckDb::mysql()->select(
            'SELECT DISTINCT financial_year_end_month AS m, financial_year_end_day AS d FROM farms
             WHERE id IN ('.implode(',', array_map('intval', $this->farmIds)).') AND _valid_to IS NULL',
        );

        $starts = [];
        $ends = [];
        foreach ($rows as $r) {
            $starts[] = (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $this->firstSeason - 1, (int) $r->m, (int) $r->d)))->modify('+1 day')->format('Y-m-d');
            $ends[] = sprintf('%04d-%02d-%02d', $this->lastSeason, (int) $r->m, (int) $r->d);
        }

        return [min($starts), max($ends), max($starts)];
    }

    /**
     * @return array<string, string>
     */
    public function bindings(): array
    {
        [$from, $to, $openingBefore] = $this->period();

        $bindings = [
            'horizon' => $this->horizon,
            'period_from' => $from,
            'period_to' => $to,
            'first_season' => (string) $this->firstSeason,
            'last_season' => (string) $this->lastSeason,
        ];

        if ($this->basis === ReportBasis::Cash) {
            $bindings['opening_before'] = $openingBefore;
        }

        return $bindings;
    }
}
