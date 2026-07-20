<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 14A — CENTRAL database.
 *
 * Adds the two plans self-signup + manual conversion need, alongside 7B's
 * starter/professional/enterprise:
 *
 *   • trial        — what every public self-signup lands on. ALL features unlocked
 *                    (a trial is the full product for a fixed window); ₹0. The trial
 *                    window itself is tenants.trial_ends_at, not a plan attribute.
 *   • paid-monthly — the entry paid plan a platform admin converts a tenant to
 *                    ("Manual plan change") before automated billing exists (14B).
 *                    All features unlocked — 14B introduces real per-tier gating
 *                    and pricing; until then a paying pilot gets the whole product.
 *
 * Idempotent (insertOrIgnore on the unique `tier`) so re-running `migrate` on a live
 * central DB never duplicates a plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Same feature-key shape as CompanyFeature's flags and the 7B plans.
        $allOn = [
            'gst' => true, 'vat' => true, 'tds' => true, 'bill_by_bill' => true,
            'cost_centres' => true, 'multi_currency' => true,
        ];

        $rows = [
            ['tier' => 'trial', 'name' => 'Free Trial', 'price_inr' => 0, 'features' => $allOn],
            ['tier' => 'paid-monthly', 'name' => 'Paid — Monthly', 'price_inr' => 1499, 'features' => $allOn],
        ];

        foreach ($rows as $r) {
            DB::table('plans')->insertOrIgnore([
                'tier' => $r['tier'],
                'name' => $r['name'],
                'price_inr' => $r['price_inr'],
                'features' => json_encode($r['features']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('plans')->whereIn('tier', ['trial', 'paid-monthly'])->delete();
    }
};
