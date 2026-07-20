<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Owns the root path `/` so it never collides between the three surfaces that share the
 * central domain space (Phase 7B, extended in 14A):
 *
 *   • TENANT subdomain (acme.zerobook.in)   → redirect to the tenant gateway (a tenant
 *     route behind the subdomain middleware, so it runs against the tenant's database).
 *   • ADMIN subdomain (admin.zerobook.in)   → the platform-admin console (or its login).
 *   • PUBLIC central domain (zerobook.in / www.) → the marketing + signup landing.
 *
 * This is a plain central route (no tenancy middleware), so it never touches a tenant
 * DB itself — it only decides where `/` should go. Deciding by host at REQUEST time keeps
 * a single `/` route, which stays correct under route caching.
 */
class RootController extends Controller
{
    public function index(Request $request)
    {
        $host = $request->getHost();
        $central = (array) config('tenancy.central_domains');

        // Admin subdomain → the platform console (login handles the guest case).
        if (str_starts_with($host, 'admin.')) {
            return Auth::guard('platform')->check()
                ? redirect()->route('platform.dashboard')
                : redirect()->route('platform.login');
        }

        // Any other central host → the public marketing + signup landing.
        if (in_array($host, $central, true)) {
            return view('central.landing', [
                'plans' => Plan::whereNotIn('tier', ['trial'])->orderBy('price_inr')->get(),
                'trialDays' => (int) config('zerobook.trial_days', 30),
            ]);
        }

        // A tenant subdomain — hand off to the gateway on the same host.
        return redirect()->route('gateway');
    }
}
