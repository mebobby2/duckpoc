<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-head valuation rates, and the actual/forecast split on stock movements —
 * Phase 2's only new data.
 *
 * The quantity half was already built: `tracker_stock_movements` plus
 * `trackers.opening_stock` feed the running head-count window function that
 * Gross Margin V2 has been using since Phase 1b. Phase 2 adds the money half,
 * which in Figured is simply `quantity x per-head value` — no FIFO, no cost
 * layers. The scheme classes (HerdScheme, NationalStandardCost, AusTax) only
 * run for EOY *tax* valuations, a once-a-year user-completed workflow that is
 * not a report-time calculation and is therefore out of scope.
 *
 * Monthly rates rather than annual, matching Figured's `ManagementMonthly`
 * mode. The `ManagementBasic` mode (one closing value per year) is the easier
 * case and falls out of this one by repeating the value across twelve months.
 *
 * **`type` on the movements table is the horizon split**, and it is the one
 * genuinely awkward dimension in the whole handler. Figured's
 * `StockQuantity::addSplitDateRangeToQuery` reads local-actual rows up to the
 * horizon date and forecast rows beyond it, in a single scan, via an OR of two
 * date+type predicates. The lake's `transaction_lines` already carries the
 * same split, so this makes the stock side match — without it the report would
 * silently double-count every month that has both an actual and a forecast row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracker_stock_movements', function (Blueprint $table): void {
            // 'actuals' / 'forecast', spelled exactly as the lake spells it so
            // the two horizon predicates read identically.
            $table->string('type')->default('actuals')->after('month');
        });

        Schema::create('valuation_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('tracker_id');

            // First of the month, matching tracker_stock_movements.
            $table->date('month');

            // Dollars per head x 10,000, the same fixed point the lake stores
            // amounts in. Kept inflated so the port does the same integer
            // deflation Figured does and any rounding difference shows up
            // against the oracle rather than hiding behind a cast.
            $table->bigInteger('per_head_value');

            $table->index(['tracker_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('valuation_rates');

        Schema::table('tracker_stock_movements', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }
};
