<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5E — Nepal VAT (a second, mutually-exclusive tax regime alongside GST).
 *
 *  1. Widen ledgers.tax_type (a DB enum) to admit 'vat', so the two reserved VAT
 *     duty ledgers (Output/Input VAT) can be tagged the same way the GST duty
 *     ledgers are. tax_role stays output/input.
 *  2. company_features: a `vat` boolean (mutually exclusive with `gst`, enforced
 *     in the app) and a `company_pan` (the company's VAT/PAN tax-id, the VAT
 *     analogue of company_gstin).
 *
 * The rate/HS-code columns (gst_rate/hsn_sac) and the party gstin column are
 * REUSED for VAT — relabelled in the UI — never duplicated.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `ledgers` MODIFY `tax_type` ENUM('central','state','integrated','vat') NULL");

        Schema::table('company_features', function (Blueprint $table) {
            $table->boolean('vat')->default(false)->after('gst');
            $table->string('company_pan')->nullable()->after('company_state');
        });
    }

    public function down(): void
    {
        Schema::table('company_features', function (Blueprint $table) {
            $table->dropColumn(['vat', 'company_pan']);
        });

        // Remove any VAT ledgers first so the enum can be narrowed back.
        DB::table('ledgers')->where('tax_type', 'vat')->update(['tax_type' => null, 'tax_role' => null]);
        DB::statement("ALTER TABLE `ledgers` MODIFY `tax_type` ENUM('central','state','integrated') NULL");
    }
};
