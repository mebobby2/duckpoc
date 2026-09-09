<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds what Gross Margin V2 needs: milk production, and a grouping dimension
 * that drives the report's section hierarchy.
 *
 * V1 handled one tracker type (livestock, in head) and produced a flat table
 * per tracker. The real report is a **hierarchy over mixed tracker types**:
 *
 *     Income
 *       Dairy Income
 *         Milk Production - Current Year
 *         Milk Production - Deferred
 *         Dairy Income Total
 *       Livestock Income
 *         Sales - Bobby Calves
 *         ...
 *         Livestock Income Total
 *       Other
 *     Income Total
 *
 * Figured cannot build that in one pass, and the reason is architectural
 * rather than incidental: milk and livestock quantities come from different
 * services (`TrackerQuantityService` branches `case 'milk'` to
 * `getMilkQuantities()` and `case 'tracker'` to `getStockQuantities()`), and
 * the two structure builders deliberately exclude each other —
 * `LivestockGrossMarginStructureBuilder` skips the milk virtual journal, while
 * `DairyGrossMarginStructureBuilder` pulls in `MilkStructureBuilder`. So a
 * combined report means running both and stitching the results.
 *
 * `accounts.report_group` is what makes the hierarchy declarative here: the
 * section an account belongs to is data, not a branch in a builder, so one
 * GROUPING SETS query can emit line items, section subtotals and grand totals
 * together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            // e.g. dairy_income, livestock_income, other_income,
            // dairy_costs, livestock_costs. Nullable because the existing
            // Cash Flow accounts have no place in a GM hierarchy.
            $table->string('report_group')->nullable()->after('account_category');
            $table->string('report_group_label')->nullable()->after('report_group');
            $table->unsignedSmallInteger('report_group_order')->default(0)->after('report_group_label');
            $table->unsignedSmallInteger('line_order')->default(0)->after('report_group_order');

            $table->index('report_group');
        });

        /**
         * Milk production per tracker per month, in kilograms of milk solids.
         *
         * Separate table from `tracker_stock_movements` on purpose — it mirrors
         * Figured's split, and the two genuinely have different shapes: stock
         * is a running balance built from movements, milk is a flow measured
         * directly. Keeping them apart is also what lets the report prove they
         * can still be resolved in one query despite that.
         *
         * `deferred` exists because dairy payout is partly paid in a later
         * season, and the report shows current-year and deferred as separate
         * income lines — visible in the real report as "Milk Production -
         * Current Year" and "Milk Production - Deferred".
         */
        Schema::create('tracker_milk_production', function (Blueprint $table): void {
            $table->id();
            $table->string('tracker_id');
            $table->date('month');
            $table->integer('kg_ms_current')->default(0);
            $table->integer('kg_ms_deferred')->default(0);

            $table->index(['tracker_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_milk_production');

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['report_group', 'report_group_label', 'report_group_order', 'line_order']);
        });
    }
};
