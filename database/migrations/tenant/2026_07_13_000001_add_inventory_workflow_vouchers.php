<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8B — inventory-workflow vouchers (Orders, Delivery/Receipt Notes, Rejections).
 *
 *  1. Widen vouchers.type ENUM to admit the six new types (raw MODIFY, mirroring the
 *     earlier enum-widens — every existing value kept).
 *  2. order_lines — one outstanding commitment per item on a Sales/Purchase Order.
 *     delivered_qty is a maintained cache of the sum of its fulfillments.
 *  3. order_fulfillments — the audit trail: each Delivery/Receipt Note that fulfils
 *     (part of) an order line adds a row, so delivered_qty is always re-derivable and
 *     reversal on alter/cancel is deterministic.
 *
 * NONE of these post voucher_entries — the Trial Balance is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `vouchers` MODIFY `type` ENUM('contra','payment','receipt','journal','sales','purchase','credit_note','debit_note','sales_order','purchase_order','delivery_note','receipt_note','rejection_out','rejection_in','stock_journal','physical_stock') NOT NULL");

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->cascadeOnDelete();
            $table->foreignId('godown_id')->nullable()->constrained('godowns')->nullOnDelete();
            $table->decimal('ordered_qty', 15, 4);
            $table->decimal('delivered_qty', 15, 4)->default(0); // = Σ fulfillments (maintained)
            $table->decimal('rate', 15, 4)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedInteger('line_no')->default(1);
            $table->timestamps();

            $table->index('voucher_id');
            $table->index('stock_item_id');
        });

        Schema::create('order_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_line_id')->constrained('order_lines')->cascadeOnDelete();
            $table->foreignId('fulfillment_voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->decimal('qty', 15, 4);
            $table->unsignedInteger('line_no')->default(1);
            $table->timestamps();

            $table->index('order_line_id');
            $table->index('fulfillment_voucher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fulfillments');
        Schema::dropIfExists('order_lines');
        DB::statement("ALTER TABLE `vouchers` MODIFY `type` ENUM('contra','payment','receipt','journal','sales','purchase','credit_note','debit_note','stock_journal','physical_stock') NOT NULL");
    }
};
