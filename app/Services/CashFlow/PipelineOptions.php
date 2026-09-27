<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

/**
 * The report options the pipes gate on, named as Figured names them.
 *
 * `type`, `basis`, `ytd`, `excludeEoyJournals` are `BuildAggregationPipeline`
 * inputs; the rest are one `shouldHandle()` each. Defaults are a cash flow's.
 */
final class PipelineOptions
{
    public function __construct(
        public readonly string $type = 'actualsForecast',
        public readonly string $basis = 'cash',
        public readonly bool $ytd = false,
        public readonly ?string $ytdType = null,
        public readonly bool $excludeEoyJournals = false,
        public readonly bool $includeOpeningBudgetGst = false,
        public readonly bool $calculateCurrentYearEarnings = false,
        public readonly bool $calculateRetained = false,
        public readonly bool $showExpectedSign = false,
        public readonly bool $inverse = false,
        public readonly bool $dynamicBankAccount = false,
    ) {
    }

    /**
     * `OverdraftCashFlowStructureBuilder` + `CashflowReport::getApplicableOptions()`:
     * cash basis, tags dropped, EOY journals excluded, nothing else on.
     */
    public function forOverdraftSubReport(): self
    {
        return new self(type: $this->type, basis: 'cash', excludeEoyJournals: true);
    }

    /** `CurrentYearEarnings::currentYearEarnings()`: the outer options with ytd forced on. */
    public function withYtd(): self
    {
        return new self(
            type: $this->type,
            basis: $this->basis,
            ytd: true,
            excludeEoyJournals: $this->excludeEoyJournals,
        );
    }
}
