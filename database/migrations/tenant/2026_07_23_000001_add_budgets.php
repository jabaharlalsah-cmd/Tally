<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15A — Budgets (per-company target amounts + actual-vs-budget variance).
 *
 * A read-mostly overlay on the balance engine: budgets never touch a posting path.
 * Actuals always come from BalanceService; these tables store only the TARGETS and the
 * per-month allocation, so variance = (BalanceService actual) − (Σ budgeted periods).
 *
 *  1. company_features.budgets — the F11 switch, mirroring gst / vat / tds.
 *
 *  2. budgets — a named container ("FY 2026-27 Budget") for a set of target lines, scoped
 *     to a company (BelongsToCompany). At most one row per company is is_primary=true — the
 *     primary is what variance reports use by default. fiscal_year_start is the STARTING
 *     calendar year, the same integer convention as vouchers.fy_start (2026 => FY 2026-27).
 *
 *  3. budget_lines — one target per ledger OR per group (exactly one of the two, enforced by
 *     a CHECK). annual_target is the headline number; allocation_method decides how it is
 *     spread across the 12 fiscal months into budget_line_periods.
 *
 *  4. budget_line_periods — the RESOLVED per-month targets, so variance can be summed at any
 *     granularity without re-running allocation. `month` is the fiscal-month ordinal (1 = the
 *     company's FY-start month). `revised_from` is null for the ORIGINAL allocation; a revision
 *     writes NEW rows carrying the revision's effective date, only for the months from that
 *     boundary forward — so historical periods stay LOCKED at their original targets. The
 *     effective target for a month is the row with the greatest revised_from ≤ that month
 *     (null = original, the earliest).
 *
 *  5. budget_revisions — the audit log: who revised a budget, when, and why.
 *
 * budget_lines / _periods / _revisions carry no company_id of their own: they are reached only
 * through their parent `budgets` row (already company-scoped) and cascade-delete with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- 1. F11 switch (mirrors add_tds_engine) --------------------------
        Schema::table('company_features', function (Blueprint $table) {
            $table->boolean('budgets')->default(false)->after('multi_currency');
        });

        // ---- 2. Budgets (the container) --------------------------------------
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 191);
            // FY start year, same convention as vouchers.fy_start (2026 => FY 2026-27).
            $table->smallInteger('fiscal_year_start');
            $table->boolean('is_primary')->default(false);
            // The acting tenant user (central tenant_users.id) — audit reference, no FK
            // because tenant_users lives in the CENTRAL database, not this tenant DB.
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'fiscal_year_start']);
            $table->index(['company_id', 'is_primary']);
        });

        // ---- 3. Budget lines (per ledger OR per group) -----------------------
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();
            $table->foreignId('ledger_id')->nullable()->constrained('ledgers')->cascadeOnDelete();
            $table->foreignId('account_group_id')->nullable()->constrained('account_groups')->cascadeOnDelete();
            $table->decimal('annual_target', 18, 2)->default(0);
            $table->enum('allocation_method', ['even', 'custom', 'seasonal'])->default('even');
            $table->text('notes')->nullable();
            $table->timestamps();

            // No duplicate target for the same ledger/group within one budget. MySQL treats
            // NULLs as distinct, so a budget can hold many group-lines (ledger_id NULL) and
            // many ledger-lines (account_group_id NULL) without collision.
            $table->unique(['budget_id', 'ledger_id'], 'budget_lines_budget_ledger_unique');
            $table->unique(['budget_id', 'account_group_id'], 'budget_lines_budget_group_unique');
        });

        // Exactly one of ledger_id / account_group_id is non-null (XOR).
        DB::statement('ALTER TABLE `budget_lines` ADD CONSTRAINT `budget_lines_target_xor` '
            .'CHECK ((`ledger_id` IS NULL) <> (`account_group_id` IS NULL))');

        // ---- 4. Resolved per-month targets -----------------------------------
        Schema::create('budget_line_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_line_id')->constrained('budget_lines')->cascadeOnDelete();
            // Fiscal-month ordinal: 1 = the company's FY-start month (April by default).
            $table->unsignedTinyInteger('month');
            $table->decimal('target_amount', 18, 2)->default(0);
            // null = original allocation; a date = the revision this period target applies from.
            $table->date('revised_from')->nullable();
            $table->timestamps();

            $table->index(['budget_line_id', 'month']);
        });

        // ---- 5. Revision audit log -------------------------------------------
        Schema::create('budget_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();
            $table->date('revised_at');
            $table->unsignedBigInteger('revised_by_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('budget_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_revisions');
        Schema::dropIfExists('budget_line_periods');
        // The CHECK constraint drops with the table.
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');

        Schema::table('company_features', function (Blueprint $table) {
            $table->dropColumn('budgets');
        });
    }
};
