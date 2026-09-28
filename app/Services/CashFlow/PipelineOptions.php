<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

/**
 * The report options the pipes gate on, named as Figured names them.
 *
 * `type`, `basis`, `ytd`, `excludeEoyJournals` are `BuildAggregationPipeline`
 * inputs; the rest are one `shouldHandle()` each. Defaults are a cash flow's.
 *
 * `reportingGroupEntities` is Figured's reporting-group request: non-empty,
 * the report is the parent's, and each listed child entity runs the pipeline
 * with the parent's financial year (`normaliseParentChildEndDates`) before
 * `CombineReports` sums them. The caller resolves it from
 * `reporting_group_farms`, as Figured resolves `multiEntityChildFarmIds`.
 */
final class PipelineOptions
{
    /**
     * @param list<string> $reportingGroupEntities
     */
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
        public readonly array $reportingGroupEntities = [],
        public readonly bool $mergedAccounts = true,
        public readonly bool $consolidateAccounts = true,
    ) {
    }

    public function isReportingGroup(): bool
    {
        return $this->reportingGroupEntities !== [];
    }

    /**
     * `OverdraftCashFlowStructureBuilder` + `CashflowReport::getApplicableOptions()`:
     * cash basis, tags dropped, EOY journals excluded, nothing else on. It
     * leaves `mergedAccounts` and `consolidateAccounts` unset, so inside a
     * reporting-group request the sub-report runs pipes 11 and 20 as well.
     */
    public function forOverdraftSubReport(): self
    {
        return new self(
            type: $this->type,
            basis: 'cash',
            excludeEoyJournals: true,
            reportingGroupEntities: $this->reportingGroupEntities,
        );
    }

    /** `CurrentYearEarnings::currentYearEarnings()`: the outer options with ytd forced on. */
    public function withYtd(): self
    {
        return new self(
            type: $this->type,
            basis: $this->basis,
            ytd: true,
            excludeEoyJournals: $this->excludeEoyJournals,
            reportingGroupEntities: $this->reportingGroupEntities,
        );
    }

    /** @param list<string> $entities */
    public function forReportingGroup(array $entities): self
    {
        return new self(
            type: $this->type,
            basis: $this->basis,
            ytd: $this->ytd,
            ytdType: $this->ytdType,
            excludeEoyJournals: $this->excludeEoyJournals,
            includeOpeningBudgetGst: $this->includeOpeningBudgetGst,
            calculateCurrentYearEarnings: $this->calculateCurrentYearEarnings,
            calculateRetained: $this->calculateRetained,
            showExpectedSign: $this->showExpectedSign,
            inverse: $this->inverse,
            dynamicBankAccount: $this->dynamicBankAccount,
            reportingGroupEntities: $entities,
            mergedAccounts: $this->mergedAccounts,
            consolidateAccounts: $this->consolidateAccounts,
        );
    }
}
