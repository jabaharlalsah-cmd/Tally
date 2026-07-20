<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 16A — per-key rate limiting. Default 60 requests/minute, overridable per key.
 *
 * THE KEY IS NOT `api:{api_key_id}` — that would be a cross-tenant leak.
 * The brief specifies `api:{api_key_id}`, but api_keys lives in the PER-TENANT database, so
 * every tenant has a key with id 1. The cache, meanwhile, is deliberately NOT tenant-scoped:
 * config/tenancy.php enables only DatabaseTenancyBootstrapper (the cache bootstrapper needs a
 * taggable store, which the file driver is not). So the cache is one global keyspace, and
 * `api:1` would be a single bucket shared by every tenant's first key — tenant A's traffic
 * would consume tenant B's 60/min budget, and A could deny service to B by spending it. The
 * tenant id is therefore part of the key. The raw secret never is: it would be written to the
 * cache — and to disk, under the file store — in plaintext.
 *
 * Runs AFTER identification because the limit is per KEY, which must be resolved first. That
 * leaves the bcrypt verify itself reachable by unauthenticated callers; an IP-level pre-limit
 * to cover it is noted as a gap in PHASE16A_README rather than silently assumed.
 *
 * The 60/min ceiling is best-effort, not exact: FileStore::increment is a read-modify-write with
 * no lock, so genuinely concurrent requests can undercount. It is a business throttle, not a
 * security control — nothing downstream depends on its precision.
 */
class RateLimitByApiKey
{
    /** One minute, in seconds — RateLimiter::hit() takes SECONDS (ThrottleRequests takes minutes). */
    private const DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next)
    {
        $key = $request->attributes->get(ApiError::API_KEY_ATTR);

        // Unreachable in the configured chain (identification runs first and returns 401 without
        // a key). Fail closed anyway rather than silently serving an unlimited request if some
        // future route reorders the group.
        if (! $key instanceof ApiKey) {
            return ApiError::invalidKey($request);
        }

        $limiterKey = self::limiterKey($key);
        $maxAttempts = $key->effectiveRateLimit();

        if (RateLimiter::tooManyAttempts($limiterKey, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($limiterKey);

            return $this->withLimitHeaders(
                ApiError::rateLimited($retryAfter, $request),
                $maxAttempts,
                0,
                $retryAfter,
            );
        }

        RateLimiter::hit($limiterKey, self::DECAY_SECONDS);

        $response = $next($request);

        if ($response instanceof Response) {
            $this->withLimitHeaders(
                $response,
                $maxAttempts,
                RateLimiter::remaining($limiterKey, $maxAttempts),
            );
        }

        return $response;
    }

    /**
     * 'api:{tenant}:{key id}' — globally unique across a shared, non-tenant-scoped cache.
     *
     * tenant() is guaranteed initialized here: identification ran first and returned 401 if it
     * could not open a tenant. The null-coalesce is belt-and-braces so a misordered group can
     * never collapse two tenants onto one bucket.
     */
    private static function limiterKey(ApiKey $key): string
    {
        $tenantId = tenant('id') ?? 'unknown';

        return 'api:'.$tenantId.':'.$key->id;
    }

    /** The four headers API clients expect, matching ThrottleRequests' names. */
    private function withLimitHeaders(Response $response, int $limit, int $remaining, ?int $retryAfter = null): Response
    {
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $remaining));

        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $retryAfter);
            $response->headers->set('X-RateLimit-Reset', (string) (time() + $retryAfter));
        }

        return $response;
    }
}
