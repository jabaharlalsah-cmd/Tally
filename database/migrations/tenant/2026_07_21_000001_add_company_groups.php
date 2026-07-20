<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12B — company groups + inter-company transaction tagging (per-tenant).
 *
 * Grouping is OPT-IN and inert by default: a tenant that never creates a group
 * behaves exactly as a 12A tenant (a CA firm's unrelated client books never see
 * any of this). Once companies are grouped, transactions between them MUST carry
 * an inter-company tag — derived server-side, never trusted from the client —
 * which is what Phase 12C's consolidation eliminates to avoid double-counting.
 *
 *  1. company_groups — the group master, a TENANT-level concept (deliberately NO
 *     company_id: a group spans companies, so it sits above the 12A scope).
 *
 *  2. company_group_members — the membership pivot. UNIQUE(company_id): a company
 *     belongs to at most ONE group at a time. Deleting a group cascades the
 *     membership rows and touches nothing else.
 *
 *  3. ledgers.linked_company_id — marks a PARTY ledger in company A as "this
 *     party IS company B". Only meaningful on party-tracking groups (Sundry
 *     Debtors/Creditors, Loans & Advances (Asset), Loans (Liability) + children —
 *     enforced in the Ledger master, not by the schema). The link alone is inert;
 *     tagging fires only when both companies are in the SAME group at post time.
 *     nullOnDelete: removing the linked company reverts the ledger to an ordinary
 *     party, never deletes it.
 *
 *  4. voucher_intercompany_tags — the mandatory tag, one per voucher (UNIQUE).
 *     company_id is the POSTING side (the 12A every-operational-table discipline —
 *     12C scans "my company's inter-company sales" through the scope);
 *     counterparty_company_id is the other side; counterparty_ledger_id is the
 *     mirror ledger in the counterparty company when one is unambiguously
 *     reciprocally linked (lets 12C match eliminations precisely, not by amount).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- 1. The group master (tenant-level) -------------------------------
        Schema::create('company_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('slug', 60)->unique();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // ---- 2. Membership — at most one group per company --------------------
        Schema::create('company_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_group_id')->constrained('company_groups')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->timestamps();

            $table->unique('company_id', 'company_group_members_company_id_unique');
            $table->index('company_group_id');
        });

        // ---- 3. Party ledger → linked company ---------------------------------
        Schema::table('ledgers', function (Blueprint $table) {
            $table->foreignId('linked_company_id')->nullable()->after('currency_id')
                ->constrained('companies')->nullOnDelete();
        });

        // ---- 4. The mandatory inter-company tag -------------------------------
        Schema::create('voucher_intercompany_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id'); // the POSTING company (12A discipline)
            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('counterparty_company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('counterparty_ledger_id')->nullable()->constrained('ledgers')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique('voucher_id', 'voucher_intercompany_tags_voucher_id_unique');
            // 12C's elimination scan: "this company's tags against that counterparty".
            $table->index(['company_id', 'counterparty_company_id'], 'voucher_intercompany_tags_company_scan_index');
            $table->foreign('company_id', 'voucher_intercompany_tags_company_id_foreign')
                ->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_intercompany_tags');

        Schema::table('ledgers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('linked_company_id');
        });

        Schema::dropIfExists('company_group_members');
        Schema::dropIfExists('company_groups');
    }
};
