<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Company;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 16A — GET /api/v1/ping, the only endpoint this phase ships.
 *
 * A customer wires this up first: it answers "is my key valid, and what does ZeroBook think it
 * is pointed at?" before any business call exists to get wrong. Every answer it gives is
 * derived from the middleware chain's own resolution — the tenant from the key, the company
 * from the key plus X-Company-Id — so a surprising ping is a real misconfiguration, not a
 * separate code path that might disagree with the one 16B will use.
 *
 * It requires no scope (declared explicitly as ':none' on the route) so that a key issued for
 * one narrow purpose can still be health-checked.
 *
 * The company block deliberately does NOT reuse Company::toCache(), which also carries gstin
 * and state — an authenticated in-app shape, not a public one. A diagnostic should not be the
 * endpoint that discloses tax identity; 16B can expose that under master:read.
 */
class PingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get(ApiError::API_KEY_ATTR);

        /** @var Company $company */
        $company = $request->attributes->get('zb_api_company');

        return response()->json([
            'tenant' => tenant('id'),
            'company' => $company->slug,
            'key_name' => $key->name,
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
