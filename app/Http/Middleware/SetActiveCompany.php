<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\ActiveCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12A — resolve the session's active company for every tenant request.
 *
 * Runs after tenancy initialization (the connection already points at the tenant
 * DB) and after the session starts. Resolution order:
 *
 *   1. session('active_company_id') if it names an existing, ACTIVE company;
 *   2. otherwise the tenant's default company (lowest-id active) — first login,
 *      a deactivated company, or a stale id all land here;
 *   3. no company at all → 503 (an unseeded tenant is not servable).
 *
 * The resolved id is written back to the session AND into the ActiveCompany
 * holder the BelongsToCompany scope reads. Fail-closed by construction: past this
 * middleware a request always has exactly one active company — the sync API's
 * snapshot/pull, every Livewire action, and every report inherit it.
 *
 * A tenant whose 12A migration has not run yet (no companies table) passes
 * through inert so `tenants:migrate` itself stays reachable.
 */
class SetActiveCompany
{
    public function handle(Request $request, Closure $next)
    {
        // Checked per request (not memoised): one process can serve several tenant
        // DBs, and during a deploy some may not have the 12A migration yet.
        if (! Schema::hasTable('companies')) {
            return $next($request);
        }

        $sessionId = (int) $request->session()->get('active_company_id', 0);
        $company = $sessionId > 0 ? Company::find($sessionId) : null;

        if (! $company || ! $company->is_active) {
            $company = Company::defaultCompany();

            if (! $company) {
                abort(503, 'No company is set up for this tenant yet.');
            }

            $request->session()->put('active_company_id', $company->id);

            // The session did NOT name this company — it was resolved by fallback
            // (first login, a deactivated company, a stale id). Interactive pages
            // simply land on the default company's Gateway; the SYNC API instead
            // refuses (SyncController) — silently serving a different company's
            // books to a desktop mid-sync would merge two mirrors.
            $request->attributes->set('active_company_was_fallback', true);
        }

        ActiveCompany::set($company->id);

        return $next($request);
    }
}
