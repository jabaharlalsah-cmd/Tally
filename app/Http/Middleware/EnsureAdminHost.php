<?php

namespace App\Http\Middleware;

use App\Support\TenantUrl;
use Closure;
use Illuminate\Http\Request;

/**
 * Phase 14A — keep the platform-admin console on the reserved `admin.` subdomain.
 *
 * The admin routes live at /admin/* in the central route file (route-cache-safe — no
 * domain constraint), and this middleware pins them to admin.<base-domain>: a request
 * for an /admin/* path on any other central host is redirected to the same path on the
 * admin subdomain, so the platform session is scoped to admin.zerobook.local alone.
 */
class EnsureAdminHost
{
    public function handle(Request $request, Closure $next)
    {
        if (! str_starts_with($request->getHost(), 'admin.')) {
            return redirect()->away(TenantUrl::admin($request->getRequestUri()));
        }

        return $next($request);
    }
}
