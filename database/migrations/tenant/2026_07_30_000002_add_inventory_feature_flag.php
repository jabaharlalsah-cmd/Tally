<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NAS parity, Phase 2 — the inventory F11 switch. Tenant database.
 *
 * WHY THIS FLAG DID NOT EXIST BEFORE
 * Every other optional module already has one (cost_centres, gst, vat, tds,
 * multi_currency, budgets, ratio_analysis, scenarios), but inventory was
 * unconditional: a pure-services company still saw Inventory Info, Stock
 * Journal, Physical Stock, the four order/note vouchers, Orders Outstanding,
 * Stock Summary and Lot Provenance on its Gateway. The Gateway rearrangement
 * gates them behind this switch, matching how the approved build presents an
 * accounts-only company.
 *
 * THE BACKFILL IS THE POINT — READ BEFORE CHANGING THE DEFAULT
 * The column defaults to FALSE, because a brand-new company starts with every
 * feature off (the rule CompanyFeature::current() already follows). But
 * defaulting an EXISTING company to false would hide inventory from a business
 * that is actively using it — the data would still be there, silently
 * unreachable from the menu, which reads as data loss to the person looking
 * for it.
 *
 * So: any company that already has stock items or stock movements is switched
 * ON here. It keeps exactly the menu it had yesterday. Only genuinely
 * inventory-free companies get the cleaner accounts-only Gateway.
 *
 * Godown presence is deliberately NOT a signal: every company is seeded with a
 * "Main Location" godown at creation, so it would switch inventory on for
 * everyone and defeat the flag entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_features', function (Blueprint $table) {
            $table->boolean('inventory')->default(false)->after('multi_currency');
        });

        // Preserve the status quo for anyone already keeping stock. Guarded so
        // the migration still runs on a tenant provisioned before those tables
        // existed.
        if (Schema::hasTable('stock_items')) {
            DB::statement('
                UPDATE company_features cf
                SET cf.inventory = 1
                WHERE EXISTS (
                    SELECT 1 FROM stock_items si WHERE si.company_id = cf.company_id
                )
            ');
        }

        if (Schema::hasTable('stock_entries')) {
            DB::statement('
                UPDATE company_features cf
                SET cf.inventory = 1
                WHERE EXISTS (
                    SELECT 1 FROM stock_entries se WHERE se.company_id = cf.company_id
                )
            ');
        }
    }

    public function down(): void
    {
        Schema::table('company_features', function (Blueprint $table) {
            $table->dropColumn('inventory');
        });
    }
};
