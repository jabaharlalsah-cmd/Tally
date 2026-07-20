<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15B — Ratio Analysis (per-company financial ratios + configurable health thresholds).
 *
 * A read-only overlay on the balance engine: ratios are compositions of BalanceService figures
 * and touch no posting path. Only two things are stored:
 *
 *  1. company_features.ratio_analysis — the F11 switch, mirroring gst / vat / tds / budgets.
 *
 *  2. ratio_thresholds — the per-company colour bands for each ratio. `green_min` / `amber_min`
 *     are the boundaries; how they are read depends on the ratio's DIRECTION (a higher-is-better
 *     ratio is green when value ≥ green_min, a lower-is-better ratio is green when value ≤ green_min).
 *     Direction is intrinsic to the ratio and lives in RatioService, not here. `red_min` is an
 *     optional hard floor. Seeded with industry-neutral defaults; the Thresholds screen edits them.
 *     No company_id is stored on the seed — BelongsToCompany stamps + scopes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- 1. F11 switch (mirrors add_budgets) -----------------------------
        Schema::table('company_features', function (Blueprint $table) {
            $table->boolean('ratio_analysis')->default(false)->after('budgets');
        });

        // ---- 2. Per-company colour bands -------------------------------------
        Schema::create('ratio_thresholds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('ratio_key', 40);
            // Boundary values in the ratio's own unit (a plain ratio like 1.5, or a percentage
            // like 30 for 30%). Nullable so a ratio can opt out of a band.
            $table->decimal('green_min', 12, 4)->nullable();
            $table->decimal('amber_min', 12, 4)->nullable();
            $table->decimal('red_min', 12, 4)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'ratio_key'], 'ratio_thresholds_company_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratio_thresholds');

        Schema::table('company_features', function (Blueprint $table) {
            $table->dropColumn('ratio_analysis');
        });
    }
};
