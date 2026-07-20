<?php

namespace App\Services\Api;

use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 16B — the result of running a write through IdempotencyService.
 *
 * One of four shapes:
 *   - fresh    : the operation ran now; return its response.
 *   - replay   : a prior identical request already succeeded; return the STORED response,
 *                byte-for-byte, plus the X-Idempotency-Replay: true header. A client cannot tell
 *                a replay from the original except by that header — which is the point.
 *   - reused   : the key was seen with a DIFFERENT body → 409 idempotency_key_reused.
 *   - inflight : a concurrent identical request holds the row and has not finished → 409
 *                idempotency_key_in_flight + Retry-After.
 */
class IdempotencyOutcome
{
    private function __construct(
        public readonly string $kind,      // 'fresh' | 'replay' | 'reused' | 'inflight'
        public readonly int $status,
        public readonly array $body,
    ) {}

    public static function fresh(int $status, array $body): self
    {
        return new self('fresh', $status, $body);
    }

    public static function replay(int $status, array $body): self
    {
        return new self('replay', $status, $body);
    }

    public static function reused(): self
    {
        return new self('reused', 409, []);
    }

    public static function inflight(): self
    {
        return new self('inflight', 409, []);
    }

    public function toResponse(Request $request): JsonResponse
    {
        if ($this->kind === 'reused') {
            return ApiError::response(
                'idempotency_key_reused',
                409,
                'This Idempotency-Key was already used with a different request body.',
                request: $request,
            );
        }

        if ($this->kind === 'inflight') {
            $response = ApiError::response(
                'idempotency_key_in_flight',
                409,
                'A request with this Idempotency-Key is still being processed. Retry shortly.',
                request: $request,
            );
            $response->headers->set('Retry-After', '2');

            return $response;
        }

        $response = new JsonResponse($this->body, $this->status);

        // Echo the trace id (the renderer's success path does not, so set it here).
        $requestId = $request->attributes->get(ApiError::REQUEST_ID_ATTR);
        if (is_string($requestId) && $requestId !== '') {
            $response->headers->set('X-Request-Id', $requestId);
        }

        if ($this->kind === 'replay') {
            $response->headers->set('X-Idempotency-Replay', 'true');
        }

        return $response;
    }
}
