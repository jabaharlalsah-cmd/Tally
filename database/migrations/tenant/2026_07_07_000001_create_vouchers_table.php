<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting vouchers (Contra / Payment / Receipt / Journal) — the header row.
 * Balanced double-entry lines live in voucher_entries. Numbering is sequential
 * per (type, financial year); fy_start is the starting calendar year (Apr–Mar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['contra', 'payment', 'receipt', 'journal']);
            $table->unsignedBigInteger('number');
            $table->unsignedSmallInteger('fy_start'); // e.g. 2026 => FY 2026-27
            $table->date('date');
            $table->text('narration')->nullable();
            $table->timestamps();

            $table->unique(['type', 'fy_start', 'number']);
            $table->index(['date']);
            $table->index(['type', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
