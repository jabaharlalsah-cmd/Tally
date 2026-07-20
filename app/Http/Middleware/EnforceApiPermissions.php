<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Support\ApiError;
use App\Support\ApiScopes;
use Closure;
use Illuminate\Http\Request;

/**
 * Phase 16A — server-side scope enforcement.
 *
 * Each route declares the scope it needs as a ROUTE DEFAULT, and this middleware is attached to
 * the GROUP rather than to individual routes:
 *
 *     Route::post('/v1/vouchers', …)->defaults('api_scope', 'voucher:create');
 *     Route::get('/v1/ping', …)    ->defaults('api_scope', 'none');
 *
 * FAIL-CLOSED BY OMISSION — and why the group placement is the whole mechanism.
 *
 * A route that declares no scope is REFUSED, not allowed. The alternative — "no declaration means
 * no requirement" — means a 16B endpoint whose author forgets silently becomes readable by every
 * key ever issued, with nothing in any test to say so. Inverted, the mistake is loud on the first
 * call. Ping therefore states its openness explicitly with 'none' rather than inheriting it.
 *
 * That guarantee only holds if this middleware ALWAYS RUNS. An earlier revision attached it
 * per-route (`->middleware(EnforceApiPermissions::class.':voucher:create')`), which reads more
 * idiomatically and is quietly broken: forgetting the whole `->middleware(...)` call — the most
 * likely mistake, and strictly easier to make than forgetting its argument — left the route with
 * NO scope check whatsoever. The "fail-closed" claim then only covered the one mistake nobody
 * makes. Attaching to the group and carrying the scope in a route default closes that: the check
 * cannot be omitted, only the declaration can, and omitting the declaration denies.
 *
 * (Route defaults are also merged into $route->parameters(), so 'api_scope' appears there. It is
 * inert — nothing binds or injects it, and URL generation ignores it — which is the price of a
 * carrier that a route cannot silently skip.)
 *
 * A misspelled scope is likewise unsatisfiable (ApiScopes::satisfies rejects anything outside the
 * catalog), so a typo closes a route rather than opening one.
 *
 * This is enforcement, not a UI hint: the key's scopes come from the tenant-DB row, never from
 * anything the caller sent. No header, body field, or query parameter can widen them — the only
 * inputs are the route's own declaration and the stored row.
 */
class EnforceApiPermissions
{
    /** The route-default key each API route uses to declare its required scope. */
    public const SCOPE_DEFAULT = 'api_scope';

    public function handle(Request $request, Closure $next)
    {
        $key = $request->attributes->get(ApiError::API_KEY_ATTR);

        // Unreachable in the configured chain; fail closed rather than trust the ordering.
        if (! $key instanceof ApiKey) {
            return ApiError::invalidKey($request);
        }

        $required = $request->route()?->defaults[self::SCOPE_DEFAULT] ?? null;

        // No declaration → refuse. See the fail-closed note above.
        if (! is_string($required) || $required === '') {
            return ApiError::insufficientScope('undeclared', $request);
        }

        if (! ApiScopes::satisfies($key->scopes(), $required)) {
            return ApiError::insufficientScope($required, $request);
        }

        return $next($request);
    }
}
