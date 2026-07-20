<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5C — bill-wise details.
 *
 * One row per bill reference allocation on a voucher line. A bill-wise ledger's
 * line amount is fully split across these rows (New Ref / Against Ref / Advance /
 * On Account). A "bill" is the set of allocations sharing (ledger_id, ref_name);
 * its pending = the signed sum of those allocations (Dr-terms), reconciling with
 * the ledger balance.
 *
 * Cascades away with the voucher (and is rewritten wholesale on alter), so the
 * allocations never outlive the postings they belong to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('ledger_id')->constrained('ledgers')->cascadeOnDelete();
            // The voucher line this allocation belongs to (nulled if the entry is
            // ever removed independently; normally rewritten with the voucher).
            $table->foreignId('voucher_entry_id')->nullable()->constrained('voucher_entries')->nullOnDelete();
            $table->enum('ref_type', ['new', 'against', 'advance', 'onaccount']);
            $table->string('ref_name');
            $table->decimal('amount', 18, 2); // magnitude; sign comes from the entry's Dr/Cr
            $table->date('due_date')->nullable();
            $table->timestamps();

            $table->index(['ledger_id', 'ref_name']);
            $table->index('voucher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_allocations');
    }
};
