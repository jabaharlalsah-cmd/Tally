<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7B — CENTRAL database.
 *
 * Subscription plans. `features` is the plan-tier → F11-feature matrix: which
 * company feature families a tenant on this plan is ALLOWED to switch on. The F11
 * screen reads it to gate the toggles; the gate is enforced server-side too, so
 * `features` is the single source of truth for what a tier unlocks — reconfigurable
 * without a code change.
 *
 * Seeded with three tiers so provisioning always has a plan to attach to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tier')->unique();       // starter | professional | enterprise
            $table->json('features');                // { gst:true, cost_centres:false, ... }
            $table->unsignedInteger('price_inr')->nullable(); // monthly; billing provider is a later phase
            $table->timestamps();
        });

        // The F11 feature keys mirror CompanyFeature's flags exactly. `tds` is statutory
        // compliance, not a premium add-on, so — like gst and vat — every tier unlocks it.
        $tiers = [
            ['starter', 'Starter', 499, [
                'gst' => true, 'vat' => true, 'tds' => true, 'bill_by_bill' => true,
                'cost_centres' => false, 'multi_currency' => false,
            ]],
            ['professional', 'Professional', 1499, [
                'gst' => true, 'vat' => true, 'tds' => true, 'bill_by_bill' => true,
                'cost_centres' => true, 'multi_currency' => false,
            ]],
            ['enterprise', 'Enterprise', 3999, [
                'gst' => true, 'vat' => true, 'tds' => true, 'bill_by_bill' => true,
                'cost_centres' => true, 'multi_currency' => true,
            ]],
        ];
        foreach ($tiers as [$tier, $name, $price, $features]) {
            DB::table('plans')->insert([
                'name' => $name,
                'tier' => $tier,
                'features' => json_encode($features),
                'price_inr' => $price,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
