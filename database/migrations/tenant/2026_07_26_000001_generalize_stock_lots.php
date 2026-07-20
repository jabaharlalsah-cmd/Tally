<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 — generalize the 12C-1 inter-company lot table to serve GENERAL FIFO/LIFO costing.
 *
 * The same table + fold that tracked inter-company inventory provenance now also cost regular
 * items' OUT movements from actual lot rates, for items flagged 'fifo' or 'lifo'. Weighted-average
 * items are untouched — they write no lots and take the unchanged running-average path.
 *
 *   1. RENAME inter_company_stock_lots → stock_lots (one table, two purposes).
 *   2. source_company_id becomes NULLABLE — a regular FIFO/LIFO lot has no groupmate source; the
 *      inter-company columns stay populated for inter-company receipts (12C-1 behaviour).
 *   3. costing_method — snapshotted from the item at lot-write time (default 'fifo', which is exactly
 *      what 12C-1's fold already implements, so every existing inter-company lot row is correct).
 *   4. index (stock_item_id, costing_method, remaining_qty) — the FIFO/LIFO depletion scan.
 *
 * The self-referencing parent_lot_id FK follows the table rename automatically (MySQL rewrites it),
 * so godown-transfer genealogy is preserved unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inter_company_stock_lots') && Schema::hasTable('stock_lots')) {
            return; // already generalized
        }

        Schema::rename('inter_company_stock_lots', 'stock_lots');

        // source_company_id: drop the FK so the column can be relaxed to nullable, then re-add it.
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->dropForeign('inter_company_stock_lots_source_company_id_foreign');
        });

        Schema::table('stock_lots', function (Blueprint $table) {
            $table->unsignedBigInteger('source_company_id')->nullable()->change();
            $table->foreign('source_company_id', 'stock_lots_source_company_id_foreign')
                ->references('id')->on('companies')->cascadeOnDelete();

            // Snapshotted costing method. Existing (inter-company) rows default to 'fifo' — the exact
            // depletion order 12C-1 already implements — so no historical row changes behaviour.
            $table->enum('costing_method', ['weighted_average', 'fifo', 'lifo'])
                ->default('fifo')->after('remaining_qty');

            $table->index(['stock_item_id', 'costing_method', 'remaining_qty'], 'stock_lots_item_method_remaining_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_lots')) {
            return;
        }

        // Regular FIFO/LIFO lots (no groupmate source) cannot exist once source_company_id is NOT
        // NULL again — remove them before re-tightening the column.
        DB::table('stock_lots')->whereNull('source_company_id')->delete();

        Schema::table('stock_lots', function (Blueprint $table) {
            $table->dropIndex('stock_lots_item_method_remaining_index');
            $table->dropColumn('costing_method');
            $table->dropForeign('stock_lots_source_company_id_foreign');
        });

        Schema::table('stock_lots', function (Blueprint $table) {
            $table->unsignedBigInteger('source_company_id')->nullable(false)->change();
            $table->foreign('source_company_id', 'inter_company_stock_lots_source_company_id_foreign')
                ->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::rename('stock_lots', 'inter_company_stock_lots');
    }
};
