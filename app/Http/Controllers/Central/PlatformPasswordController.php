<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Phase 16 — password self-service for ZeroBook PLATFORM admins (admin.<domain>).
 *
 *   • forgot  → email a reset link            (guest)
 *   • reset   → set a new password from link  (guest, token)
 *   • change  → change own password           (auth:platform)
 */
class PlatformPasswordController extends Controller
{
    /** The password broker configured in config/auth.php → passwords.platform_admins. */
    private const BROKER = 'platform_admins';

    // ── Forgot: request a reset link ─────────────────────────────────────────────
    public function requestForm()
    {
        return view('central.admin.passwords.forgot');
    }

    public function sendLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::broker(self::BROKER)->sendResetLink($request->only('email'));

        // Neutral message either way — never reveal whether an email is registered.
        return back()->with('flash', 'If that email belongs to an admin account, a reset link is on its way.');
    }

    // ── Reset: set a new password from the emailed link ──────────────────────────
    public function resetForm(Request $request, string $token)
    {
        return view('central.admin.passwords.reset', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
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
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                // The model's `hashed` cast hashes the plain value on save.
                $user->forceFill(['password' => $password])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect()->route('platform.login')->with('flash', 'Password updated — please sign in.');
    }

    // ── Change: authenticated admin changes their own password ───────────────────
    public function changeForm()
    {
        return view('central.admin.passwords.change');
    }

    public function change(Request $request)
    {
        // Current password is NOT required — an already-authenticated admin can set a
        // new password directly (only the new password + confirmation are validated).
        $request->validate([
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        Auth::guard('platform')->user()
            ->forceFill(['password' => $request->input('password')])
            ->save();

        return back()->with('flash', 'Your password has been changed.');
    }
}
