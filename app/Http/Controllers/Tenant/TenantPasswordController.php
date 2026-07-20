<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Phase 16 — password self-service for TENANT users (on the tenant subdomain).
 *
 * Every credential lookup is scoped by tenant_id (the current subdomain) so a reset is
 * bound to the tenant the request came in on — the same email registered under two
 * companies only ever resets the one whose subdomain issued the link.
 *
 *   • forgot  → email a reset link            (guest)
 *   • reset   → set a new password from link  (guest, token)
 *   • change  → change own password           (auth:tenant)
 */
class TenantPasswordController extends Controller
{
    /** The password broker configured in config/auth.php → passwords.tenant_users. */
    private const BROKER = 'tenant_users';

    // ── Forgot: request a reset link ─────────────────────────────────────────────
    public function requestForm()
    {
        return view('tenant.passwords.forgot', [
            'tenantName' => tenant()?->name ?? 'ZeroBook',
        ]);
    }

    public function sendLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::broker(self::BROKER)->sendResetLink([
            'email' => $request->input('email'),
            'tenant_id' => tenant('id'),
        ]);

        return back()->with('flash', 'If that email belongs to a user of this company, a reset link is on its way.');
    }

    // ── Reset: set a new password from the emailed link ──────────────────────────
    public function resetForm(Request $request, string $token)
    {
        return view('tenant.passwords.reset', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
            'tenantName' => tenant()?->name ?? 'ZeroBook',
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::broker(self::BROKER)->reset(
            [
                'email' => $request->input('email'),
                'password' => $request->input('password'),
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $request->input('token'),
                'tenant_id' => tenant('id'),
            ],
            function ($user, $password) {
                $user->forceFill(['password' => $password])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect()->route('tenant.login')->with('flash', 'Password updated — please sign in.');
    }

    // ── Change: authenticated tenant user changes their own password ─────────────
    public function changeForm()
    {
        return view('tenant.passwords.change', [
            'tenantName' => tenant()?->name ?? 'ZeroBook',
        ]);
    }

    public function change(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password:tenant'],
            'password' => ['required', 'confirmed', 'different:current_password', PasswordRule::min(8)],
        ]);

        Auth::guard('tenant')->user()
            ->forceFill(['password' => $request->input('password')])
            ->save();

        return back()->with('flash', 'Your password has been changed.');
    }
}
