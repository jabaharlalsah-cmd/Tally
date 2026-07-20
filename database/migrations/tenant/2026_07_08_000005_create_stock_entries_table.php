<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6A — Stock ledger (forward-compatible structure only; NOTHING writes to
 * it this phase). 6B posts one row per item movement per voucher line; 6C reports
 * on it. quantity/rate use generous precision (15,4) for fractional units; value
 * is stored explicitly in rupees (15,2) so nothing drifts on re-read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->cascadeOnDelete();
            $table->foreignId('godown_id')->nullable()->constrained('godowns')->nullOnDelete();
            $table->enum('direction', ['in', 'out']);
            $table->decimal('quantity', 15, 4);
            $table->decimal('rate', 15, 4);
            $table->decimal('value', 15, 2);
            $table->unsignedInteger('line_no')->default(1);
            $table->timestamps();

            $table->index('stock_item_id');
            $table->index('voucher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_entries');
    }
};
