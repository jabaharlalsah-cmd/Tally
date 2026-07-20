<?php

namespace App\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;

/**
 * Phase 16B — require an Idempotency-Key on every write.
 *
 * Applied per-write-route (not to the whole API group — reads must NOT require it). A missing or
 * empty header is a 422 before any work happens; a present one is validated for length and
 * stamped on the request for the controller + IdempotencyService to read.
 *
 * This only guarantees the header EXISTS and is well-formed. The single-flight semantics
 * (replay / conflict / in-flight) live in IdempotencyService, which the controller invokes — this
 * middleware is the cheap front gate so no write endpoint can forget the requirement.
 */
class RequiresIdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    public const ATTR = 'zb_api_idempotency_key';

    private const MAX_LENGTH = 255;

    public function handle(Request $request, Closure $next)
    {
        $key = trim((string) $request->header(self::HEADER, ''));

        if ($key === '') {
            return ApiError::response('idempotency_key_required', 422, request: $request);
        }

        if (mb_strlen($key) > self::MAX_LENGTH) {
            return ApiError::response(
                'idempotency_key_required',
                422,
                'The Idempotency-Key must be at most '.self::MAX_LENGTH.' characters.',
                request: $request,
            );
        }

        $request->attributes->set(self::ATTR, $key);

        return $next($request);
    }
}
