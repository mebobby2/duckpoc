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
     * opening-balance scan the same way. Each farm's own window is cut from
     * it in the statement.
     *
     * @return array{0: string, 1: string, 2: string} from, to, latest start
     */
    public function period(): array
    {
        $farmIds = implode(',', array_map('intval', $this->farmIds));
        $row = InsightsDuckDb::mysql()->selectOne(
            "SELECT MIN(STR_TO_DATE(CONCAT(? - 1, '-', financial_year_end_month, '-', financial_year_end_day), '%Y-%c-%e') + INTERVAL 1 DAY) AS period_from,
                    MAX(STR_TO_DATE(CONCAT(?, '-', financial_year_end_month, '-', financial_year_end_day), '%Y-%c-%e')) AS period_to,
                    MAX(STR_TO_DATE(CONCAT(? - 1, '-', financial_year_end_month, '-', financial_year_end_day), '%Y-%c-%e') + INTERVAL 1 DAY) AS opening_before
             FROM farms
             WHERE id IN ({$farmIds}) AND _valid_to IS NULL",
            [$this->firstSeason, $this->lastSeason, $this->firstSeason],
        );

        return [(string) $row->period_from, (string) $row->period_to, (string) $row->opening_before];
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
