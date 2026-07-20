<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6B — the selling side of a stock movement.
 *
 * Two numbers must never be confused on an OUT (sale) row:
 *   • rate / value   — the COST (weighted-average, system-computed at post time).
 *   • sale_rate / sale_value — the SELLING price the user entered (revenue).
 *
 * These columns are nullable: they carry the selling figures on OUT rows only.
 * On IN (purchase) rows the entered rate IS the cost, so rate/value hold it and
 * sale_rate/sale_value stay null. Nothing pre-6B wrote to stock_entries, so no
 * backfill is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_entries', function (Blueprint $table) {
            $table->decimal('sale_rate', 15, 4)->nullable()->after('value');
            $table->decimal('sale_value', 15, 2)->nullable()->after('sale_rate');
        });
    }

    public function down(): void
    {
        Schema::table('stock_entries', function (Blueprint $table) {
            $table->dropColumn(['sale_rate', 'sale_value']);
        });
    }
};
