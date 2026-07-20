<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account Groups (Tally "Groups" master).
 *
 * Self-referencing hierarchy (parent_id). The 15 primary + 13 sub predefined
 * groups are seeded as reserved (non-deletable). Advanced behavioural options
 * are scaffolded here but hidden in the UI until Phase 4 (F11/F12-gated).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('alias')->nullable();

            // "Under" — null = primary group. restrictOnDelete so a parent with
            // children can never be deleted out from under them (no orphaning).
            $table->foreignId('parent_id')->nullable()
                ->constrained('account_groups')->restrictOnDelete();

            // Reporting nature. Primary groups set it explicitly; sub-groups
            // inherit their root primary's nature (denormalised for reporting).
            $table->enum('nature', ['Assets', 'Liabilities', 'Income', 'Expenses']);

            $table->boolean('is_primary')->default(false); // parent_id === null
            $table->boolean('is_reserved')->default(false); // predefined, non-deletable
            $table->unsignedInteger('sort_order')->default(0);

            // --- Scaffolded, F11/F12-gated behavioural options (hidden this phase) ---
            $table->boolean('is_sub_ledger')->default(false);        // "behaves like sub-ledger"
            $table->boolean('nett_balance')->default(false);         // nett Dr/Cr for reporting
            $table->boolean('used_for_calculation')->default(false); // used in calculation

            $table->timestamps();

            $table->unique('name');
            $table->index('parent_id');
            $table->index('nature');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_groups');
    }
};
