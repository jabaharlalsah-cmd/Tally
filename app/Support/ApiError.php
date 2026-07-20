<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 16A — the one place an API error response is built.
 *
 * Envelope:  {"error": {"code": "<snake_case>", "message": "<human>", "details": {…}}}
 *
 * `code` is the machine contract — a customer's integration branches on it, so it must stay
 * stable across phases. `message` is safe to show an end user. `details` is optional.
 *
 * Two rules this class exists to enforce:
 *
 *  1. Nothing internal leaks. Stack traces, class names, file paths, SQL and DB names never
 *     reach a client. A server fault becomes a generic `internal_error` plus an `error_id`
 *     the customer can quote — the detail goes to the app log under that same id, so support
 *     can find it without the client ever seeing it.
 *
 *  2. Every response carries X-Request-Id, errors included. That is what makes "it failed at
 *     10:04, request 01J…" a traceable report rather than a guess.
 */
class ApiError
{
    /** Request attribute key holding the per-request ULID (stamped by LogApiRequest). */
    public const REQUEST_ID_ATTR = 'zb_api_request_id';

    /** Request attribute key holding the authenticated ApiKey (stamped by IdentifyTenantByApiKey). */
    public const API_KEY_ATTR = 'zb_api_key';

    /** The stable code → default message map. */
    public const MESSAGES = [
        'invalid_key' => 'The API key is missing, malformed, or not valid.',
        'key_revoked' => 'This API key has been revoked or has expired. Issue a new key.',
        'tenant_not_active' => 'This account is not active. API access is disabled until it is reactivated.',
        'insufficient_scope' => 'This API key does not have the permission required for this endpoint.',
        'company_not_authorized' => 'This API key is not authorized for the requested company.',
        'company_unavailable' => 'No active company is available for this API key.',
        'rate_limited' => 'Too many requests. Slow down and retry after the indicated delay.',
        'service_unavailable' => 'ZeroBook is briefly unavailable for maintenance. Retry after the indicated delay.',
        'not_found' => 'The requested resource does not exist.',
        'method_not_allowed' => 'That HTTP method is not supported on this endpoint.',
        'validation_failed' => 'The request payload failed validation.',
        'bad_request' => 'The request could not be understood.',
        'conflict' => 'The request conflicts with the current state of the resource.',
        // Phase 16B — idempotency contract.
        'idempotency_key_required' => 'This write requires an Idempotency-Key header.',
        'idempotency_key_reused' => 'This Idempotency-Key was already used with a different request body.',
        'idempotency_key_in_flight' => 'A request with this Idempotency-Key is still being processed. Retry shortly.',
        // Phase 16B — money / posting.
        'invalid_amount' => 'A money amount is malformed or out of range. Send amounts as decimal strings, e.g. "1500.00".',
        'unsupported_voucher_type' => 'That voucher type is not available through the API.',
        'total_mismatch' => 'The expected_total does not match the amount computed by the server.',
        'internal_error' => 'Something went wrong on our side. Quote the error id when contacting support.',
    ];

    /**
     * Build the envelope. Pass $request so the response can carry X-Request-Id even when the
     * failure happened before (or instead of) the handler running.
     */
    public static function response(
        string $code,
        int $status,
        ?string $message = null,
        ?array $details = null,
        ?Request $request = null,
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message ?? self::MESSAGES[$code] ?? 'Request failed.',
        ];

        if ($details !== null && $details !== []) {
            $error['details'] = $details;
        }

        $response = new JsonResponse(['error' => $error], $status);

        $requestId = self::requestId($request);

        if ($requestId !== null) {
            $response->headers->set('X-Request-Id', $requestId);
        }

        return $response;
    }

    /**
     * The request's trace id, minting one if nothing has stamped it yet.
     *
     * LogApiRequest normally stamps this as the outermost ROUTE middleware — but route middleware
     * only runs once a route has MATCHED. Errors raised before or instead of matching never reach
     * it: a 404 on an unknown /api/v1/* path, a 405 on the wrong method, a 503 from
     * PreventRequestsDuringMaintenance (global middleware, runs before routing). Those are exactly
     * the responses a customer opens a support ticket about, and they were shipping with no
     * X-Request-Id at all — quietly making "every response carries one" false in the cases that
     * matter most.
     *
     * So mint one here when it is absent, and stamp it back onto the request so repeated calls
     * within the same request agree. An id minted this way has no api_request_log row behind it
     * (there is no authenticated key, hence no tenant DB to write to), but it still correlates the
     * response with the app log for 500s, and it keeps the contract honest.
     */
    private static function requestId(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        $requestId = $request->attributes->get(self::REQUEST_ID_ATTR);

        if (is_string($requestId) && $requestId !== '') {
            return $requestId;
        }

        $minted = (string) \Illuminate\Support\Str::ulid();
        $request->attributes->set(self::REQUEST_ID_ATTR, $minted);

        return $minted;
    }

    /** 401 — deliberately identical for "no header", "garbage", and "prefix not found". */
    public static function invalidKey(?Request $request = null): JsonResponse
    {
        return self::response('invalid_key', 401, request: $request);
    }

    /** 403 — the key authenticated but its scopes do not cover this route. */
    public static function insufficientScope(string $required, ?Request $request = null): JsonResponse
    {
        return self::response(
            'insufficient_scope',
            403,
            details: ['required' => $required],
            request: $request,
        );
    }

    /** 429 — includes both the envelope field and the standard Retry-After header. */
    public static function rateLimited(int $retryAfterSeconds, ?Request $request = null): JsonResponse
    {
        $response = self::response(
            'rate_limited',
            429,
            details: ['retry_after_seconds' => $retryAfterSeconds],
            request: $request,
        );

        $response->headers->set('Retry-After', (string) $retryAfterSeconds);

        return $response;
    }

    /**
     * 500 — a generic body plus a correlation id. The real exception is written to the app log
     * against that id; the client is told nothing about our internals.
     */
    public static function internal(string $errorId, ?Request $request = null): JsonResponse
    {
        return self::response(
            'internal_error',
            500,
            details: ['error_id' => $errorId],
            request: $request,
        );
    }
}
