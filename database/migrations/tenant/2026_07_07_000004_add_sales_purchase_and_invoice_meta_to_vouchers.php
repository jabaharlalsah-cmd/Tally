<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5A — Sales (F8) / Purchase (F9) vouchers + "as Invoice" metadata.
 *
 *  1. vouchers.type is an ENUM, so it must be widened to admit 'sales' and
 *     'purchase' (a plain string column would need no change).
 *  2. Invoice mode records a party ledger, a supplier/reference invoice number
 *     and a reference date on the voucher header. All three are NULLABLE so the
 *     existing Contra/Payment/Receipt/Journal vouchers are completely unaffected
 *     and continue to post through the same balanced double-entry path.
 */
return new class extends Migration
{
    public function up(): void
    {
        // (1) Widen the type enum. Doctrine DBAL does not understand MySQL ENUM
        //     changes, so this is a raw, idempotent-friendly MODIFY.
        DB::statement("ALTER TABLE `vouchers` MODIFY `type` ENUM('contra','payment','receipt','journal','sales','purchase') NOT NULL");

        // (2) Invoice metadata columns.
        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreignId('party_ledger_id')
                ->nullable()
                ->after('fy_start')
                ->constrained('ledgers')
                ->nullOnDelete();
            $table->string('reference_no')->nullable()->after('party_ledger_id');
            $table->date('reference_date')->nullable()->after('reference_no');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('party_ledger_id');
            $table->dropColumn(['reference_no', 'reference_date']);
        });

        // Restore the original enum. (Any sales/purchase rows must be removed
        // first; in practice down() only runs on a dev rollback.)
        DB::statement("ALTER TABLE `vouchers` MODIFY `type` ENUM('contra','payment','receipt','journal') NOT NULL");
    }
};
