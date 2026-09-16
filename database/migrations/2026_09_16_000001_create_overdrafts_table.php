<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Overdraft configuration — Phase 3's only new dimension.
 *
 * MySQL, not the lake, for the same reason trackers and accounts are: this is
 * production data, small, mutable and edited by users. A farm has a handful of
 * rows here over its whole life.
 *
 * Mirrors Figured's `inputs_overdraft`, minus four things that are deliberately
 * out of scope:
 *
 * - `budget_id` / `budget_type` — scenarios. The PoC reports the rolling plan
 *   only, and Figured's own service refuses a non-zero budget id.
 * - `utilisation` — legacy. It feeds an older mechanism that writes real
 *   transactions (`InterestTransactionLogic`), not the report-time calculation
 *   ported here. Figured's own README carries a TODO about it.
 * - `account_id` — always the same internal Overdraft account, so a column
 *   holding one value everywhere would be noise.
 * - `_valid_from` / `_valid_to` — bitemporal versioning. Figured keeps every
 *   revision and filters `_valid_to IS NULL`; nothing in this PoC reads history,
 *   and adding it would mean every query carrying a predicate that is always
 *   the same.
 *
 * `limit` is stored but unused by the interest calculation. It drives the
 * "Overdraft Limit" and "Overdraft Headroom" report rows, which are a separate
 * mechanism (dynamic rows, not virtual journals) — so it is here to make that
 * next step possible, not because interest needs it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overdrafts', function (Blueprint $table): void {
            $table->id();
            $table->string('farm_id');

            // Annual percentage x 10,000, matching Figured: 5% is 50000. Kept
            // inflated rather than a DECIMAL so the port does the same integer
            // deflation Figured does, and any rounding difference shows up
            // against the oracle instead of hiding behind a cast.
            $table->integer('rate');

            // Dollars x 10,000. Unused by interest; see the class docblock.
            $table->bigInteger('overdraft_limit')->default(0);

            // Effective-from month. The config in force for a month is the one
            // with the latest start_date on or before that month's end, so
            // several rows are a time series rather than concurrent facilities.
            $table->date('start_date');

            // Drives WHICH months the accrued interest is posted in, not how
            // much accrues. The repayment calendar counts forward from this
            // row's own start month, not from the financial year.
            $table->string('payment_term')->default('interest_only_monthly');

            $table->index(['farm_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overdrafts');
    }
};
