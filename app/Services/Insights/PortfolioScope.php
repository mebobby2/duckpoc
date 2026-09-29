<?php

declare(strict_types=1);

namespace App\Services\Insights;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Which farms, which seasons, and where actuals stop. FIP passes the farm
 * list resolved from the user's practice, portfolio and business access, so
 * the statement never decides visibility itself.
 *
 * The default window is FIP's sync window: three seasons back, the current
 * season, two forward.
 */
final readonly class PortfolioScope
{
    public const int CURRENT_SEASON = 2027;

    /**
     * @param list<int> $farmIds
     */
    public function __construct(
        public array $farmIds,
        public string $horizon = InsightsPracticeSeeder::HORIZON,
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

    public static function forPractice(ConnectionInterface $db, int $practiceId, ?int $limit = null, ReportBasis $basis = ReportBasis::Cash): self
    {
        $s = InsightsSchema::SCHEMA;
        $sql = "SELECT farm_id FROM {$s}.farm_practice WHERE practice_id = ? AND view ORDER BY farm_id";
        if ($limit !== null) {
            $sql .= ' LIMIT '.max(1, $limit);
        }

        $ids = array_map(static fn (object $r): int => (int) $r->farm_id, $db->select($sql, [$practiceId]));
        if ($ids === []) {
            throw new InvalidArgumentException("Practice {$practiceId} has no farms.");
        }

        return new self($ids, basis: $basis);
    }

    public function farmIdsLiteral(): string
    {
        return '{'.implode(',', $this->farmIds).'}';
    }

    /**
     * The widest date range any farm's window covers, so the scan's date
     * filter is one literal range the column store can apply. Each farm's own
     * window is cut from it afterwards.
     *
     * The latest window start bounds the opening-balance scan the same way.
     *
     * @return array{0: string, 1: string, 2: string} from, to, latest start
     */
    public function period(ConnectionInterface $db): array
    {
        $s = InsightsSchema::SCHEMA;
        $row = $db->selectOne(
            "SELECT min(make_date(CAST(? AS INTEGER) - 1, financial_year_end_month, financial_year_end_day) + 1) AS period_from,
                    max(make_date(CAST(? AS INTEGER), financial_year_end_month, financial_year_end_day)) AS period_to,
                    max(make_date(CAST(? AS INTEGER) - 1, financial_year_end_month, financial_year_end_day) + 1) AS opening_before
             FROM {$s}.farms
             WHERE id = ANY (CAST(? AS INTEGER[])) AND _valid_to IS NULL",
            [$this->firstSeason, $this->lastSeason, $this->firstSeason, $this->farmIdsLiteral()],
        );

        return [(string) $row->period_from, (string) $row->period_to, (string) $row->opening_before];
    }

    /**
     * @return array<string, int|string>
     */
    public function bindings(ConnectionInterface $db, string $assumptionsJson): array
    {
        [$from, $to, $openingBefore] = $this->period($db);

        $bindings = [
            'farm_ids' => $this->farmIdsLiteral(),
            'horizon' => $this->horizon,
            'period_from' => $from,
            'period_to' => $to,
            'first_season' => $this->firstSeason,
            'last_season' => $this->lastSeason,
            'assumptions' => $assumptionsJson,
        ];

        // Only the cash statement reads an opening bank balance, and PDO
        // refuses a binding the statement has no placeholder for.
        if ($this->basis === ReportBasis::Cash) {
            $bindings['opening_before'] = $openingBefore;
        }

        return $bindings;
    }
}
