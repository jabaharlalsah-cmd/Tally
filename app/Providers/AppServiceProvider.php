<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Phase 14A — register the global write-block hook HERE, not in boot(): Livewire's
        // ComponentHookRegistry::boot() (in LivewireServiceProvider::boot()) snapshots the
        // registered hooks to wire their mount/hydrate listeners, and package boot() runs
        // before this app's boot(). Registering in register() (which completes for every
        // provider before any boot() runs) guarantees the hook is present in time. The
        // static call needs no bound Livewire service.
        \Livewire\ComponentHookRegistry::register(\App\Livewire\Support\TenantWriteGuard::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->tenantAwareLivewireUpdateRoute();
        $this->signupRateLimiters();
        $this->apiKeyScopedRouteBindings();
        // The global write-block hook (TenantWriteGuard) is registered in register() — see
        // there for why it can't be registered in boot().
    }

    /**
     * Phase 16C — resolve {webhook} scoped to the calling API key's companies.
     *
     * THIS LIVES HERE, NOT IN routes/api.php, AND THAT IS THE WHOLE POINT. `php artisan
     * route:cache` (deploy.sh runs it on every production deploy) loads the cached route table and
     * NEVER EVALUATES THE ROUTE FILES — so a Route::bind declared there is silently dropped in
     * production only. Implicit binding would take over, resolving {webhook} off an unscoped model,
     * and every guard below would pass locally while being wide open on zerobook.in. Verified, not
     * assumed: with routes cached, the binding in routes/api.php stopped applying and a
     * company-restricted key got 200 on another company's subscription — its delivery log, its
     * secret rotation, its URL. A provider's boot() runs either way.
     *
     * WHY SCOPE AT THE BINDING AT ALL: webhook_subscriptions has no company_id (it holds the SET of
     * companies each subscription may hear about), and unlike Voucher the model carries no company
     * global scope — so nothing else stops a key restricted to company 3 from resolving, rotating,
     * repointing, or reading the delivery log of company 7's subscription. Binding once means the
     * next {webhook} route added is scoped by construction rather than by remembering.
     *
     * 404, not 403: a key restricted to company 3 has no business learning that company 7's
     * subscription #12 exists.
     */
    private function apiKeyScopedRouteBindings(): void
    {
        Route::bind('webhook', function (string $id) {
            $key = request()->attributes->get(\App\Support\ApiError::API_KEY_ATTR);

            // FAIL CLOSED. IdentifyTenantByApiKey always sets this before SubstituteBindings runs
            // (see the priority list in bootstrap/app.php), so a null key means the chain was
            // reordered — resolve nothing rather than fall back to unscoped.
            abort_unless($key instanceof \App\Models\ApiKey, 404);

            return \App\Models\WebhookSubscription::query()
                ->visibleToApiKey($key)
                ->whereKey($id)
                ->firstOrFail();
        });
    }

    /**
     * Phase 14A — rate limits for the public signup surface. Real signups are rare and
     * spam is common, so the write endpoint is tight (5 attempts per IP per hour) and the
     * live subdomain-availability probe is looser (60 per IP per minute).
     */
    private function signupRateLimiters(): void
    {
        RateLimiter::for('zerobook-signup', fn (Request $request) => Limit::perHour(5)->by($request->ip()));
        RateLimiter::for('zerobook-subdomain', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }

    /**
     * Put Livewire's `update` endpoint inside the tenant middleware stack.
     *
     * Found while browser-verifying Phase 10A; the defect dates from Phase 7B.
     *
     * routes/tenant.php wraps every screen in [web, InitializeTenancyBySubdomain,
     * PreventAccessFromCentralDomains], which switches the DEFAULT database connection to
     * the tenant's database before anything runs. But Livewire registers its own POST
     * endpoint — the one that receives EVERY component action — from its service provider,
     * outside that group. So the initial GET of a screen ran against the tenant database
     * while every subsequent commit (post a voucher, create a ledger, save F11) ran against
     * the CENTRAL database, where the accounting tables do not exist. The result was a 500
     * on the first Ctrl+A, on every tenant subdomain.
     *
     * It went unnoticed because the prove-* battery drives VoucherScreen::post() in-process
     * (inside `tenant()->run()`), which is correctly scoped, and the HTTP click-through was
     * never exercised end-to-end.
     *
     * Re-registering the endpoint here gives it the identical middleware stack as the rest
     * of the tenant routes. Livewire's RouteCollection entry is keyed by method + URI, so
     * this replaces the default route it registered during its own boot(); setUpdateRoute()
     * then appends `web` and its header guard, so nothing is lost. Every Livewire component
     * in ZeroBook is tenant-side (the central landing page and platform admin are plain
     * controllers), so scoping the endpoint to a tenant subdomain costs nothing — and
     * PreventAccessFromCentralDomains now stops a crafted Livewire call from reaching a
     * tenant component through the central domain.
     *
     * Phase 14A — TrackTenantActivity is included so interactions that only touch Livewire
     * (not a full page load) still keep last_active_at current. The write-block for
     * suspended/expired tenants (and view-only impersonation) is NOT a blanket method block
     * here (the endpoint carries reads too); it is enforced per-action by the
     * TenantWriteGuard component hook (see boot()), which refuses only the enumerated write
     * actions while leaving reads untouched.
     */
    private function tenantAwareLivewireUpdateRoute(): void
    {
        Livewire::setUpdateRoute(fn ($handle, $path) => Route::post($path, $handle)
            ->middleware([
                'web',
                InitializeTenancyBySubdomain::class,
                PreventAccessFromCentralDomains::class,
                \App\Http\Middleware\SetActiveCompany::class, // Phase 12A — every Livewire
                // action resolves the active company exactly like a page load does.
                \App\Http\Middleware\TrackTenantActivity::class, // Phase 14A
            ])
            ->name('tenant.livewire.update'));
    }
}
