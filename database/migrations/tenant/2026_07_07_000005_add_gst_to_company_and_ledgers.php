<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5B — GST.
 *
 *  1. Company GST profile on company_features: the company GSTIN + state. The
 *     state drives the intra- vs inter-state determination for every invoice.
 *  2. Ledger GST columns:
 *       - tax_type  (central/state/integrated) — set on the six GST duty ledgers
 *       - tax_role  (output/input)             — output on Sales, input on Purchase
 *       - gst_rate  (decimal)                  — set on Sales/Purchase nominal ledgers
 *       - hsn_sac   (string)                   — HSN/SAC on Sales/Purchase ledgers
 *       - gst_registration_type (string)       — on party ledgers
 *     (gstin + state already exist on ledgers from Phase 2.)
 *
 * All columns are nullable, so nothing before GST is affected. GST behaviour is
 * additionally gated at runtime by the F11 `gst` feature flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_features', function (Blueprint $table) {
            $table->string('company_gstin')->nullable()->after('multi_currency');
            $table->string('company_state')->nullable()->after('company_gstin');
        });

        Schema::table('ledgers', function (Blueprint $table) {
            $table->enum('tax_type', ['central', 'state', 'integrated'])->nullable()->after('gstin');
            $table->enum('tax_role', ['output', 'input'])->nullable()->after('tax_type');
            $table->decimal('gst_rate', 5, 2)->nullable()->after('tax_role');
            $table->string('hsn_sac')->nullable()->after('gst_rate');
            $table->string('gst_registration_type')->nullable()->after('hsn_sac');
        });

        // Give the single company row a sensible default working state so intra/
        // inter can be demonstrated immediately (editable via F11).
        DB::table('company_features')->whereNull('company_state')->update([
            'company_state' => 'Maharashtra',
        ]);
    }

    public function down(): void
    {
        Schema::table('ledgers', function (Blueprint $table) {
            $table->dropColumn(['tax_type', 'tax_role', 'gst_rate', 'hsn_sac', 'gst_registration_type']);
        });
        Schema::table('company_features', function (Blueprint $table) {
            $table->dropColumn(['company_gstin', 'company_state']);
        });
    }
};
