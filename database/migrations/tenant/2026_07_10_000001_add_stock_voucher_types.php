<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 6C — Stock Journal (inter-godown transfer / consumption) and Physical
 * Stock (stock-take reconciliation) voucher types.
 *
 * These are pure quantity/value movements: they post rows to `stock_entries` only,
 * NEVER to `voucher_entries` (no money side). The only schema change is widening
 * the `vouchers.type` ENUM to admit the two new types — mirroring the Phase 5A
 * enum-widen exactly (raw MODIFY; Doctrine DBAL cannot diff a MySQL ENUM).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `vouchers` MODIFY `type` ENUM('contra','payment','receipt','journal','sales','purchase','stock_journal','physical_stock') NOT NULL");
    }

    public function down(): void
    {
        // Any stock_journal/physical_stock rows must be removed first; down() only
        // runs on a dev rollback.
        DB::statement("ALTER TABLE `vouchers` MODIFY `type` ENUM('contra','payment','receipt','journal','sales','purchase') NOT NULL");
    }
};
