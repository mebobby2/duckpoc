<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dimension data Figured's `DataPipeline` reads and this PoC never had.
 *
 * Every table here answers to a specific pipe in
 * `DataPipelineService::$pipes`, named in the comment above it. They are
 * MySQL for the reason every other dimension table is: production data that is
 * small, relational, user-edited, and read by predicate rather than scanned.
 *
 * Deliberately left out, with the reason:
 *
 * - `_valid_from` / `_valid_to` history — nothing here reads history.
 * - scenarios (`budget_id ≠ 0`) — the overdraft handler refuses them and
 *   `RetainedEarnings` branches on them; out of scope for a report-time port.
 * - snapshots — `ReportingGroupOffsetAccounts` reads merged accounts outside
 *   the annual-plan snapshot; there are no snapshots here to be outside of.
 * - MYOB / Intuit branches — `excludeEoyJournals` is MYOB-only in Figured and
 *   actual GST payments are not detected for MYOB or Intuit. The seed farm is
 *   a Xero-shaped NZ farm and the port follows that branch only.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pipes 9, 10, 15, 16 and Balance::getReport() all key off the farm's
        // financial year; the GST VJ keys off the country.
        Schema::table('farms', function (Blueprint $table): void {
            $table->unsignedTinyInteger('financial_year_end_month')->default(6)->after('region');
            $table->string('country_code', 2)->default('NZ')->after('financial_year_end_month');
        });

        // Pipe 4 / 13 (mapped accounts), 18 (expected sign), 21 (dynamic bank),
        // and Balance::getReport() (BANK / DEPRECIATION types) all read account
        // attributes the original dimension table did not carry.
        Schema::table('accounts', function (Blueprint $table): void {
            // BANK / DEPRECIATION / null — Figured's `xero_accounts.type`.
            $table->string('account_type')->nullable()->after('account_category');
            // GST / GSTPAYMENTS / RETAINED_EARNINGS / CURRENT_YEAR_EARNINGS /
            // LIABILITY / null — Figured's `system_account`.
            $table->string('system_account')->nullable()->after('account_type');
            // An internal Figured account whose cells fold into a Xero account
            // at pipe 13 and are then dropped. Null for real accounts.
            $table->string('mapped_to_account_id')->nullable()->after('system_account');
            // Pipe 18: the user sees this account with its sign flipped.
            $table->boolean('inverted_for_user')->default(false)->after('mapped_to_account_id');
        });

        // Pipes 11 and 20 only run for a reporting-group request: a parent
        // farm whose report is the sum of its child entities.
        Schema::create('reporting_group_farms', function (Blueprint $table): void {
            $table->id();
            $table->string('parent_farm_id');
            $table->string('child_farm_id');
            $table->unique(['parent_farm_id', 'child_farm_id']);
        });

        // Pipe 11: an inter-entity transfer. The `from` entity's account
        // balances are added onto the `to` account and the `from` account is
        // zeroed — including its mapped alias, because pipe 11 runs before
        // pipe 13 folds mapped accounts. Figured's `MergedAccount`.
        Schema::create('merged_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('parent_farm_id');
            $table->string('from_farm_id');
            $table->string('from_account_id');
            $table->string('to_farm_id');
            $table->string('to_account_id');
            $table->index('parent_farm_id');
        });

        // Pipe 20: several child-entity accounts presented as one line on the
        // parent's report. Figured's `ConsolidatedAccounts`.
        Schema::create('consolidated_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('parent_farm_id');
            $table->string('old_account_id');
            $table->string('new_account_id');
            $table->index('parent_farm_id');
        });

        // Pipes 9 and 10, and two of the five terms of Balance::getReport():
        // the user-entered opening position for a budget year. Figured stores
        // these as `Variable` rows keyed `budget_opening_bank_<fy>` and
        // `budget_opening_gst_<fy>`, per tracking entity; a row per
        // (farm, year) is the same thing with a schema.
        Schema::create('opening_balances', function (Blueprint $table): void {
            $table->id();
            $table->string('farm_id');
            $table->unsignedSmallInteger('financial_year');
            // Dollars x 10,000, like every amount in this PoC.
            $table->bigInteger('opening_bank')->default(0);
            $table->bigInteger('opening_gst')->default(0);
            $table->unique(['farm_id', 'financial_year']);
        });

        // The GST payments/refunds VJ (priority 145, merged at pipe 8) needs
        // the farm's tax settings to build its payment schedule. Values are
        // Figured's NZ `GstVersion` constants: period ONEMONTHS / TWOMONTHS /
        // SIXMONTHS, basis INVOICE (accrual) / PAYMENTS (cash).
        Schema::create('gst_settings', function (Blueprint $table): void {
            $table->string('farm_id')->primary();
            $table->string('sales_tax_period')->default('TWOMONTHS');
            $table->string('sales_tax_basis')->default('PAYMENTS');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gst_settings');
        Schema::dropIfExists('opening_balances');
        Schema::dropIfExists('consolidated_accounts');
        Schema::dropIfExists('merged_accounts');
        Schema::dropIfExists('reporting_group_farms');

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['account_type', 'system_account', 'mapped_to_account_id', 'inverted_for_user']);
        });

        Schema::table('farms', function (Blueprint $table): void {
            $table->dropColumn(['financial_year_end_month', 'country_code']);
        });
    }
};
