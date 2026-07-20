<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12C-1 — FIFO lot tracking for INTER-COMPANY stock movements (per-tenant).
 *
 * The 12C-2 consolidation needs to know, at period end, how many units of each
 * item on company B's books came from group-internal purchases (and at what cost
 * to the group) versus outside purchases. Weighted-average pooling erases that
 * provenance — this table preserves it, for inter-company receipts ONLY.
 *
 * THE VALUATION CONTRACT IS UNTOUCHED: weighted-average remains authoritative for
 * every cost and every report (6B/6D). A lot row participates in PROVENANCE math,
 * never valuation math — nothing reads it except the Lot Provenance report and
 * 12C-2's elimination. Ungrouped tenants write no rows and pay no cost.
 *
 * One row per inter-company IN stock_entries row (Purchase / Receipt Note /
 * Credit Note / Rejections In from a groupmate party), plus CHILD rows created
 * when a godown transfer splits a lot:
 *
 *   • company_id           — the RECEIVING company (BelongsToCompany, 12A);
 *   • voucher_id/stock_entry_id — the receiving voucher + its IN row. The
 *     stock_entry FK CASCADES: altering a voucher deletes its stock rows, which
 *     auto-cleans the stale lots before the rewrite + refold;
 *   • parent_lot_id        — godown-transfer genealogy: a transfer moves FIFO
 *     remaining qty into a child lot at the destination godown, INHERITING the
 *     parent's received_date so FIFO order stays the original receipt order;
 *   • source_company_id    — the groupmate that sold us this inventory;
 *   • source_voucher_id / source_cost_paise — best-effort identification of the
 *     counterparty's matching OUT voucher (via 12B's reciprocal-ledger trace);
 *     null when zero/many candidates match — 12C-2 surfaces those as UNMATCHED
 *     rather than silently misvaluing;
 *   • original_qty / remaining_qty — the FIFO state. remaining_qty is a PURE
 *     FUNCTION of (root lots + chronological OUT/transfer events), so alter and
 *     cancel repair it by replay (InterCompanyLotService::refoldItem);
 *   • received_rate_paise  — the transfer price we paid per unit;
 *   • received_date        — the FIFO ordering key (voucher date of the receipt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inter_company_stock_lots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id'); // the RECEIVING company (12A discipline)
            $table->foreignId('stock_item_id')->constrained('stock_items')->cascadeOnDelete();
            $table->foreignId('godown_id')->nullable()->constrained('godowns')->nullOnDelete();
            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('stock_entry_id')->constrained('stock_entries')->cascadeOnDelete();
            $table->foreignId('parent_lot_id')->nullable()->constrained('inter_company_stock_lots')->cascadeOnDelete();
            $table->foreignId('source_company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('source_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->decimal('original_qty', 15, 4);
            $table->decimal('remaining_qty', 15, 4);
            $table->unsignedBigInteger('source_cost_paise')->nullable(); // groupmate's per-unit cost ×100; null = unmatched
            $table->unsignedBigInteger('received_rate_paise');           // what we paid per unit ×100 (the transfer price)
            $table->date('received_date');                               // FIFO key (children inherit the parent's)
            $table->timestamps();

            $table->index(['stock_item_id', 'remaining_qty'], 'ic_lots_item_remaining_index');
            $table->index(['company_id', 'stock_item_id', 'received_date'], 'ic_lots_fifo_scan_index');
            $table->foreign('company_id', 'inter_company_stock_lots_company_id_foreign')
                ->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inter_company_stock_lots');
    }
};
