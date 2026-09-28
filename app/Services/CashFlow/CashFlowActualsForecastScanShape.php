<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

/**
 * How the Cash Flow's scan is written for an engine. Both shapes select the
 * same lines and produce the same report; they differ in what each engine
 * can execute inside its scan.
 */
enum CashFlowActualsForecastScanShape
{
    /**
     * One scan grouped by (date, account, tracker, tag), with the horizon as
     * one `CASE` comparison. DuckDB evaluates the `OR` form as two passes
     * over every row, so it is 1.1 s slower at 1B lines.
     */
    case DuckDb;

    /**
     * AlloyDB's column store only sums inside the scan when every grouping
     * key and filter is one it can evaluate there. `tag` as a grouping key
     * stops it, and so does the horizon written as a `CASE`; each sends every
     * matching row to PostgreSQL's executor instead. On 500M lines that was
     * 32 s against 1 s.
     *
     * So the scan groups by (date, account, tracker) only, in two parts: GST
     * settlements, the one tag the statement reads, and everything else,
     * untagged. The GST handler only ever asks whether a line is a
     * settlement, so no figure changes. The horizon keeps Figured's `OR`.
     */
    case AlloyDbColumnar;
}
