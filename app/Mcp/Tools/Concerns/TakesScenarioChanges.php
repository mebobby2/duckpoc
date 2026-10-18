<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Services\Insights\DuckDB\ScenarioChanges;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

trait TakesScenarioChanges
{
    private function changesSchema(JsonSchema $schema, bool $required = true): Type
    {
        $month = static fn (string $what): Type => $schema->string()->pattern('^\d{4}-\d{2}$')->description($what);

        return $schema->array()->min(1)->max(20)->required($required)->items($schema->anyOf([
            $schema->object([
                'type' => $schema->string()->enum(['account'])->required(),
                'category' => $schema->string()->required()
                    ->description('The income or cost category as get-report names it, e.g. Repairs, Fertiliser, Wages, Livestock Sales, Capital Purchases.'),
                'enterprise' => $schema->string()
                    ->description('Only this enterprise\'s share of the category, e.g. Cropping. Default: every enterprise, in proportion.'),
                'start_month' => $month('First month changed, YYYY-MM; a forecast month (after actuals_to).')->required(),
                'end_month' => $month('Last month changed, YYYY-MM (default: the start month).'),
                'values' => $schema->array()->items($schema->number())
                    ->description('Dollars excluding GST, as said: more income or more cost is positive. One value for every month, or one per month.'),
                'percentage' => $schema->number()->description('Change the forecast by this percent instead, e.g. 10 or -15.'),
                'application_mode' => $schema->string()->enum(['add', 'replace'])->default('add')
                    ->description('add to the forecast (default), or replace it with the values.'),
            ])->description('Change a category\'s forecast over a run of months.'),
            $schema->object([
                'type' => $schema->string()->enum(['asset_purchase'])->required(),
                'description' => $schema->string()->description('What is bought, e.g. "Tractor".'),
                'month' => $month('Month of purchase, YYYY-MM; a forecast month.')->required(),
                'cost' => $schema->number()->required()->description('Price excluding GST.'),
                'trade_in' => $schema->number()->default(0)->description('Trade-in or sale value of the old asset, excluding GST.'),
                'depreciation_rate_pct' => $schema->number()->default(ScenarioChanges::DEFAULT_DEPRECIATION_RATE_PCT)
                    ->description('Diminishing-value depreciation rate, percent a year. Use the farm\'s own rate for this kind of asset if known.'),
                'loan' => $schema->object([
                    'amount' => $schema->number()->required(),
                    'annual_rate_pct' => $schema->number()->required()->description('Interest rate, percent a year, e.g. 7.9.'),
                    'years' => $schema->integer()->required()->description('Term in years; repaid monthly.'),
                ])->description('Finance for the purchase, if any.'),
            ])->description('Buy an asset: the purchase with GST, any trade-in, depreciation, and optional loan finance.'),
        ]))->description('The changes that make up the scenario, together.');
    }
}
