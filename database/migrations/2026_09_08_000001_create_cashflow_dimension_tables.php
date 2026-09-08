<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts and farms live in MySQL, not the lake.
 *
 * This mirrors Figured's own data placement: `farms`, `xero_accounts` and
 * `categories` are MySQL tables (347 / 276,895 / 41,641 rows in the dev
 * database), and only the financial line data sits in the document store.
 *
 * They belong here rather than in DuckLake for reasons beyond fidelity:
 * they are small, mutable, read on every request, and want real uniqueness
 * constraints — DuckLake supports no PRIMARY KEY/UNIQUE at all, and its
 * append-plus-tombstone file model turns repeated dimension edits into
 * delete-file churn that has to be read and reconciled on every query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farms', function (Blueprint $table): void {
            $table->string('farm_id')->primary();
            $table->string('farm_type');
            $table->string('region');
            // Fixed-point x10,000, matching Figured's TEN_THOUSAND convention
            // and the amounts in transaction_lines.
            $table->bigInteger('opening_balance')->default(0);

            // The cohort a farm belongs to is how a report resolves which
            // Parquet partition to read, so it is looked up by this pair.
            $table->index(['farm_type', 'region']);
        });

        Schema::create('accounts', function (Blueprint $table): void {
            $table->string('account_id')->primary();
            $table->string('account_name');
            // REVENUE / EXPENSE / ASSET / LIABILITY / EQUITY. Drives the
            // display sign: only REVENUE is inverted, mirroring
            // XeroAccount::isAccountInversedForUser().
            $table->string('account_class');
            // Drives which report section a line lands in.
            $table->string('account_category');
            $table->boolean('is_gst_account')->default(false);
            $table->boolean('is_default_bank_account')->default(false);

            $table->index('account_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('farms');
    }
};
