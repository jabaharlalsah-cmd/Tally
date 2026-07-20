<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voucher lines. Each line debits or credits one ledger by a positive amount;
 * the sum of Dr must equal the sum of Cr for the parent voucher (enforced on the
 * server at commit). line_no preserves entry order. Columns are left open for
 * later phases (bill-wise / cost-centre references) without building them now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('ledger_id')->constrained('ledgers')->restrictOnDelete();
            $table->enum('dr_cr', ['Dr', 'Cr']);
            $table->decimal('amount', 18, 2); // always positive
            $table->unsignedSmallInteger('line_no')->default(0);
            $table->timestamps();

            $table->index('voucher_id');
            $table->index('ledger_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_entries');
    }
};
