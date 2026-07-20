<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledgers (Tally "Ledgers" master).
 *
 * Each ledger sits under an account group (except the special Profit & Loss A/c,
 * which — as in Tally — sits under "Primary", so group_id is nullable). Opening
 * balance is stored as a magnitude plus a Dr/Cr side. F11-gated feature columns
 * are scaffolded but their sub-screens are not built this phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledgers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('alias')->nullable();

            // "Under" group. Nullable only for the special P&L A/c (under Primary).
            $table->foreignId('group_id')->nullable()
                ->constrained('account_groups')->restrictOnDelete();

            // Opening balance: magnitude + side.
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->enum('opening_balance_type', ['Dr', 'Cr'])->nullable();

            // Always-visible mailing / registration block (all optional).
            $table->string('mailing_name')->nullable();
            $table->text('address')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->default('India');
            $table->string('pincode')->nullable();
            $table->string('pan')->nullable();
            $table->string('gstin')->nullable();

            $table->boolean('is_reserved')->default(false);   // Cash, P&L — non-deletable
            $table->boolean('is_pl_account')->default(false); // the special Profit & Loss A/c

            // --- Scaffolded, F11-gated columns (sub-screens not built this phase) ---
            $table->boolean('maintain_bill_by_bill')->default(false);
            $table->boolean('cost_centres_applicable')->default(false);
            $table->string('bank_account_no')->nullable();
            $table->string('bank_ifsc')->nullable();
            $table->string('bank_name')->nullable();

            $table->timestamps();

            $table->unique('name');
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledgers');
    }
};
