<?php

declare(strict_types=1);

namespace App\Services\Insights;

use InvalidArgumentException;

/**
 * One what-if on the Portfolio Modelling page: move one line by a percentage
 * in one season, for every farm in the portfolio. FIP's `assumptions` input
 * carries the same three things per year.
 */
final readonly class PortfolioAssumption
{
    public function __construct(
        public PortfolioLine $line,
        public int $season,
        public float $percent,
    ) {
        if (!$line->isAssumable()) {
            throw new InvalidArgumentException("{$line->value} is calculated from other lines and cannot carry an assumption.");
        }
    }

    /**
     * Parses `milk_income:2027:-5`, the command-line spelling.
     */
    public static function parse(string $spec): self
    {
        $parts = explode(':', $spec);
        if (count($parts) !== 3 || !is_numeric($parts[1]) || !is_numeric($parts[2])) {
            throw new InvalidArgumentException("Expected line:season:percent, got '{$spec}'.");
        }

        $line = PortfolioLine::tryFrom($parts[0])
            ?? throw new InvalidArgumentException("Unknown line '{$parts[0]}'.");

        return new self($line, (int) $parts[1], (float) $parts[2]);
    }

    /**
     * @param list<self> $assumptions
     */
    public static function toJson(array $assumptions): string
    {
        $seen = [];
        foreach ($assumptions as $a) {
            $key = $a->line->value.':'.$a->season;
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Two assumptions for {$key}; FIP allows one per line per season.");
            }
            $seen[$key] = true;
        }

        return json_encode(array_map(
            static fn (self $a): array => ['line' => $a->line->value, 'season' => $a->season, 'percent' => $a->percent],
            $assumptions,
        ), JSON_THROW_ON_ERROR);
    }
}
