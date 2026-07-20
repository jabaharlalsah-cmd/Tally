<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Services\Api\ApiKeyService;
use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Phase 16A — stamp every API request with a traceable id, then record it.
 *
 * ORDER: this middleware runs FIRST in the API group, not last as the brief's list suggests.
 * Laravel middleware is an onion — the first entry wraps the rest — so "last" would mean
 * "innermost", and an innermost logger never sees a request that IdentifyTenantByApiKey
 * rejected. The brief's own requirement forces the correction: "Every response includes an
 * X-Request-Id header." EVERY response includes the 401s and 429s, and only the outermost
 * layer observes those. Being outermost also means the id exists before anything can fail, so
 * an error envelope can quote it.
 *
 * The DB write happens in terminate(), which PHP-FPM runs after the response is already
 * flushed to the client. That is the closest thing to "async" available here: the queue is
 * QUEUE_CONNECTION=sync (dispatch runs inline, adding latency rather than removing it) and no
 * worker exists to drain a database queue anyway. terminate() genuinely moves the cost off the
 * request the client is waiting on, with no worker to operate.
 *
 * A request that never authenticated is NOT logged: api_request_log lives in the tenant
 * database, and with no valid key there is no tenant to write to. Failed-auth telemetry would
 * need a central log — deliberately out of 16A's scope (see PHASE16A_README).
 */
class LogApiRequest
{
    /** Request attribute holding the handler's start time, for the duration measurement. */
    private const STARTED_AT_ATTR = 'zb_api_started_at';

    public function handle(Request $request, Closure $next)
    {
        // Stamped before ANYTHING can reject the request, so every downstream error envelope
        // and every response header can carry it.
        $requestId = (string) Str::ulid();

        $request->attributes->set(ApiError::REQUEST_ID_ATTR, $requestId);
        $request->attributes->set(self::STARTED_AT_ATTR, microtime(true));

        $response = $next($request);

        if ($response instanceof Response) {
            $response->headers->set('X-Request-Id', $requestId);
        }

        return $response;
    }

    /**
     * Runs after the response has been sent. Writes the audit row and refreshes last_used_at.
     *
     * Wrapped in a catch-all: this is bookkeeping that happens after the customer already has
     * their response. A logging fault must never surface as an error the client cannot act on,
     * nor mask a response that already succeeded — so it is swallowed to the app log instead.
     */
    public function terminate(Request $request, Response $response): void
    {
        $key = $request->attributes->get(ApiError::API_KEY_ATTR);

        // No authenticated key → no tenant → nowhere to write. By design.
        if (! $key instanceof ApiKey) {
            return;
        }

        try {
            $startedAt = $request->attributes->get(self::STARTED_AT_ATTR);
            $durationMs = is_float($startedAt) ? (int) round((microtime(true) - $startedAt) * 1000) : 0;

            ApiRequestLog::create([
                'api_key_id' => $key->id,
                'request_id' => (string) $request->attributes->get(ApiError::REQUEST_ID_ATTR),
                'method' => $request->getMethod(),
                'path' => Str::limit($request->getPathInfo(), 250, ''),
                'query_string' => self::redactedQueryString($request),
                'request_body_hash' => self::bodyHash($request),
                'response_status' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'created_at' => now(),
            ]);

            app(ApiKeyService::class)->touchLastUsed($key, $request->ip());
        } catch (Throwable $e) {
            // Never echo the exception body — it could quote the request. Class + message only.
            Log::warning('api.request_log_failed', [
                'request_id' => $request->attributes->get(ApiError::REQUEST_ID_ATTR),
                'api_key_id' => $key->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The query string, with anything key-shaped redacted before it is persisted.
     *
     * The API only ever authenticates a bearer header — a key in the URL is never read, and the
     * docs tell integrators it is a server-side secret. But customers do put secrets in URLs, and
     * without this the FIRST one to try `?api_key=zb_live_…` would have their live key written to
     * api_request_log in plaintext and kept for as long as the log is. It would then also sit in
     * any DB backup and be visible to anyone who can read the Activity screen.
     *
     * The key never worked as a query parameter, so redacting it costs nothing and cannot break a
     * caller. It also makes the guarantee unconditional rather than true-only-if-customers-are-
     * careful: no zb_ secret reaches the log by any route.
     */
    private static function redactedQueryString(Request $request): ?string
    {
        $query = $request->getQueryString();

        if ($query === null || $query === '') {
            return null;
        }

        // Matches the key format in raw and percent-encoded form, whatever parameter carries it.
        $redacted = preg_replace('/zb_(live|test)_[A-Za-z0-9]{32}/', 'zb_$1_[REDACTED]', $query);

        return Str::limit((string) $redacted, 500, '');
    }

    /**
     * SHA-256 of the raw body, or null when there is no body.
     *
     * The digest — never the body — is what gets stored: bodies are PII and unbounded, while the
     * hash still answers the one question support asks ("was this the same payload?"). An empty
     * body hashes to null rather than to the constant digest of "", which would be noise.
     */
    private static function bodyHash(Request $request): ?string
    {
        $body = $request->getContent();

        return ($body === '' || $body === false) ? null : hash('sha256', $body);
    }
}
