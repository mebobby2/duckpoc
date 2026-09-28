<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use App\Services\CashFlow\Definition\CashFlowActualsForecastReportDefinition;

/**
 * The actuals-plus-forecast Cash Flow on some engine. The runner and the
 * oracle check hold one of these, so the same 687 cells are checked against
 * DuckLake and AlloyDB alike.
 */
interface CashFlowActualsForecastReport
{
    public function definition(): CashFlowActualsForecastReportDefinition;

    public function options(): CashFlowActualsForecastOptions;

    public function sql(): string;

    /**
     * One row per (scope, column): scope `farm` for the consolidated report,
     * a tracker id for each tracker block; a month or `Total` per column.
     *
     * @return list<array<string, mixed>>
     */
    public function run(string $farmId, ReportPeriod $period, string $horizon): array;
}
