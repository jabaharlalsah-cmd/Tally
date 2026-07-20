<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Platform\PlatformActions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 14B — the platform-admin Plans screen: edit names, per-region prices, billing
 * period and public visibility. Editing a price is NEVER retroactive — each payment
 * already stores the amount received, so only payments recorded AFTER the change use the
 * new price.
 */
class PlansController extends Controller
{
    public function index()
    {
        return view('central.admin.plans', [
            'plans' => Plan::orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, Plan $plan, PlatformActions $actions)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'price_inr' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'price_npr' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'billing_period_months' => ['required', 'integer', 'min:1', 'max:120'],
        ]);

        $old = ['price_inr' => $plan->price_inr, 'price_npr' => $plan->price_npr];

        $plan->update([
            'name' => $data['name'],
            'price_inr' => $data['price_inr'] !== null && $data['price_inr'] !== '' ? $data['price_inr'] : null,
            'price_npr' => $data['price_npr'] !== null && $data['price_npr'] !== '' ? $data['price_npr'] : null,
            'billing_period_months' => $data['billing_period_months'],
            'is_public' => $request->boolean('is_public'),
        ]);

        // Plan edits are platform-wide (no single tenant), logged with tenant_id null.
        $actions->log(Auth::guard('platform')->user(), 'plan_edited', null, [
            'meta' => ['tier' => $plan->tier, 'old' => $old, 'new' => ['price_inr' => $plan->price_inr, 'price_npr' => $plan->price_npr]],
        ]);

        return back()->with('flash', "Plan “{$plan->tier}” updated. Existing payments are unchanged.");
    }
}
