<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

/**
 * Phase 14A — record that a tenant is alive.
 *
 * Touches tenants.last_active_at on any tenant request so the platform-admin surface
 * can show which tenants are actually being used (and which trials have gone cold).
 *
 * Throttled to at most once per minute, in a SINGLE conditional UPDATE — no read, no
 * race, and no write on the vast majority of requests. Runs after the response so it
 * never delays the page. The write targets the CENTRAL tenants row (Tenant is pinned
 * to the central connection), independent of the active tenant DB.
 */
class TrackTenantActivity
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (function_exists('tenant') && tenant()) {
            Tenant::whereKey(tenant('id'))
                ->where(function ($q) {
                    $q->whereNull('last_active_at')
                        ->orWhere('last_active_at', '<', now()->subMinute());
                })
                ->update(['last_active_at' => now()]);
        }

        return $response;
    }
}
