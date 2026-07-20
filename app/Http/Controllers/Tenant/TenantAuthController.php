<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7B — login for a tenant's accounting app (served on the tenant subdomain).
 *
 * Authenticates against the CENTRAL `tenant_users` table, SCOPED to the current
 * subdomain's tenant — so the same email registered under two tenants only logs into
 * the one whose subdomain the request came in on.
 */
class TenantAuthController extends Controller
{
    public function show()
    {
        if (Auth::guard('tenant')->check()) {
            return redirect()->route('gateway');
        }

        return view('tenant.login', [
            'tenantName' => tenant()?->name ?? 'ZeroBook',
        ]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Phase 14C — a closed (archived / purged) account cannot be signed into.
        $tenant = tenant() ? \App\Models\Tenant::find(tenant('id')) : null;
        if ($tenant && $tenant->isClosed()) {
            throw ValidationException::withMessages(['email' => $tenant->writeBlockMessage()]);
        }

        // Phase 14A — a self-signup admin cannot sign in until they confirm their email.
        // Only surfaced when the password is otherwise correct, so it is not an account
        // enumeration oracle beyond what a successful login already reveals.
        $candidate = TenantUser::where('tenant_id', tenant('id'))->where('email', $data['email'])->first();
        if ($candidate && ! $candidate->hasVerifiedEmail() && Hash::check($data['password'], $candidate->password)) {
            throw ValidationException::withMessages([
                'email' => 'Please confirm your email before signing in — check your inbox for the verification link.',
            ]);
        }

        // Scope the credential lookup to THIS tenant — the tenant_id constraint is
        // applied by the Eloquent provider alongside the email.
        $ok = Auth::guard('tenant')->attempt([
            'email' => $data['email'],
            'password' => $data['password'],
            'tenant_id' => tenant('id'),
        ], $request->boolean('remember'));

        if (! $ok) {
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match any user for this company.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('gateway'));
    }

    public function logout(Request $request)
    {
        Auth::guard('tenant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('tenant.login');
    }
}
