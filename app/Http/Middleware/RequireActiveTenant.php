<?php

namespace App\Http\Middleware;

use App\Support\TenantGate;
use Closure;
use Illuminate\Http\Request;

/**
 * Phase 14A — enforce the subscription state on plain HTTP tenant writes.
 *
 * Runs AFTER tenancy identification (so tenant() and the tenant DB connection are
 * available). GET/HEAD/OPTIONS always pass — a suspended or expired-trial tenant keeps
 * full read access to its books. Non-safe methods (POST/PUT/PATCH/DELETE) are blocked
 * when TenantGate says the request may not write, EXCEPT the auth/navigation routes a
 * blocked user still needs (log in, log out, switch company, consume an
 * impersonation/verification hand-off).
 *
 * This guards the plain-HTTP write surface (chiefly the desktop sync push). The
 * interactive Livewire write path is gated at its own chokepoint — VoucherScreen::post()
 * calls TenantGate::assertWritable() — because the Livewire update endpoint carries BOTH
 * reads and writes over POST, so a blanket method block there would also break reading.
 */
class RequireActiveTenant
{
    /** Non-GET routes always allowed regardless of subscription state. */
    private const ALWAYS_ALLOWED = [
        'tenant.login', 'tenant.login.attempt', 'tenant.logout',
        // Phase 16 — a locked-out (expired) user must still be able to reset/change their password.
        'tenant.password.email', 'tenant.password.update', 'tenant.password.change.update',
        'company.switch',
        'impersonate.consume', 'impersonate.write', 'impersonate.exit',
        'auth.consume',
        // Phase 14B — an expired tenant must still be able to submit a renewal payment.
        'subscription.claim',
        // Phase 14C — an expired/suspended tenant can always export their data or close the account.
        'account.data.export', 'account.close',
    ];

    /** Routes a CLOSED (archived/purged) tenant may still reach — only to be told it's closed. */
    private const CLOSED_ALLOWED = [
        'tenant.login', 'tenant.login.attempt', 'tenant.logout',
        // Phase 14C — an archived customer can still download their data export (signed link).
        'export.download',
    ];

    public function handle(Request $request, Closure $next)
    {
        // Central domain / tenancy not initialized — not this middleware's concern.
        if (! function_exists('tenant') || ! tenant()) {
            return $next($request);
        }

        // Phase 16A — the API surface answers to a STRICTER rule than the web, and to a
        // different vocabulary, so it branches out before any of the interactive logic below.
        if ($request->attributes->has(\App\Support\ApiError::API_KEY_ATTR)) {
            return $this->handleApi($request, $next);
        }

        // Phase 14C — a closed (archived / purge_scheduled / purged) account has NO access:
        // reads are blocked too, not just writes. Log the user out and send them to login.
        $tenant = \App\Support\TenantGate::tenant();
        if ($tenant && $tenant->isClosed()) {
            if (in_array($request->route()?->getName(), self::CLOSED_ALLOWED, true)) {
                return $next($request);
            }
            \Illuminate\Support\Facades\Auth::guard('tenant')->logout();

            return redirect()->route('tenant.login')->withErrors(['email' => $tenant->writeBlockMessage()]);
        }

        if ($request->isMethodSafe()) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALWAYS_ALLOWED, true)) {
            return $next($request);
        }

        if (! TenantGate::writable()) {
            $message = TenantGate::blockMessage();

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['tenant' => $message]);
        }

        return $next($request);
    }

    /**
     * Phase 16A — the lifecycle gate for API-key-authenticated requests.
     *
     * Reached only when IdentifyTenantByApiKey has stamped a verified key on the request. The
     * check is the attribute, NOT the path: `api/*` already belongs to /api/sync/push,
     * /api/sync/pull (session-authenticated desktop sync) and /api/subdomain-available (a public
     * signup probe), and path-matching would sweep all three into this rule and break them.
     *
     * THREE DELIBERATE DIFFERENCES FROM THE INTERACTIVE RULE ABOVE:
     *
     *  1. It blocks READS too. The web keeps a suspended tenant's books readable — a human
     *     should still see what they are paying to restore. A machine integration is the
     *     opposite case: an HMS that keeps reading a suspended account has not noticed anything
     *     is wrong. So the API goes dark and says why, while the customer's staff can still log
     *     in and read. This is the one place the API is intentionally stricter than the web.
     *
     *  2. It tests POSITIVELY — isWritable(), i.e. status === 'active'. A blocklist of
     *     "suspended, expired_trial, archived, purge_scheduled, purged" (the set the brief
     *     names) silently grants full API access to 'expired_subscription' — a real status for a
     *     paying customer who lapsed past grace — and to 'provisioning', 'pending_verification',
     *     'cancelled' and 'restored'. Only 'active' is active.
     *
     *  3. It never redirects. The interactive path returns redirect()->route('tenant.login') for
     *     a closed account, which is meaningless to a machine client.
     *
     * TenantGate is not consulted: statusWritable() FAILS OPEN outside a tenant context
     * (`return $tenant ? $tenant->isWritable() : true`), and its impersonation branch is session
     * state an API request cannot have. The tenant row is read directly and fails closed.
     */
    private function handleApi(Request $request, Closure $next)
    {
        $tenant = \App\Models\Tenant::find(tenant('id'));

        if (! $tenant || ! $tenant->isWritable()) {
            return \App\Support\ApiError::response(
                'tenant_not_active',
                403,
                $tenant?->writeBlockMessage(),
                request: $request,
            );
        }

        return $next($request);
    }
}
