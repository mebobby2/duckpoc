<?php

declare(strict_types=1);

namespace App\Services\Insights;

/**
 * Which per-farm rows the Portfolio Modelling statement returns beside the
 * portfolio totals: none, every line and season (for the parity checks), or
 * the one line and season the page is showing, in the page's order.
 *
 * The totals always cover every line and season; this only narrows the
 * per-farm rows, so the statement does the selecting and ordering, not PHP.
 */
final readonly class PortfolioBreakdown
{
    private function __construct(
        public bool $perFarm,
        public ?PortfolioLine $line,
        public ?int $season,
        public PortfolioBreakdownSort $sort,
    ) {
    }

    public static function none(): self
    {
        return new self(false, null, null, PortfolioBreakdownSort::Farm);
    }

    public static function everyLine(): self
    {
        return new self(true, null, null, PortfolioBreakdownSort::Farm);
    }

    public static function of(PortfolioLine $line, int $season, PortfolioBreakdownSort $sort = PortfolioBreakdownSort::Variance): self
    {
        return new self(true, $line, $season, $sort);
    }

    /** The per-farm rows' WHERE condition over the statement's line and season columns. */
    public function condition(): string
    {
        if ($this->line === null || $this->season === null) {
            return 'true';
        }

        return sprintf("line = '%s' AND season = %d", $this->line->value, $this->season);
    }
}
