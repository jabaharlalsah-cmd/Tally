<?php

use Database\Seeders\TaxLedgerSeeder;
use Database\Seeders\TdsSectionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10A — the TDS deduction engine (per-tenant).
 *
 *  1. company_features.tds — the F11 switch, mirroring gst / vat / cost_centres.
 *
 *  2. tds_sections — THE RATE TABLE. Rates and thresholds are DATA, never code, so
 *     next year's Finance Act is a row edit, not a deploy. Sections are effective-dated
 *     by fiscal year (`effective_from` / `effective_to` hold the FY START year, the same
 *     convention as vouchers.fy_start), because the entire non-salary TDS regime moved
 *     from the old 194-series to Section 393 of the Income Tax Act 2025 on 1 April 2026.
 *     A book will therefore contain BOTH — historical vouchers under 194J, current ones
 *     under 393-194J — so the catalog carries both and the voucher's own fiscal year
 *     decides which is legal. `unique(code, effective_from)` lets the SAME code be
 *     re-dated when only its threshold changed (194I-B: annual 2,40,000 → monthly 50,000).
 *
 *  3. tds_deductee_ytd — the stateful running total per (deductee, section, fiscal year).
 *     This is what makes the threshold decision possible: TDS law aggregates over the
 *     year, and once a threshold is crossed you catch up on everything paid before it.
 *
 *  4. tds_deductions — one row per TDS-engaged Payment voucher, INCLUDING the ones that
 *     deducted nothing. The zero rows are not noise: they are the audit trail of a
 *     below-threshold payment, the reconstruction source for a monthly-threshold section
 *     (194I), the amount-paid figure Form 26Q reports, and what makes reverseFor()
 *     uniform on alter/cancel.
 *
 *  5. ledgers — deductee tagging (PAN, type, default section) + the tax_type ENUM widened
 *     to admit 'tds'. The TDS Payable duty ledger carries tax_type='tds' and, deliberately,
 *     tax_role=NULL: GstService::taxLedgerMap() selects whereNotNull('tax_type')->whereNotNull('tax_role'),
 *     so a NULL role keeps the TDS ledger completely invisible to the GST/VAT engines.
 *
 * Ordering matters: tds_sections must exist before ledgers.default_tds_section_id can
 * reference it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- 1. F11 switch ---------------------------------------------------
        Schema::table('company_features', function (Blueprint $table) {
            $table->boolean('tds')->default(false)->after('vat');
        });

        // ---- 2. The rate table ----------------------------------------------
        Schema::create('tds_sections', function (Blueprint $table) {
            $table->id();
            // '194J' (old) | '393-194J' (Income Tax Act 2025). Free text — the user
            // may add whatever the Finance Act names next.
            $table->string('code', 30);
            $table->string('label', 191);

            // The default rate (individuals / HUF, and everyone when rate_company is null).
            $table->decimal('rate', 5, 2);
            // Some sections deduct at a higher rate from companies / firms / LLPs
            // (194C: 1% individual, 2% company). Null = one rate for everyone.
            $table->decimal('rate_company', 5, 2)->nullable();
            // Section 206AA floor when the deductee supplies no PAN. Null = the statutory
            // default of 20%. The proviso to 206AA(1) sets it to 5% for 194Q and 194O, so
            // that exception is DATA, not another branch in the engine.
            $table->decimal('no_pan_rate', 5, 2)->nullable();

            // The single-transaction threshold (194C: ₹30,000). Null = not applicable.
            $table->decimal('threshold_single', 15, 2)->nullable();
            // The AGGREGATE threshold (194J: ₹50,000/yr; 194I-B: ₹50,000/month). Null = none.
            $table->decimal('threshold_annual', 15, 2)->nullable();
            // The window threshold_annual aggregates over. 194I moved to a per-month
            // threshold in April 2025 — this is how that quirk stays data, not code.
            $table->enum('threshold_period', ['annual', 'monthly'])->default('annual');
            // Once the threshold is crossed: deduct on the WHOLE aggregate (the normal
            // rule — you catch up on prior below-threshold payments), or only on the
            // EXCESS above the threshold (194Q: 0.1% on purchases beyond ₹50 lakh).
            $table->enum('deduct_basis', ['aggregate', 'excess'])->default('aggregate');

            // Fiscal-year START years, matching vouchers.fy_start (2026 => FY 2026-27).
            $table->smallInteger('effective_from');
            $table->smallInteger('effective_to')->nullable(); // null = still in force
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['code', 'effective_from']);
            $table->index(['effective_from', 'effective_to']);
        });

        // ---- 3. Threshold state ----------------------------------------------
        Schema::create('tds_deductee_ytd', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deductee_ledger_id')->constrained('ledgers')->cascadeOnDelete();
            $table->foreignId('tds_section_id')->constrained('tds_sections')->cascadeOnDelete();
            $table->smallInteger('fy_start');
            // Running Σ of the taxable BASE amounts paid to this deductee under this
            // section this fiscal year (pre-GST — see TdsService).
            $table->decimal('paid_amount', 18, 2)->default(0);
            // Running Σ of TDS actually deducted.
            $table->decimal('deducted_amount', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['deductee_ledger_id', 'tds_section_id', 'fy_start'], 'tds_ytd_unique');
        });

        // ---- 4. Per-voucher deduction record ---------------------------------
        Schema::create('tds_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            // The Cr TDS Payable line. NULL when nothing was deducted (below threshold)
            // — there is no third line on such a voucher, but the row still exists.
            $table->foreignId('voucher_entry_id')->nullable()->constrained('voucher_entries')->nullOnDelete();
            $table->foreignId('deductee_ledger_id')->constrained('ledgers')->cascadeOnDelete();
            $table->foreignId('tds_section_id')->constrained('tds_sections')->restrictOnDelete();

            // THIS voucher's own taxable base (the pre-GST expense it paid). What Form
            // 26Q reports as "amount paid", and what accumulates into tds_deductee_ytd.
            $table->decimal('payment_amount', 18, 2);
            // The base the deduction was actually COMPUTED on. Equal to payment_amount
            // in the steady state; equal to the full year-to-date aggregate on the
            // voucher that first crosses the threshold (the catch-up voucher).
            $table->decimal('base_amount', 18, 2);
            // The EFFECTIVE rate applied — after the deductee-type choice and after
            // Section 206AA (no PAN => max(rate, 20)). Never re-derivable from the
            // section alone, so it is stored.
            $table->decimal('rate', 5, 2);
            $table->decimal('deducted_amount', 18, 2);
            $table->smallInteger('fy_start');
            // Human-readable explanation, shown on the screen and in the summary report.
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['deductee_ledger_id', 'tds_section_id', 'fy_start'], 'tds_ded_state_idx');
        });

        // ---- 5. Ledger: deductee tagging + the 'tds' tax_type -----------------
        DB::statement("ALTER TABLE `ledgers` MODIFY `tax_type` ENUM('central','state','integrated','vat','tds') NULL");

        Schema::table('ledgers', function (Blueprint $table) {
            // Deliberately distinct from the existing `pan` column: `pan` is the party's
            // general/Nepal-VAT PAN, `deductee_pan` is the PAN quoted on a TDS return.
            // Only deductee_pan drives Section 206AA.
            $table->string('deductee_pan', 10)->nullable()->after('gst_registration_type');
            $table->enum('deductee_type', ['individual_huf', 'company_firm_llp', 'other'])
                ->nullable()->after('deductee_pan');
            $table->foreignId('default_tds_section_id')->nullable()->after('deductee_type')
                ->constrained('tds_sections')->nullOnDelete();
        });

        // ---- 6. Seed ----------------------------------------------------------
        // The catalog has no dependencies — always seed it.
        (new TdsSectionSeeder())->run();

        // The TDS Payable duty ledger needs "Duties & Taxes". On an EXISTING tenant the
        // chart is already seeded, so create it now. A freshly-provisioned tenant runs
        // migrations BEFORE seeders, so there the DatabaseSeeder's TaxLedgerSeeder
        // (extended with TDS Payable) creates it right after the groups.
        if (Schema::hasTable('account_groups') && DB::table('account_groups')->where('name', 'Duties & Taxes')->exists()) {
            (new TaxLedgerSeeder())->run();
        }
    }

    /**
     * Order matters here, and the obvious order is wrong.
     *
     * The tax_type ENUM cannot be narrowed back to four values while a row still holds
     * 'tds' — MySQL refuses with a data-truncation error. So the seeded TDS Payable ledger
     * has to go FIRST. Deleting it also surfaces the right failure: if any voucher entry
     * still points at it, the foreign key blocks the delete rather than letting a rollback
     * quietly orphan a posted deduction.
     *
     * Likewise the deduction tables reference tds_sections, so they are dropped before it.
     */
    public function down(): void
    {
        // 1. The deduction records first — they reference both ledgers and tds_sections.
        Schema::dropIfExists('tds_deductions');
        Schema::dropIfExists('tds_deductee_ytd');

        // 2. The ledger's reference to the catalog, then the catalog itself.
        if (Schema::hasColumn('ledgers', 'default_tds_section_id')) {
            Schema::table('ledgers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('default_tds_section_id');
            });
        }
        Schema::table('ledgers', function (Blueprint $table) {
            $table->dropColumn(['deductee_pan', 'deductee_type']);
        });
        Schema::dropIfExists('tds_sections');

        // 3. The reserved TDS Payable ledger must go before the ENUM can be narrowed.
        //    A foreign key from voucher_entries blocks this if the ledger was ever posted
        //    to — which is exactly the error a rollback should raise, not swallow.
        DB::table('ledgers')->where('tax_type', 'tds')->delete();
        DB::statement("ALTER TABLE `ledgers` MODIFY `tax_type` ENUM('central','state','integrated','vat') NULL");

        Schema::table('company_features', function (Blueprint $table) {
            $table->dropColumn('tds');
        });
    }
};
