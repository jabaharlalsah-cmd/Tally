<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

/**
 * Phase 11 — the minimal currency seed.
 *
 * INR is seeded as the base (reporting) currency — the primary case for an Indian tenant.
 * A Nepali (VAT-regime) tenant adds NPR from the currency master and marks it base; the
 * master enforces that exactly one currency is base, so marking NPR base un-marks INR (and
 * INR then becomes a foreign currency for that tenant, which is correct).
 *
 * Users add every other currency (USD, EUR, GBP, JPY, AED, SGD, …) themselves.
 */
class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        // firstOrCreate, NOT updateOrCreate: is_base is decided once, at creation —
        // INR becomes base only if the company has no base currency yet. A re-run
        // (repair seeding, per-company backfill) must never re-mark INR base on a
        // company whose user switched base to NPR.
        Currency::firstOrCreate(
            ['code' => 'INR'],
            [
                'symbol' => '₹', 'name' => 'Indian Rupee', 'decimal_places' => 2,
                'is_base' => ! Currency::where('is_base', true)->exists(),
            ],
        );
    }
}
