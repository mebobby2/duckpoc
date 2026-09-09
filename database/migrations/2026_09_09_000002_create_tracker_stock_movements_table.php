<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock quantities per tracker per month — the missing half of Gross Margin.
 *
 * Gross Margin is not income minus costs. Figured's `BaseGrossMargin` emits a
 * financial row *and* a quantity row per tracker, and per-unit figures are the
 * one divided by the other (see `AssumptionsProcessorService`: "the LLM derives
 * price per head from the report (financial row / quantity row)"). Without
 * quantities there is no margin, only a profit — which is what this PoC's
 * tracker Cash Flow report had until now.
 *
 * MySQL, not the lake, for the same reason trackers are: production data is
 * relational, small, mutable and edited by users. Figured stores stock
 * movements this way too. Volume confirms it — the 500M-row hero farm needs
 * 50 trackers x 30 years x 12 months = 18,000 rows here.
 *
 * **Movements only, no opening balance column.** Opening stock for a month is
 * every prior movement accumulated, so storing it per month would duplicate
 * derivable state and let the two disagree. The report derives it with a
 * window function instead, seeded from `trackers.opening_stock`. That running
 * chain is the part Mongo cannot express and PHP therefore loops over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trackers', function (Blueprint $table): void {
            // Head count before the first recorded movement — the seed the
            // running stock chain accumulates from.
            $table->integer('opening_stock')->default(0)->after('stock_type');
        });

        Schema::create('tracker_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->string('tracker_id');
            // First of the month. Monthly granularity because that is the
            // granularity every report interval uses.
            $table->date('month');

            // Head counts. Split by movement type because Gross Margin shows
            // them as separate rows, not just a net figure.
            $table->integer('purchases')->default(0);
            $table->integer('births')->default(0);
            $table->integer('sales')->default(0);
            $table->integer('deaths')->default(0);

            // The report reads a tracker's whole history to derive opening
            // stock, then filters to the period — so this is the lookup.
            $table->index(['tracker_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_stock_movements');

        Schema::table('trackers', function (Blueprint $table): void {
            $table->dropColumn('opening_stock');
        });
    }
};
