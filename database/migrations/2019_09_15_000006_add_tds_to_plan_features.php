<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10A — CENTRAL database.
 *
 * Add the `tds` key to every plan's feature matrix.
 *
 * This migration is not cosmetic. `Plan::allows()` returns FALSE for a feature key that
 * is absent from the JSON, and `PlanGate::violation()` is the server-side security
 * boundary the F11 save path calls. So the moment 'tds' joins PlanGate::GATED, every
 * tenant provisioned before this migration would find TDS permanently locked — the
 * toggle disabled, the save rejected — because their plan row simply never mentioned it.
 *
 * TDS is unlocked on every tier, exactly as GST and VAT are: it is statutory compliance,
 * not a premium add-on. A tier that should NOT include it only needs its JSON flipped —
 * the matrix stays the single source of truth, editable without a code change.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setTdsFlag(true);
    }

    public function down(): void
    {
        // Drop the key entirely rather than setting it false — an absent key is what the
        // pre-10A rows looked like, so `down()` genuinely restores the prior state.
        foreach (DB::table('plans')->get() as $plan) {
            $features = json_decode($plan->features, true) ?: [];
            unset($features['tds']);
            DB::table('plans')->where('id', $plan->id)->update([
                'features' => json_encode($features),
                'updated_at' => now(),
            ]);
        }
    }

    private function setTdsFlag(bool $value): void
    {
        foreach (DB::table('plans')->get() as $plan) {
            $features = json_decode($plan->features, true) ?: [];
            $features['tds'] = $value;
            DB::table('plans')->where('id', $plan->id)->update([
                'features' => json_encode($features),
                'updated_at' => now(),
            ]);
        }
    }
};
