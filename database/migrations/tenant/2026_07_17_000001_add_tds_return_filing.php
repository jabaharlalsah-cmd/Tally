<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10B — Form 26Q quarterly return-file exporter (per-tenant).
 *
 * READ-ONLY over the Phase 10A deduction engine: 10B adds the challan (remittance)
 * identifiers and the deductor's filing identity, then EXPORTS. It never alters
 * tds_sections / tds_deductions / tds_deductee_ytd.
 *
 *  1. tds_challans — the real bank/book identifiers for each remittance to the
 *     government. In 10A, remitting TDS is an ordinary Payment (Dr TDS Payable / Cr
 *     Bank); the deduction→remittance mapping is FIFO. But a real 26Q return must carry,
 *     per remittance, the BSR code (deductor's bank branch), the 5-digit challan serial
 *     from the bank stamp, and the actual deposit date. This table captures those.
 *
 *  2. company_features — the deductor's filing identity. A 26Q Batch Header is
 *     mandatory-heavy: TAN, deductor name/address/state/PIN/email/phone/category, and a
 *     full "responsible person" block (name, designation, PAN, address, state, PIN, email,
 *     phone). None of this existed before; the exporter REFUSES to run until it is set,
 *     rather than emit placeholders the FVU would reject. The company PAN (BH field 15)
 *     reuses the existing company_features.company_pan.
 *
 *  3. tds_return_filings — the post-filing acknowledgement log (token/receipt), recorded
 *     by the CA after the portal accepts the return. One row per (fiscal year, quarter).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- 1. Challan (remittance) identifiers -----------------------------
        Schema::create('tds_challans', function (Blueprint $table) {
            $table->id();
            // The remittance Payment voucher (Dr TDS Payable / Cr Bank). One challan per
            // remittance; unique so a voucher can never carry two conflicting identifiers.
            $table->foreignId('voucher_id')->unique()->constrained('vouchers')->cascadeOnDelete();
            // BSR code of the deductor's bank branch — 7 digits (CD field 15).
            $table->string('bsr_code', 7)->nullable();
            // The 5-digit challan serial number from the bank stamp (CD field 17).
            $table->string('challan_number', 5)->nullable();
            // The actual bank deposit date, which may differ from the voucher date (CD field 19).
            $table->date('deposit_date')->nullable();
            // The amount deposited (should equal the voucher's Dr TDS Payable). Whole rupees.
            $table->decimal('total_amount', 18, 2)->default(0);
            // Minor head of challan (Annexure 7) — 200 = TDS payable by taxpayer (CD field 23).
            $table->string('minor_head', 3)->default('200');
            $table->timestamps();
        });

        // ---- 2. Deductor filing identity ------------------------------------
        Schema::table('company_features', function (Blueprint $table) {
            // The Tax Deduction Account Number the deductor files under — DISTINCT from the
            // company PAN. Required whenever a 26Q is exported.
            $table->string('company_tan', 10)->nullable()->after('company_pan');

            // Deductor (BH fields 19-32).
            $table->string('deductor_name', 75)->nullable()->after('company_tan');
            $table->string('deductor_address1', 25)->nullable()->after('deductor_name');
            $table->string('deductor_address2', 25)->nullable()->after('deductor_address1');
            $table->string('deductor_state_code', 2)->nullable()->after('deductor_address2');
            $table->string('deductor_pincode', 6)->nullable()->after('deductor_state_code');
            $table->string('deductor_email', 75)->nullable()->after('deductor_pincode');
            $table->string('deductor_phone', 10)->nullable()->after('deductor_email');
            // Deductor category code (Annexure 4): K = Company, Q = Individual/HUF, etc.
            $table->string('deductor_type', 1)->nullable()->after('deductor_phone');

            // Person responsible for deduction (BH fields 33-59) — the signatory.
            $table->string('resp_name', 75)->nullable()->after('deductor_type');
            $table->string('resp_designation', 20)->nullable()->after('resp_name');
            $table->string('resp_pan', 10)->nullable()->after('resp_designation');
            $table->string('resp_address1', 25)->nullable()->after('resp_pan');
            $table->string('resp_state_code', 2)->nullable()->after('resp_address1');
            $table->string('resp_pincode', 6)->nullable()->after('resp_state_code');
            $table->string('resp_email', 75)->nullable()->after('resp_pincode');
            $table->string('resp_phone', 10)->nullable()->after('resp_email');
        });

        // ---- 3. Post-filing acknowledgement log -----------------------------
        Schema::create('tds_return_filings', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('fy_start');           // fiscal-year START, e.g. 2026 for FY 2026-27
            $table->unsignedTinyInteger('quarter');     // 1-4
            $table->timestamp('filed_at')->nullable();  // when the portal accepted it
            $table->string('token_no', 15)->nullable(); // the acknowledgement / provisional receipt number
            $table->string('receipt_no', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['fy_start', 'quarter']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tds_return_filings');
        Schema::table('company_features', function (Blueprint $table) {
            $table->dropColumn([
                'company_tan',
                'deductor_name', 'deductor_address1', 'deductor_address2', 'deductor_state_code',
                'deductor_pincode', 'deductor_email', 'deductor_phone', 'deductor_type',
                'resp_name', 'resp_designation', 'resp_pan', 'resp_address1', 'resp_state_code',
                'resp_pincode', 'resp_email', 'resp_phone',
            ]);
        });
        Schema::dropIfExists('tds_challans');
    }
};
