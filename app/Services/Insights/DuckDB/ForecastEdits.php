<?php

declare(strict_types=1);

namespace App\Services\Insights\DuckDB;

use InvalidArgumentException;

/**
 * Which changes to the forecast a report includes, beyond the forecast in
 * the lake: none, the scenarios adopted into it (as things stand now, or as
 * they stood at a time, for a snapshot or the budget), and optionally one
 * proposed scenario on top, for a what-if.
 *
 * Adopting and withdrawing a scenario are timestamped, so the forecast as
 * known at any time is the edits adopted by then and not yet withdrawn.
 */
final readonly class ForecastEdits
{
    /**
     * @param ?string $knownAt the parameter holding the time the forecast is
     *     as known at; null for as things stand now
     * @param ?string $proposal the parameter holding a proposed scenario's changes
     */
    private function __construct(
        public bool $adopted,
        public ?string $knownAt = null,
        public ?string $proposal = null,
    ) {
        foreach ([$knownAt, $proposal] as $parameter) {
            if ($parameter !== null && preg_match('/^[a-z][a-z0-9_]*$/', $parameter) !== 1) {
                throw new InvalidArgumentException("Not a parameter name: {$parameter}");
            }
        }
    }

    public static function none(): self
    {
        return new self(false);
    }

    public static function adopted(?string $knownAt = null): self
    {
        return new self(true, $knownAt);
    }

    public static function proposed(string $proposal, ?string $knownAt = null): self
    {
        return new self(true, $knownAt, $proposal);
    }

    public function any(): bool
    {
        return $this->adopted || $this->proposal !== null;
    }
}
