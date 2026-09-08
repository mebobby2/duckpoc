<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Definition;

/**
 * A derived row: `$field` = `$formula`, where the formula is written in terms
 * of section fields and earlier calculation rows.
 *
 * Formulas are kept in exactly the notation Figured's own
 * `ReportCalculationRow` uses (`'gross_profit - operating_expenses'`), so the
 * two definitions can be read side by side. Each becomes one chained CTE, so
 * a formula appears once in the generated SQL rather than being re-expanded
 * into every row that builds on it.
 */
final readonly class CalculationRow
{
    public function __construct(
        public string $field,
        /** Arithmetic over section/calculation field names. */
        public string $formula,
        /** Row label for the report viewer. */
        public string $label,
        /** Emphasised in the viewer — the rows a reader actually looks for. */
        public bool $isSubtotal = false,
    ) {
    }
}
