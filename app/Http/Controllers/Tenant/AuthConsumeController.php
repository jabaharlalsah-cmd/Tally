<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 14A — the tenant-subdomain end of the email-verification hand-off.
 *
 * After the CENTRAL verify route confirms the email and activates the tenant, it
 * redirects here with a short-lived signed URL. This logs the (now verified) user into
 * their tenant subdomain and drops them at the gateway — completing "confirm email →
 * signed in" in one hop, across the domain boundary that separate sessions otherwise
 * impose.
 */
class AuthConsumeController extends Controller
{
    /** GET /_auth/consume — behind signed:relative. */
    public function consume(Request $request)
    {
        $userId = (int) $request->query('user');

        $user = TenantUser::where('tenant_id', tenant('id'))->whereKey($userId)->first();

        if (! $user) {
            abort(403, 'This sign-in link is invalid.');
        }

        if (! $user->hasVerifiedEmail()) {
            // Shouldn't happen (verify marks it first) — fail safe rather than log in.
            return redirect()->route('tenant.login')
                ->withErrors(['email' => 'Please confirm your email before signing in.']);
        }

        Auth::guard('tenant')->login($user);
        $request->session()->regenerate();

        return redirect()->route('gateway');
    }
}
