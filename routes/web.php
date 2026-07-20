<?php

use App\Http\Controllers\Central\PlatformAuthController;
use App\Http\Controllers\Central\PlatformPasswordController;
use App\Http\Controllers\Central\PlatformController;
use App\Http\Controllers\Central\SignupController;
use App\Http\Controllers\RootController;
use App\Http\Middleware\EnsureAdminHost;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ZeroBook — CENTRAL routes (Phase 7B, extended in 14A)
|--------------------------------------------------------------------------
| Served on the central domain(s) only. These pages NEVER touch a tenant's accounting
| database — that lives behind the subdomain middleware in routes/tenant.php. Three
| surfaces share this space, split by host (see RootController for `/`):
|
|   • PUBLIC  (zerobook.in / www.)     — marketing landing + self-signup.
|   • ADMIN   (admin.zerobook.in)      — the platform-admin console (/admin/*, pinned
|                                        to the admin subdomain by EnsureAdminHost).
|   • TENANT  (<slug>.zerobook.in)     — `/` hands off to the gateway (tenant.php).
|
| The only shared path is `/`, owned here by RootController; the tenant gateway lives at
| /app in tenant.php, so there is no collision.
*/

// `/` — marketing landing (public) · admin console (admin host) · gateway (tenant host).
Route::get('/', [RootController::class, 'index'])->name('landing');

// ── Public self-signup (Phase 14A) ──────────────────────────────────────────────
Route::get('/signup', [SignupController::class, 'create'])->name('signup');
Route::post('/signup', [SignupController::class, 'store'])
    ->middleware('throttle:zerobook-signup')            // 5 / IP / hour
    ->name('signup.store');
Route::get('/signup/check-email', [SignupController::class, 'checkEmail'])->name('signup.check-email');

// Live subdomain availability probe (rate-limited: 60 / IP / minute).
Route::get('/api/subdomain-available', [SignupController::class, 'subdomainAvailable'])
    ->middleware('throttle:zerobook-subdomain')
    ->name('signup.subdomain-available');

// Phase 16A — public API documentation stub.
//
// The brief names docs.zerobook.local; a dedicated docs host is a DNS + vhost change, not an
// application one, and the brief allows "the equivalent central-app URL". 'docs' is a reserved
// subdomain (config/zerobook.php), so that hostname stays free to point here later with no code
// change. Deliberately public and unauthenticated — it is the page an integrator reads BEFORE
// they have an account. The generated OpenAPI reference lands in 16E.
Route::view('/docs/api', 'docs.api')->name('docs.api');

// Email verification (temporary signed URL, host-independent → signed:relative).
Route::get('/verify/{id}/{hash}', [SignupController::class, 'verify'])
    ->middleware('signed:relative')
    ->name('signup.verify');

// ── Platform-admin console (Phase 7B/14A) — admin.<central-domain> only ───────────
Route::middleware(EnsureAdminHost::class)->prefix('admin')->group(function () {
    Route::get('/login', [PlatformAuthController::class, 'show'])->name('platform.login');
    Route::post('/login', [PlatformAuthController::class, 'login'])->name('platform.login.attempt');
    Route::post('/logout', [PlatformAuthController::class, 'logout'])->name('platform.logout');

    // Phase 16 — password reset (guest): forgot → email link → set new password.
    Route::get('/forgot-password', [PlatformPasswordController::class, 'requestForm'])->name('platform.password.request');
    Route::post('/forgot-password', [PlatformPasswordController::class, 'sendLink'])
        ->middleware('throttle:zerobook-signup')->name('platform.password.email');
    Route::get('/reset-password/{token}', [PlatformPasswordController::class, 'resetForm'])->name('platform.password.reset');
    Route::post('/reset-password', [PlatformPasswordController::class, 'reset'])->name('platform.password.update');

    Route::middleware('auth:platform')->group(function () {
        Route::get('/', [PlatformController::class, 'dashboard'])->name('platform.dashboard');

        // Phase 16 — change own password (authenticated).
        Route::get('/password', [PlatformPasswordController::class, 'changeForm'])->name('platform.password.change');
        Route::post('/password', [PlatformPasswordController::class, 'change'])->name('platform.password.change.update');
        Route::post('/tenants', [PlatformController::class, 'provision'])->name('platform.provision');
        Route::get('/tenants/{tenant}', [PlatformController::class, 'show'])->name('platform.tenant');
        // Phase 16 — link a manually pre-created database (shared-hosting provisioning).
        Route::post('/tenants/{tenant}/link-database', [PlatformController::class, 'linkDatabase'])->name('platform.link-database');
        // Phase 16 — auto-provision infra (subdomain + database) via the Hostinger API.
        Route::post('/tenants/{tenant}/auto-provision', [PlatformController::class, 'autoProvision'])->name('platform.auto-provision');
        // Phase 16 — change the tenant DB password on Hostinger + sync the stored copy.
        Route::post('/tenants/{tenant}/db-password', [PlatformController::class, 'changeDatabasePassword'])->name('platform.db-password');
        // Phase 16 — tenant logins: create an owner, reset a user's password.
        Route::post('/tenants/{tenant}/users', [PlatformController::class, 'createTenantUser'])->name('platform.tenant-user.create');
        Route::post('/tenants/{tenant}/users/{user}/password', [PlatformController::class, 'resetTenantUserPassword'])->name('platform.tenant-user.password');
        // Phase 16 — manual status override.
        Route::post('/tenants/{tenant}/status', [PlatformController::class, 'setStatus'])->name('platform.set-status');
        // Phase 16 — edit company + owner details.
        Route::post('/tenants/{tenant}/details', [PlatformController::class, 'updateDetails'])->name('platform.update-details');
        Route::post('/tenants/{tenant}/suspend', [PlatformController::class, 'suspend'])->name('platform.suspend');
        Route::post('/tenants/{tenant}/reactivate', [PlatformController::class, 'reactivate'])->name('platform.reactivate');
        Route::post('/tenants/{tenant}/extend-trial', [PlatformController::class, 'extendTrial'])->name('platform.extend-trial');
        Route::post('/tenants/{tenant}/plan', [PlatformController::class, 'changePlan'])->name('platform.plan');
        Route::post('/tenants/{tenant}/impersonate', [PlatformController::class, 'impersonate'])->name('platform.impersonate');

        // Phase 14B — manual payments + plan management.
        Route::get('/payments', [\App\Http\Controllers\Central\PaymentsController::class, 'pending'])->name('platform.payments');
        Route::get('/payments/record', [\App\Http\Controllers\Central\PaymentsController::class, 'recordForm'])->name('platform.payments.record');
        Route::post('/payments/record', [\App\Http\Controllers\Central\PaymentsController::class, 'recordStore'])->name('platform.payments.record.store');
        Route::post('/payments/{payment}/confirm', [\App\Http\Controllers\Central\PaymentsController::class, 'confirm'])->name('platform.payments.confirm');
        Route::post('/payments/{payment}/reject', [\App\Http\Controllers\Central\PaymentsController::class, 'reject'])->name('platform.payments.reject');
        Route::post('/payments/{payment}/reverse', [\App\Http\Controllers\Central\PaymentsController::class, 'reverse'])->name('platform.payments.reverse');
        Route::get('/payments/{payment}/proof', [\App\Http\Controllers\Central\PaymentsController::class, 'proof'])->name('platform.payments.proof');
        Route::get('/payments/{payment}/invoice', [\App\Http\Controllers\Central\PaymentsController::class, 'invoice'])->name('platform.payments.invoice');

        Route::get('/plans', [\App\Http\Controllers\Central\PlansController::class, 'index'])->name('platform.plans');
        Route::post('/plans/{plan}', [\App\Http\Controllers\Central\PlansController::class, 'update'])->name('platform.plans.update');

        // Phase 14C — backups, restore, offboarding lifecycle.
        Route::get('/lifecycle', [PlatformController::class, 'lifecycle'])->name('platform.lifecycle');
        Route::post('/tenants/{tenant}/backup', [\App\Http\Controllers\Central\BackupsController::class, 'takeNow'])->name('platform.backup');
        Route::post('/tenants/{tenant}/backups/{backup}/restore', [\App\Http\Controllers\Central\BackupsController::class, 'restore'])->name('platform.restore');
        Route::post('/tenants/{tenant}/offboarding/initiate', [\App\Http\Controllers\Central\OffboardingController::class, 'initiate'])->name('platform.offboarding.initiate');
        Route::post('/tenants/{tenant}/offboarding/reactivate', [\App\Http\Controllers\Central\OffboardingController::class, 'reactivate'])->name('platform.offboarding.reactivate');
        Route::post('/tenants/{tenant}/offboarding/purge', [\App\Http\Controllers\Central\OffboardingController::class, 'purge'])->name('platform.offboarding.purge');
    });
});
