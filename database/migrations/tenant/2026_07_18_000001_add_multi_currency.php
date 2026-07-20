<?php

use Database\Seeders\CurrencySeeder;
use Database\Seeders\ForexLedgerSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 — the multi-currency engine (per-tenant).
 *
 * A forex LAYER on top of the base-currency books. The Trial Balance, Balance Sheet and
 * P&L stay in base currency (INR for Indian tenants, NPR for Nepali) — that never changes.
 * Every voucher entry line gains an OPTIONAL foreign amount + rate + currency; the existing
 * base-currency `amount` is the reporting-currency equivalent and remains the source of
 * truth for the Dr/Cr balance gate. All existing rows keep NULL forex fields (backward
 * compatible), so with multi-currency off nothing behaves differently.
 *
 *  1. currencies — the currency master. Exactly one is `is_base` (the company's own
 *     reporting currency). Seeded with INR as base; a Nepali tenant marks NPR as base from
 *     the currency master (which enforces exactly-one-base).
 *
 *  2. exchange_rates — the rate history: rate = base-currency value of one foreign unit
 *     (1 USD = ₹83.50 → rate 83.5000). `rateOn()` returns the most recent rate on or
 *     before a date. Manual entry; automatic feeds are a later phase.
 *
 *  3. ledgers.currency_id — a party ledger tagged with a non-base currency is a
 *     foreign-currency ledger; every voucher line on it must supply a foreign amount + rate.
 *
 *  4. voucher_entries.{currency_id, foreign_amount, exchange_rate} — the dual-currency
 *     shape. All three NULL on a base line; on a foreign line the invariant
 *     `amount = round(foreign_amount × exchange_rate × 100)` holds (verified server-side).
 *
 *  5. Two reserved ledgers — Foreign Exchange Gain (Indirect Incomes) and Foreign Exchange
 *     Loss (Indirect Expenses) — absorb the realised timing difference on settlement.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- 1. Currency master ---------------------------------------------
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();          // 3-letter ISO: USD, EUR, INR, NPR
            $table->string('symbol', 8)->nullable();       // $, €, ₹, Rs.
            $table->string('name', 60);
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_base')->default(false);    // exactly one true — the reporting currency
            $table->timestamps();
        });

        // ---- 2. Exchange-rate history ---------------------------------------
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('currency_id')->constrained('currencies')->cascadeOnDelete();
            $table->date('date');
            // Base-currency value of ONE unit of the foreign currency, 6dp for precision.
            $table->decimal('rate', 16, 6);
            $table->timestamps();

            $table->index(['currency_id', 'date']);
            $table->unique(['currency_id', 'date']); // one rate per currency per day
        });

        // ---- 3. Foreign-currency ledger tagging -----------------------------
        Schema::table('ledgers', function (Blueprint $table) {
            // NULL = base currency (the default for every existing ledger).
            $table->foreignId('currency_id')->nullable()->after('gstin')
                ->constrained('currencies')->nullOnDelete();
        });

        // ---- 4. Dual-currency voucher lines ---------------------------------
        Schema::table('voucher_entries', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('amount')
                ->constrained('currencies')->nullOnDelete();
            $table->decimal('foreign_amount', 18, 4)->nullable()->after('currency_id');
            $table->decimal('exchange_rate', 16, 6)->nullable()->after('foreign_amount');
        });

        // ---- 5. Seed --------------------------------------------------------
        (new CurrencySeeder())->run();

        // Reserved forex Gain/Loss ledgers need the Indirect Income/Expense groups. On an
        // EXISTING tenant the chart is already seeded, so create them now; a freshly
        // provisioned tenant runs this migration BEFORE its seeders, where the
        // DatabaseSeeder's ForexLedgerSeeder creates them right after the groups.
        if (Schema::hasTable('account_groups') && DB::table('account_groups')->where('name', 'Indirect Incomes')->exists()) {
            (new ForexLedgerSeeder())->run();
        }
    }

    public function down(): void
    {
        DB::table('ledgers')->where('name', 'Foreign Exchange Gain')->orWhere('name', 'Foreign Exchange Loss')->delete();

        Schema::table('voucher_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('currency_id');
            $table->dropColumn(['foreign_amount', 'exchange_rate']);
        });
        Schema::table('ledgers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('currency_id');
        });
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};
