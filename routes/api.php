<?php

use App\Http\Controllers\Api\V1\LedgersController;
use App\Http\Controllers\Api\V1\PingController;
use App\Http\Controllers\Api\V1\ReportsController;
use App\Http\Controllers\Api\V1\StockItemsController;
use App\Http\Controllers\Api\V1\VouchersController;
use App\Http\Controllers\Api\V1\WebhooksController;
use App\Http\Middleware\EnforceApiPermissions;
use App\Http\Middleware\IdentifyTenantByApiKey;
use App\Http\Middleware\LogApiRequest;
use App\Http\Middleware\RateLimitByApiKey;
use App\Http\Middleware\RequireActiveTenant;
use App\Http\Middleware\RequiresIdempotencyKey;
use Illuminate\Support\Facades\Route;

/** Every write route requires an Idempotency-Key; reads never do. */
$scope = EnforceApiPermissions::SCOPE_DEFAULT;
$idem = RequiresIdempotencyKey::class;

/*
|--------------------------------------------------------------------------
| Phase 16A — the customer-integration REST API
|--------------------------------------------------------------------------
|
| Registered from bootstrap/app.php via withRouting(api: …), which applies Laravel's `api`
| middleware group AND an automatic `/api` URL prefix. Routes below therefore declare
| 'v1/…' and NOT 'api/v1/…' — the latter would serve /api/api/v1/….
|
| The inherited `api` group is [SubstituteBindings] only: no session, no CSRF, no throttle.
| That sessionless surface is exactly what an API-key-authenticated machine client needs, and
| it means the 60/min limit is entirely this group's own responsibility (Laravel's default
| `throttle:api` limiter is never registered in this app).
|
| These routes are NOT in routes/tenant.php and must never be moved there: that group's stack
| identifies tenants by SUBDOMAIN, rejects central-domain hosts, and reads the session for the
| active company — every one of which fights a bearer-token client.
|
| MIDDLEWARE ORDER IS LOAD-BEARING. Laravel middleware is an onion: the first entry wraps all
| the rest, and each may reject before the next runs.
|
|   LogApiRequest          outermost, so EVERY response — 401s and 429s included — gets an
|                          X-Request-Id, and so the row is written from terminate(), after the
|                          response is already on the wire.
|   IdentifyTenantByApiKey the security boundary: verifies the key, opens its tenant, pins its
|                          company. Everything after it depends on all three.
|   RequireActiveTenant    the lifecycle gate. After identification (it needs a tenant to judge)
|                          and before any work is done for a tenant that should not get any.
|   RateLimitByApiKey      per-key, so necessarily after the key is known.
|   EnforceApiPermissions  innermost: the last thing before the handler, and the cheapest check
|                          to run once identity is established.
|
| !! THE ORDER BELOW IS NOT WHAT RUNS. Laravel sorts route middleware through the global
| priority list before executing it, and the two entries above that appear in that list —
| LogApiRequest and IdentifyTenantByApiKey — are ordered by bootstrap/app.php, NOT by their
| position here. That is deliberate (IdentifyTenantByApiKey has to beat SubstituteBindings, which
| the `api` group injects ahead of everything declared here), but it means editing this array
| cannot be trusted to change execution order. Change bootstrap/app.php's priority calls, and
| confirm with:  php artisan route:list  or by re-running zerobook:prove-api-foundation, which
| asserts the sorted order explicitly.
*/

Route::middleware([
    LogApiRequest::class,
    IdentifyTenantByApiKey::class,
    RequireActiveTenant::class,
    RateLimitByApiKey::class,
    // Attached to the GROUP, never per-route — that placement IS the fail-closed guarantee.
    // Each route names its scope with ->defaults('api_scope', …); a route that declares nothing
    // is refused. Attaching this per-route instead would mean a 16B endpoint that forgot the
    // ->middleware(...) call entirely had no scope check at all, which is the easier mistake.
    EnforceApiPermissions::class,
])->group(function () use ($scope, $idem) {

    // The diagnostic. 'none' is an EXPLICIT declaration that this route demands no scope beyond
    // a valid key — it is not the default, and cannot be reached by omission.
    Route::get('/v1/ping', PingController::class)
        ->defaults(EnforceApiPermissions::SCOPE_DEFAULT, 'none')
        ->name('api.v1.ping');

    /*
    |----------------------------------------------------------------------------------------
    | Phase 16B — the business surface
    |----------------------------------------------------------------------------------------
    | Every route declares its scope via ->defaults($scope, …) — group-attached EnforceApi
    | Permissions refuses any route that declares nothing, so a forgotten scope fails closed.
    | Every WRITE additionally gets RequiresIdempotencyKey; reads never do.
    */

    // ── Vouchers ──
    Route::get('/v1/vouchers', [VouchersController::class, 'index'])->defaults($scope, 'voucher:read');
    Route::post('/v1/vouchers', [VouchersController::class, 'store'])->middleware($idem)->defaults($scope, 'voucher:create');
    Route::get('/v1/vouchers/{voucher}', [VouchersController::class, 'show'])->defaults($scope, 'voucher:read');
    Route::put('/v1/vouchers/{voucher}', [VouchersController::class, 'update'])->middleware($idem)->defaults($scope, 'voucher:alter');
    // cancel takes {voucher} as a plain id (see the controller) — it must NOT bind, so idempotency
    // can replay a same-key retry after the voucher is hard-deleted.
    Route::post('/v1/vouchers/{voucher}/cancel', [VouchersController::class, 'cancel'])->middleware($idem)->defaults($scope, 'voucher:cancel');

    // ── Ledgers ──
    Route::get('/v1/ledgers', [LedgersController::class, 'index'])->defaults($scope, 'master:read');
    Route::post('/v1/ledgers', [LedgersController::class, 'store'])->middleware($idem)->defaults($scope, 'master:write');
    Route::get('/v1/ledgers/{ledger}', [LedgersController::class, 'show'])->defaults($scope, 'master:read');
    Route::put('/v1/ledgers/{ledger}', [LedgersController::class, 'update'])->middleware($idem)->defaults($scope, 'master:write');

    // ── Stock items ──
    Route::get('/v1/stock-items', [StockItemsController::class, 'index'])->defaults($scope, 'master:read');
    Route::post('/v1/stock-items', [StockItemsController::class, 'store'])->middleware($idem)->defaults($scope, 'master:write');
    Route::get('/v1/stock-items/{stockItem}', [StockItemsController::class, 'show'])->defaults($scope, 'master:read');
    Route::put('/v1/stock-items/{stockItem}', [StockItemsController::class, 'update'])->middleware($idem)->defaults($scope, 'master:write');

    /*
    |----------------------------------------------------------------------------------------
    | Phase 16C — outbound webhooks (scope: webhook:manage, reserved in ApiScopes since 16A)
    |----------------------------------------------------------------------------------------
    | The secret is returned ONCE by store + rotate-secret. Every other response omits it
    | structurally (the resource never carries it; the model marks it $hidden).
    */

    /*
     * {webhook} does NOT use implicit binding: it resolves through a Route::bind that scopes it to
     * the calling API key's companies. That binding is declared in AppServiceProvider::boot(), NOT
     * here — `route:cache` would drop it from a route file and silently reopen the hole in
     * production only. See the docblock there; it is the reason this comment exists.
     */
    Route::get('/v1/webhooks', [WebhooksController::class, 'index'])->defaults($scope, 'webhook:manage');
    Route::post('/v1/webhooks', [WebhooksController::class, 'store'])->middleware($idem)->defaults($scope, 'webhook:manage');
    Route::get('/v1/webhooks/{webhook}', [WebhooksController::class, 'show'])->defaults($scope, 'webhook:manage');
    Route::put('/v1/webhooks/{webhook}', [WebhooksController::class, 'update'])->middleware($idem)->defaults($scope, 'webhook:manage');
    Route::delete('/v1/webhooks/{webhook}', [WebhooksController::class, 'destroy'])->defaults($scope, 'webhook:manage');
    Route::post('/v1/webhooks/{webhook}/rotate-secret', [WebhooksController::class, 'rotateSecret'])->middleware($idem)->defaults($scope, 'webhook:manage');
    Route::get('/v1/webhooks/{webhook}/deliveries', [WebhooksController::class, 'deliveries'])->defaults($scope, 'webhook:manage');
    Route::post('/v1/webhooks/{webhook}/test', [WebhooksController::class, 'test'])->middleware($idem)->defaults($scope, 'webhook:manage');

    // ── Reports (read-only, real-books-only) ──
    Route::get('/v1/reports/trial-balance', [ReportsController::class, 'trialBalance'])->defaults($scope, 'report:read');
    Route::get('/v1/reports/ledger-balance/{ledger}', [ReportsController::class, 'ledgerBalance'])->defaults($scope, 'report:read');
    Route::get('/v1/reports/party-outstanding/{ledger}', [ReportsController::class, 'partyOutstanding'])->defaults($scope, 'report:read');
    Route::get('/v1/reports/day-book', [ReportsController::class, 'dayBook'])->defaults($scope, 'report:read');
});
