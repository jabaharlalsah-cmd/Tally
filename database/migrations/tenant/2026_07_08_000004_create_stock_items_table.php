<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6A — Stock Items (Tally "Stock Items" master).
 *
 * Under a Stock Group, measured in a Unit, with an opening balance (qty × rate =
 * value) held in a Godown. The gst_rate/hsn_sac columns are the ITEM's OWN tax
 * columns (parallel to the ledger's same-named columns, not shared) — stored this
 * phase, consumed by the tax engine in 6B. costing_method is fixed to
 * 'weighted_average' for now (the column exists so 6B's valuation reads it without
 * a schema change; other methods are a later enhancement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('alias')->nullable();

            $table->foreignId('stock_group_id')->nullable()
                ->constrained('stock_groups')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()
                ->constrained('units')->nullOnDelete();

            // Opening balance (qty × rate = value; value stored explicitly).
            $table->decimal('opening_qty', 15, 4)->default(0);
            $table->decimal('opening_rate', 15, 4)->default(0);
            $table->decimal('opening_value', 15, 2)->default(0);
            $table->foreignId('opening_godown_id')->nullable()
                ->constrained('godowns')->nullOnDelete();

            // The item's own tax attributes (regime-relabelled in the UI, like ledgers).
            $table->decimal('gst_rate', 5, 2)->nullable();
            $table->string('hsn_sac')->nullable();

            // Valuation method — fixed to weighted_average this phase (6B reads it).
            $table->string('costing_method')->default('weighted_average');
            $table->decimal('reorder_level', 15, 4)->nullable();

            $table->timestamps();

            $table->unique('name');
            $table->index('stock_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_items');
    }
};
