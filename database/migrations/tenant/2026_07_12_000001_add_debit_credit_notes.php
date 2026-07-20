<?php

use Database\Seeders\ReturnLedgerSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8A — Debit Notes & Credit Notes (per-tenant).
 *
 *  1. Widen the vouchers.type ENUM to admit 'credit_note' and 'debit_note' (raw
 *     MODIFY, mirroring the Phase 5A / 6C enum-widens — Doctrine can't diff a MySQL
 *     ENUM). No other type is affected.
 *  2. vouchers.reference_voucher_id — the original invoice a Note adjusts (Sales for
 *     a Credit Note, Purchase for a Debit Note). Nullable (a Note can be a free-
 *     standing rate adjustment) and set-null on delete, so a return SURVIVES the
 *     deletion of the invoice it referenced (it becomes free-standing).
 *  3. Seed the Sales Return / Purchase Return nominal ledgers — but only if the chart
 *     of accounts is already seeded (an existing tenant). A freshly-provisioned tenant
 *     runs this migration BEFORE its seeders, so the groups don't exist yet; there the
 *     DatabaseSeeder's ReturnLedgerSeeder creates them right after the groups.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `vouchers` MODIFY `type` ENUM('contra','payment','receipt','journal','sales','purchase','credit_note','debit_note','stock_journal','physical_stock') NOT NULL");

        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreignId('reference_voucher_id')
                ->nullable()
                ->after('reference_date')
                ->constrained('vouchers')
                ->nullOnDelete();
        });

        // Existing tenant (chart already seeded) → create the return ledgers now.
        if (Schema::hasTable('account_groups') && DB::table('account_groups')->where('name', 'Sales Accounts')->exists()) {
            (new ReturnLedgerSeeder())->run();
        }
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reference_voucher_id');
        });
        DB::statement("ALTER TABLE `vouchers` MODIFY `type` ENUM('contra','payment','receipt','journal','sales','purchase','stock_journal','physical_stock') NOT NULL");
    }
};
