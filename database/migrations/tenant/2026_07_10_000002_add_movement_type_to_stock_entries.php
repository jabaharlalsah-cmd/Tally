<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6C fix — tag each stock movement with its kind so the item-level valuation
 * fold can treat an inter-godown TRANSFER as value-neutral.
 *
 * A transfer posts an OUT (source) + an IN (dest) at one locked rate. The IN's
 * stored value is frozen at post time; but the item-level fold re-derives an OUT at
 * the *live* average, so if the average later shifts (an altered or back-dated
 * purchase) the two legs no longer cancel and the item's total value/average drift.
 * Marking transfer rows lets fold() SKIP them entirely (they never change the item
 * total — only the godown location), which is correct by construction and immune to
 * any later rate change. godownQuantity still counts them (per-godown movement).
 *
 * Nullable: pre-existing rows and 6B sale/purchase rows keep null; only the fold's
 * skip-list ('transfer') is load-bearing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_entries', function (Blueprint $table) {
            // sale | purchase | transfer | consumption | physical | null
            $table->string('movement_type', 20)->nullable()->after('direction');
        });
    }

    public function down(): void
    {
        Schema::table('stock_entries', function (Blueprint $table) {
            $table->dropColumn('movement_type');
        });
    }
};
