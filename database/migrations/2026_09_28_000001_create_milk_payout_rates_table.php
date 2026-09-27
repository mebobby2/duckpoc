<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dimensions for the actuals-plus-forecast Cash Flow report.
 *
 * `milk_payout_rates` is the price half of Figured's milk tracker virtual
 * journals: forecast milk income is production (already in
 * `tracker_milk_production`) times a per-kg-MS payout, and the payout is a
 * per-month input the farmer maintains. Rates are dollars x 10,000 per kg,
 * so kg x rate lands in the lake's fixed-point unit without a cast.
 *
 * `trackers.income_account_id` is where a milk tracker's forecast income is
 * posted — Figured maps milk trackers to their milk sales account; the lake
 * needs the same edge to synthesise the journal.
 *
 * `accounts.farm_id` scopes system accounts to a farm. Figured's system
 * accounts (GST, GST payments, overdraft interest) are per farm; this PoC's
 * earlier seeders share one unscoped set. Nullable so those seeders are
 * untouched — a NULL farm_id is the shared set — and the report can resolve
 * its own farm's accounts without picking up another farm's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_payout_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('tracker_id');
            $table->date('month');
            $table->bigInteger('advance_rate');
            $table->bigInteger('deferred_rate');
            $table->unique(['tracker_id', 'month']);
        });

        Schema::table('trackers', function (Blueprint $table): void {
            $table->string('income_account_id')->nullable()->after('stock_type');
        });

        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('farm_id')->nullable()->after('account_id');
            $table->index(['farm_id', 'system_account']);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropIndex(['farm_id', 'system_account']);
            $table->dropColumn('farm_id');
        });

        Schema::table('trackers', function (Blueprint $table): void {
            $table->dropColumn('income_account_id');
        });

        Schema::dropIfExists('milk_payout_rates');
    }
};
