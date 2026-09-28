<?php

declare(strict_types=1);

namespace App\Services\AlloyDb;

use App\Services\CashFlow\CashFlowActualsForecastOptions;
use App\Services\CashFlow\CashFlowActualsForecastScanShape;
use App\Services\CashFlow\CashFlowActualsForecastSqlBuilder;
use App\Services\CashFlow\Definition\CashFlowActualsForecastReportDefinition;
use LogicException;

/**
 * The actuals-plus-forecast Cash Flow for PostgreSQL 17 / AlloyDB.
 *
 * Not a copy of `CashFlowActualsForecastSqlBuilder` but a translation of its
 * output, so the two engines always run the same statement: same CTEs, same
 * summed scan, same option gates. A change to the lake's statement reaches
 * this one without anybody remembering to port it, and a timing difference
 * is the engine's rather than a rewrite's. The one deliberate difference is
 * the scan's shape, which the builder takes as a parameter: see
 * CashFlowActualsForecastScanShape for why the column store needs another.
 *
 * The dialect gap is small and every rule is below. What the translation
 * cannot see — a DuckDB-only function it has no rule for — is refused rather
 * than sent, so a new construct fails here with its name instead of as a
 * syntax error from the server.
 *
 * Everything else is SQL both engines implement: `WITH RECURSIVE`, `AS
 * MATERIALIZED`, named windows, `FILTER`, `make_date`, `generate_series`
 * over dates, `least` / `greatest`.
 */
final class CashFlowActualsForecastPgSqlBuilder
{
    /** DuckDB spellings that must never reach PostgreSQL. */
    private const array REFUSED = ['strftime(', 'arg_min(', 'arg_max(', 'GROUP BY ALL', 'BY NAME', 'hash(', 'QUALIFY'];

    private readonly CashFlowActualsForecastSqlBuilder $lake;

    public function __construct(
        CashFlowActualsForecastReportDefinition $definition,
        CashFlowActualsForecastOptions $options,
    ) {
        // Both "databases" are the one PostgreSQL schema: facts and
        // dimensions live side by side, so there is no attached catalog.
        $this->lake = new CashFlowActualsForecastSqlBuilder($definition, 'public', 'public', $options, CashFlowActualsForecastScanShape::AlloyDbColumnar);
    }

    public function build(): string
    {
        return self::translate($this->lake->build());
    }

    public function buildVirtualJournalsSql(): string
    {
        return self::translate($this->lake->buildVirtualJournalsSql());
    }

    public function buildVirtualJournalSummarySql(): string
    {
        return self::translate($this->lake->buildVirtualJournalSummarySql());
    }

    public function buildSourceRowsSql(): string
    {
        return self::translate($this->lake->buildSourceRowsSql());
    }

    public function buildSourceRowCountSql(): string
    {
        return self::translate($this->lake->buildSourceRowCountSql());
    }

    public static function translate(string $sql): string
    {
        $rules = [
            // DuckDB's `$name` parameters are PDO's `:name`.
            '/\$([a-z_]+)\b/' => ':$1',
            // `INTERVAL 1 MONTH` is a DuckDB shorthand; PostgreSQL wants the
            // quantity and unit as one quoted literal.
            '/\bINTERVAL (\d+) (MONTH|DAY)\b/' => "INTERVAL '$1 $2'",
            '/\bstrftime\(([^,()]+), \'%Y-%m\'\)/' => "to_char($1, 'YYYY-MM')",
            // `month(x)` / `year(x)`. EXTRACT returns numeric in PostgreSQL
            // 14+, and make_date only takes integers.
            '/\bmonth\(([^()]+)\)/' => 'EXTRACT(MONTH FROM $1)::int',
            '/\byear\(([^()]+)\)/' => 'EXTRACT(YEAR FROM $1)::int',
            // No arg_min / arg_max: the first element of an ordered array
            // is the same value.
            '/\barg_min\(([^,()]+), ([^,()]+)\)/' => '(array_agg($1 ORDER BY $2))[1]',
            '/\barg_max\(([^,()]+), ([^,()]+)\)/' => '(array_agg($1 ORDER BY $2 DESC))[1]',
            '/\bAS DOUBLE\)/' => 'AS DOUBLE PRECISION)',
        ];

        $translated = preg_replace(array_keys($rules), array_values($rules), $sql);

        foreach (self::REFUSED as $construct) {
            if (stripos($translated, $construct) !== false) {
                throw new LogicException("The Cash Flow statement uses {$construct}, which has no PostgreSQL translation rule.");
            }
        }

        return $translated;
    }
}
