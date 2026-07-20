<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Company;
use App\Services\Api\ApiKeyService;
use App\Support\ActiveCompany;
use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;

/**
 * Phase 16A — authenticate a bearer API key, then open its tenant and pin its company.
 *
 * This is the security boundary of the whole API. Everything after it — 16B's vouchers, 16C's
 * webhooks, 16D's events — inherits whatever this class decides, so it is written to fail
 * closed at every step.
 *
 * WHY THIS IS A PARALLEL IDENTIFIER, NOT A REUSE OF THE 7B STACK
 * The web identifies a tenant by SUBDOMAIN (InitializeTenancyBySubdomain) and a company from
 * the SESSION (SetActiveCompany). An API request has neither: it carries one bearer token and
 * nothing else. So this middleware does the same two jobs from the key alone. Reusing the 7B
 * stack is not merely wrong here, it throws: SetActiveCompany calls $request->session(), and
 * the `api` group has no StartSession — and PreventAccessFromCentralDomains would reject the
 * call outright.
 *
 * ORDER IS A SECURITY PROPERTY — the two invariants that make this correct:
 *
 *  1. Tenancy is initialized BEFORE the company is pinned. ActiveCompany memoises the Company
 *     row against the current database name; pinning while still on the central connection
 *     would memoise the wrong database — and since EVERY tenant's default company is id 1,
 *     that resolves silently to another tenant's identity rather than erroring.
 *
 *  2. The company is pinned BEFORE any handler runs. BelongsToCompany's global scope reads
 *     ActiveCompany::id() and, when it is null, SKIPS the WHERE clause entirely — it does not
 *     throw. Reads FAIL OPEN. An API request that authenticated the tenant but forgot to pin a
 *     company would return every company's rows in that tenant with HTTP 200 and no error
 *     anywhere. That is the quietest way this phase could ship a data leak, so the pin is
 *     unconditional and this middleware refuses the request rather than continue without one.
 */
class IdentifyTenantByApiKey
{
    public function __construct(private ApiKeyService $keys) {}

    public function handle(Request $request, Closure $next)
    {
        // ActiveCompany is PROCESS state, not request state. Under a persistent worker (Octane,
        // or one artisan process serving several dispatches — including this phase's own proof)
        // a previous request's company would still be pinned here. Clearing first means a bug
        // below can only ever fail closed, never inherit someone else's company.
        ActiveCompany::set(null);

        $rawKey = ApiKeyService::bearerFrom($request->header('Authorization'));

        // No header, or a non-Bearer scheme (Basic, Digest, …). Same generic answer as every
        // other auth failure — telling a caller *why* is telling an attacker where to aim.
        if ($rawKey === null) {
            return ApiError::invalidKey($request);
        }

        // Verifies the key and, on success, opens that key's tenant connection. Returns null for
        // every failure mode — malformed, unknown prefix, wrong secret, missing/closed tenant —
        // each having paid a real bcrypt verify so the clock cannot tell them apart.
        $key = $this->keys->authenticate($rawKey);

        if (! $key) {
            return ApiError::invalidKey($request);
        }

        // Identity succeeded but the key is switched off. A DISTINCT code here is deliberate and
        // safe: only someone holding the genuine secret can reach this line, so it leaks nothing
        // — and "your key was revoked" is the one auth failure a customer can actually act on.
        if ($key->isInactive()) {
            $request->attributes->set(ApiError::API_KEY_ATTR, $key);   // real key → log the attempt

            return ApiError::response('key_revoked', 401, request: $request);
        }

        $company = $this->resolveCompany($request, $key);

        if ($company instanceof \Illuminate\Http\JsonResponse) {
            $request->attributes->set(ApiError::API_KEY_ATTR, $key);

            return $company;
        }

        // Invariant 2. Past this line the BelongsToCompany scope is armed for every read and
        // write in the request.
        ActiveCompany::set($company->id);

        // Published for the rest of the chain: RequireActiveTenant reads this to know the
        // request is API-key-authenticated (rather than sniffing the path, which would wrongly
        // capture /api/sync/* and /api/subdomain-available), RateLimitByApiKey keys on it,
        // EnforceApiPermissions reads its scopes, and LogApiRequest::terminate writes the row.
        $request->attributes->set(ApiError::API_KEY_ATTR, $key);
        $request->attributes->set('zb_api_company', $company);

        return $next($request);
    }

    /**
     * Decide which company this request acts on.
     *
     *   X-Company-Id present → must be authorized AND active, else 403. Never silently ignored.
     *   absent               → the lowest-id company the key is authorized for and that is active.
     *
     * Returns a Company, or a JsonResponse to return instead.
     *
     * The header is validated against the KEY's authorization list, never merely against the
     * tenant — that is the difference between company scoping and a suggestion. And there is no
     * fallback to "the tenant's default company" when the requested one is unavailable: the
     * codebase already learned that lesson on the sync API, where silently serving a different
     * company's books merged two desktop mirrors. Refusing is the only safe answer.
     */
    private function resolveCompany(Request $request, ApiKey $key): Company|\Illuminate\Http\JsonResponse
    {
        $header = $request->header('X-Company-Id');

        if ($header !== null && trim((string) $header) !== '') {
            $header = trim((string) $header);

            // Reject non-numeric outright — do not let '1abc' cast to 1.
            if (! ctype_digit($header)) {
                return ApiError::response('company_not_authorized', 403, request: $request);
            }

            $companyId = (int) $header;

            if (! $key->authorizesCompany($companyId)) {
                return ApiError::response('company_not_authorized', 403, request: $request);
            }

            $company = Company::find($companyId);

            // Company::find is unscoped (the registry model is deliberately not company-scoped),
            // so is_active must be checked explicitly — it is not implied by the lookup.
            if (! $company || ! $company->is_active) {
                return ApiError::response('company_not_authorized', 403, request: $request);
            }

            return $company;
        }

        // No header. Choose deterministically: lowest-id ACTIVE company the key may use.
        // Company::defaultCompany() is NOT used — it falls back to the lowest-id company of ANY
        // state, so it can hand back a deactivated company, and it ignores the key's list.
        $query = Company::query()->where('is_active', true)->orderBy('id');

        if (! $key->authorizesAllCompanies()) {
            $query->whereIn('id', $key->authorizedCompanyIds());
        }

        $company = $query->first();

        if (! $company) {
            // The key's companies were all deactivated or deleted, or the tenant has none.
            return ApiError::response('company_unavailable', 403, request: $request);
        }

        return $company;
    }
}
