<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5D — cost allocations.
 *
 * One row per cost-centre allocation on a voucher line. A cost-applicable ledger's
 * line amount is fully split across these rows. Cost allocations are an ANALYTICAL
 * tag only — they add no accounting entry, so the Trial Balance is unaffected;
 * only the cost-centre reports read them.
 *
 * Cascades away with the voucher (and is rewritten wholesale on alter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('ledger_id')->constrained('ledgers')->cascadeOnDelete();
            $table->foreignId('voucher_entry_id')->nullable()->constrained('voucher_entries')->nullOnDelete();
            $table->foreignId('cost_centre_id')->constrained('cost_centres')->cascadeOnDelete();
            $table->decimal('amount', 18, 2); // magnitude; the entry's Dr/Cr gives the sense
            $table->timestamps();

            $table->index('cost_centre_id');
            $table->index('voucher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_allocations');
    }
};
