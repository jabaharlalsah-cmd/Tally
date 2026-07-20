<?php

namespace App\Http\Middleware;

use App\Support\TenantGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * Phase 14A — mark a platform admin's presence on every tenant screen they impersonate.
 *
 * When the current tenant session is an impersonation session (started from the admin
 * surface, TenantGate session keys present), this:
 *
 *   • shares the impersonation context to ALL views as `$zbImpersonation`, so the
 *     persistent "PLATFORM ADMIN IMPERSONATING …" banner + Exit button render on every
 *     tenant page without touching a single controller; and
 *   • stamps a request attribute so downstream code knows this request is impersonated.
 *
 * The impersonation START (with the admin's identity + reason), the write-toggle
 * changes, and the EXIT are each recorded as rows in platform_admin_actions by the
 * impersonation controllers — this middleware carries the live banner, not per-request
 * log spam.
 */
class LogImpersonation
{
    public function handle(Request $request, Closure $next)
    {
        $context = TenantGate::impersonationContext();

        if ($context !== null) {
            $request->attributes->set('impersonating', true);
            View::share('zbImpersonation', $context);
        }

        return $next($request);
    }
}
