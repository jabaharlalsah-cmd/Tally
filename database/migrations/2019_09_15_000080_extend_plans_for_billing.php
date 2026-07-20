<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14B (Manual) — CENTRAL database. Billing columns on `plans`.
 *
 *   • price_inr            — widened from unsignedInteger to nullable decimal (India price).
 *   • price_npr            — nullable decimal (Nepal price; a plan may price one region only).
 *   • billing_period_months— 1 = monthly, 12 = annual, etc. (drives the subscription period).
 *   • is_public            — whether the plan appears on the customer's pricing/renew dropdown.
 *
 * Seeds the concrete purchasable plans (monthly + annual for starter/professional) plus a
 * `custom` plan the platform admin can invoice at an arbitrary amount. PRICES ARE
 * PLACEHOLDERS — they are editable from the admin Plans screen, and editing a price never
 * changes past payments (each payment stores the amount actually received).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Widen the existing whole-rupee column to a nullable money column.
            $table->decimal('price_inr', 12, 2)->nullable()->change();
            $table->decimal('price_npr', 12, 2)->nullable()->after('price_inr');
            $table->unsignedSmallInteger('billing_period_months')->default(1)->after('price_npr');
            $table->boolean('is_public')->default(false)->after('billing_period_months');
        });

        // Feature matrices mirror the 7B tiers.
        $starter = ['gst' => true, 'vat' => true, 'tds' => true, 'bill_by_bill' => true, 'cost_centres' => false, 'multi_currency' => false];
        $professional = ['gst' => true, 'vat' => true, 'tds' => true, 'bill_by_bill' => true, 'cost_centres' => true, 'multi_currency' => false];
        $all = ['gst' => true, 'vat' => true, 'tds' => true, 'bill_by_bill' => true, 'cost_centres' => true, 'multi_currency' => true];

        // [tier, name, price_inr, price_npr, months, is_public, features]
        $rows = [
            ['starter-monthly', 'Starter — Monthly', 499, 799, 1, true, $starter],
            ['starter-annual', 'Starter — Annual', 4990, 7990, 12, true, $starter],
            ['professional-monthly', 'Professional — Monthly', 1499, 2399, 1, true, $professional],
            ['professional-annual', 'Professional — Annual', 14990, 23990, 12, true, $professional],
            // Admin-invoiced arbitrary plan (enterprise/annual/custom deals). Not public;
            // amount is entered per payment, so its list price is left null.
            ['custom', 'Custom', null, null, 1, false, $all],
        ];

        foreach ($rows as [$tier, $name, $inr, $npr, $months, $public, $features]) {
            DB::table('plans')->updateOrInsert(
                ['tier' => $tier],
                [
                    'name' => $name,
                    'price_inr' => $inr,
                    'price_npr' => $npr,
                    'billing_period_months' => $months,
                    'is_public' => $public,
                    'features' => json_encode($features),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('plans')->whereIn('tier', [
            'starter-monthly', 'starter-annual', 'professional-monthly', 'professional-annual', 'custom',
        ])->delete();

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['price_npr', 'billing_period_months', 'is_public']);
            $table->unsignedInteger('price_inr')->nullable()->change();
        });
    }
};
