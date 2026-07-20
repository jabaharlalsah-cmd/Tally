<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15C — Scenarios (provisional-voucher "what-if" layer).
 *
 * A voucher is EITHER real (scenario_id NULL) or provisional (tagged to exactly one scenario).
 * There is no middle state. Every voucher-reading query defaults to scenario_id IS NULL (the real
 * books, byte-identical to before 15C); a report opts in by selecting scenarios, which widens the
 * filter to `scenario_id IS NULL OR IN (...)`. Promotion is a one-way transactional flip that clears
 * scenario_id (recording promoted_from_scenario_id for the audit trail).
 *
 *  1. company_features.scenarios — the F11 switch, mirroring budgets / ratio_analysis.
 *  2. scenarios — the named containers, company-scoped (BelongsToCompany), archivable via is_active.
 *  3. scenario_promotions — the audit log of one-way promotions (survives scenario deletion).
 *  4. vouchers.scenario_id — the tag (nullable FK, ON DELETE SET NULL as a safety net; promotion
 *     clears it explicitly first, and scenario delete removes its provisional vouchers, so this
 *     default rarely fires). vouchers.promoted_from_scenario_id — audit trail for a now-real voucher.
 *     Composite index (company_id, scenario_id) serves the filtered report queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- 1. F11 switch ----------------------------------------------------
        Schema::table('company_features', function (Blueprint $table) {
            $table->boolean('scenarios')->default(false)->after('ratio_analysis');
        });

        // ---- 2. Scenarios -----------------------------------------------------
        Schema::create('scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('slug', 191);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable(); // central tenant_users.id (no FK)
            $table->boolean('is_active')->default(true); // archived = false (hidden from active pickers)
            $table->timestamps();

            $table->unique(['company_id', 'slug'], 'scenarios_company_slug_unique');
            $table->index(['company_id', 'is_active']);
        });

        // ---- 3. Promotion audit log ------------------------------------------
        Schema::create('scenario_promotions', function (Blueprint $table) {
            $table->id();
            // Nullable + set-null so the audit row survives if the (now-empty) scenario is later
            // deleted; the denormalised name keeps it readable regardless.
            $table->foreignId('scenario_id')->nullable()->constrained('scenarios')->nullOnDelete();
            $table->string('scenario_name', 191);
            $table->unsignedBigInteger('promoted_by_user_id')->nullable();
            $table->unsignedInteger('voucher_count');
            $table->timestamp('promoted_at');
            $table->timestamps();
        });

        // ---- 4. The voucher tag + audit trail --------------------------------
        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreignId('scenario_id')->nullable()->after('company_id')->constrained('scenarios')->nullOnDelete();
            $table->unsignedBigInteger('promoted_from_scenario_id')->nullable()->after('scenario_id');
            $table->index(['company_id', 'scenario_id'], 'vouchers_company_scenario_idx');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropIndex('vouchers_company_scenario_idx');
            $table->dropConstrainedForeignId('scenario_id');
            $table->dropColumn('promoted_from_scenario_id');
        });

        Schema::dropIfExists('scenario_promotions');
        Schema::dropIfExists('scenarios');

        Schema::table('company_features', function (Blueprint $table) {
            $table->dropColumn('scenarios');
        });
    }
};
