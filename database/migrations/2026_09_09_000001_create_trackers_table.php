<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trackers live in MySQL, not the lake — matching Figured, where `trackers`
 * is an Eloquent-managed MySQL table (see its
 * `add_inter_tracker_transfer_accounts_to_trackers_table`,
 * `tracker_season_stock_class` and `stock_classes_production_categorisation`
 * migrations) while journals sit in the document store.
 *
 * The division that matters: a tracker's *identity and attributes* are
 * dimension data and belong here; the only thing that reaches the lake is
 * `transaction_lines.tracker_id`, the foreign key tagged onto each journal
 * line. Production quantities — stock movements, milk volumes, crop yields —
 * are also MySQL in Figured, and are Phase 2's subject, not this one's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trackers', function (Blueprint $table): void {
            $table->string('tracker_id')->primary();
            $table->string('farm_id');
            $table->string('tracker_name');
            // livestock / milk / crops. Only livestock is exercised here.
            $table->string('tracker_type');
            // Sheep, Cattle, Deer — the grouping Figured's
            // LivestockStructureBuilder offers as its "by stock type" format.
            $table->string('stock_type');
            $table->unsignedSmallInteger('display_order')->default(0);

            // A report resolves every tracker for one farm, ordered for
            // display — this is that lookup.
            $table->index(['farm_id', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trackers');
    }
};
