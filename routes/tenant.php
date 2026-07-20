<?php

declare(strict_types=1);

use App\Http\Controllers\Dev\KeyboardHarnessController;
use App\Http\Controllers\FeaturesController;
use App\Http\Controllers\GatewayController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\MastersController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\Tenant\TenantAuthController;
use App\Http\Controllers\Tenant\TenantPasswordController;
use App\Http\Controllers\VouchersController;
use App\Models\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| ZeroBook — TENANT routes (Phase 7B)
|--------------------------------------------------------------------------
| The accounting application. Served ONLY on a tenant subdomain: the
| InitializeTenancyBySubdomain middleware identifies the tenant from the subdomain
| and switches the DEFAULT database connection to that tenant's database BEFORE any
| controller or Livewire component runs — so every screen and service (VoucherScreen,
| BalanceService, StockService, GST/VAT, the Tally importer …) operates on the
| tenant's data with no code change. PreventAccessFromCentralDomains blocks these
| routes on the central domain.
|
| None of the paths here (/app, /login, /masters, /vouchers, …) collide with the
| central routes (only `/` and /admin), so both route sets coexist cleanly.
*/

Route::middleware([
    'web',
    InitializeTenancyBySubdomain::class,
    PreventAccessFromCentralDomains::class,
    \App\Http\Middleware\SetActiveCompany::class,       // Phase 12A — pin the active company
    \App\Http\Middleware\TrackTenantActivity::class,    // Phase 14A — touch last_active_at
    \App\Http\Middleware\LogImpersonation::class,       // Phase 14A — impersonation banner
    \App\Http\Middleware\RequireActiveTenant::class,    // Phase 14A — block writes when not active
])->group(function () {

    // ── auth-free: login + a health probe ────────────────────────────────────
    Route::get('/login', [TenantAuthController::class, 'show'])->name('tenant.login');
    Route::post('/login', [TenantAuthController::class, 'login'])->name('tenant.login.attempt');
    Route::post('/logout', [TenantAuthController::class, 'logout'])->name('tenant.logout');

    // Phase 16 — password reset (guest): forgot → email link → set new password. The
    // POST routes are whitelisted in RequireActiveTenant so a locked-out (expired) user
    // can still recover their password.
    Route::get('/forgot-password', [TenantPasswordController::class, 'requestForm'])->name('tenant.password.request');
    Route::post('/forgot-password', [TenantPasswordController::class, 'sendLink'])->name('tenant.password.email');
    Route::get('/reset-password/{token}', [TenantPasswordController::class, 'resetForm'])->name('tenant.password.reset');
    Route::post('/reset-password', [TenantPasswordController::class, 'reset'])->name('tenant.password.update');

    // Phase 14A — email-verification auto-login hand-off (signed, host-independent).
    Route::get('/_auth/consume', [\App\Http\Controllers\Tenant\AuthConsumeController::class, 'consume'])
        ->middleware('signed:relative')->name('auth.consume');

    // Phase 14A — platform-admin impersonation hand-off (signed) + banner controls.
    Route::get('/_impersonate/consume', [\App\Http\Controllers\Tenant\ImpersonationController::class, 'consume'])
        ->middleware('signed:relative')->name('impersonate.consume');

    // Phase 14C — data-export download (signed link from email; works without login and
    // even for a closed/archived account so the customer can always retrieve their data).
    Route::get('/account/data/export/{export}/download', [\App\Http\Controllers\Tenant\DataExportController::class, 'download'])
        ->middleware('signed:relative')->name('export.download');

    // A tiny probe proving the connection was switched to THIS tenant's DB.
    Route::get('/health/tenant', function () {
        return response()->json([
            'tenant' => tenant('id'),
            'database' => DB::connection()->getDatabaseName(),
            'ledger_count' => Ledger::count(),
        ]);
    })->name('tenant.health');

    // ── the accounting app — behind tenant auth ──────────────────────────────
    Route::middleware('auth:tenant')->group(function () {
        Route::get('/app', [GatewayController::class, 'index'])->name('gateway');

        // Phase 16 — change own password (authenticated).
        Route::get('/password', [TenantPasswordController::class, 'changeForm'])->name('tenant.password.change');
        Route::post('/password', [TenantPasswordController::class, 'change'])->name('tenant.password.change.update');

        // Phase 14A — impersonation banner controls (write toggle + exit).
        Route::post('/_impersonate/write', [\App\Http\Controllers\Tenant\ImpersonationController::class, 'toggleWrite'])->name('impersonate.write');
        Route::post('/_impersonate/exit', [\App\Http\Controllers\Tenant\ImpersonationController::class, 'exit'])->name('impersonate.exit');

        // Phase 14B — subscription & manual payments (the claim POST is whitelisted in
        // RequireActiveTenant so an expired tenant can still renew).
        Route::get('/subscription', \App\Livewire\SubscriptionStatus::class)->name('subscription');
        Route::post('/subscription/claim', [\App\Http\Controllers\Tenant\TenantSubscriptionController::class, 'claim'])->name('subscription.claim');

        // Phase 14C — Settings → Data & Privacy (export + close account; both whitelisted in
        // RequireActiveTenant so an expired/suspended tenant can still get their data / leave).
        Route::get('/account/data', [\App\Http\Controllers\Tenant\DataPrivacyController::class, 'index'])->name('account.data');
        Route::post('/account/data/export', [\App\Http\Controllers\Tenant\DataPrivacyController::class, 'export'])->name('account.data.export');
        Route::post('/account/close', [\App\Http\Controllers\Tenant\DataPrivacyController::class, 'close'])->name('account.close');
        Route::get('/subscription/proof/{payment}', [\App\Http\Controllers\Tenant\TenantSubscriptionController::class, 'proof'])->name('subscription.proof');
        Route::get('/subscription/invoice/{payment}', [\App\Http\Controllers\Tenant\TenantSubscriptionController::class, 'invoice'])->name('subscription.invoice');

        // Phase 16A — Settings → API Keys. Issue/revoke the keys that let a customer's own
        // website or software reach this account over /api/v1/*.
        //
        // These are the ORDINARY tenant screens (subdomain + session + auth:tenant); only the
        // REST surface they administer is sessionless. Both components gate on role === 'owner'
        // in mount() and in every write action — this is the tenant app's first role-gated
        // screen, so the gate lives in the components rather than in a middleware that has no
        // other caller yet.
        Route::get('/settings/api-keys', \App\Livewire\ApiKeysList::class)->name('account.api-keys');
        Route::get('/settings/api-keys/{apiKey}/activity', \App\Livewire\ApiKeyActivity::class)->name('account.api-keys.activity');

        // Phase 16C — Settings → Webhooks. Register endpoints ZeroBook pushes events to.
        // Owner-only, gated in the component (mount + every action), like the API Keys screen.
        Route::get('/settings/webhooks', \App\Livewire\WebhooksList::class)->name('account.webhooks');

        // Phase 7C — desktop sync (outbox push / change-log pull). Same tenant DB,
        // same VoucherScreen::post() posting path. The desktop's session (from the
        // tenant login) authenticates these; the web SaaS never calls them.
        Route::post('/api/sync/push', [\App\Http\Controllers\Api\Sync\SyncController::class, 'push'])->name('sync.push');
        Route::get('/api/sync/pull', [\App\Http\Controllers\Api\Sync\SyncController::class, 'pull'])->name('sync.pull');

        // Phase 2 — Masters
        Route::get('/masters', [MastersController::class, 'index'])->name('masters.index');
        Route::get('/masters/groups', [MastersController::class, 'groups'])->name('masters.groups');
        Route::get('/masters/ledgers', [MastersController::class, 'ledgers'])->name('masters.ledgers');
        Route::get('/masters/cost-centres', [MastersController::class, 'costCentres'])->name('masters.cost-centres');
        Route::get('/masters/tds-sections', [MastersController::class, 'tdsSections'])->name('masters.tds-sections'); // Phase 10A
        Route::get('/masters/currencies', [MastersController::class, 'currencies'])->name('masters.currencies'); // Phase 11

        // Phase 6A — Inventory masters
        Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('/inventory/units', [InventoryController::class, 'units'])->name('inventory.units');
        Route::get('/inventory/stock-groups', [InventoryController::class, 'stockGroups'])->name('inventory.stock-groups');
        Route::get('/inventory/godowns', [InventoryController::class, 'godowns'])->name('inventory.godowns');
        Route::get('/inventory/stock-items', [InventoryController::class, 'stockItems'])->name('inventory.stock-items');

        // Phase 3/5 — Vouchers + Day Book
        Route::get('/vouchers/create/{type?}', [VouchersController::class, 'create'])->name('vouchers.create');
        Route::get('/vouchers/{voucher}/alter', [VouchersController::class, 'alter'])->name('vouchers.alter');
        Route::get('/vouchers/{voucher}/print', [VouchersController::class, 'print'])->name('vouchers.print');
        Route::get('/day-book', [VouchersController::class, 'dayBook'])->name('daybook');

        // Phase 4/5/6 — Reports
        Route::get('/reports/trial-balance', [ReportsController::class, 'trialBalance'])->name('reports.trial-balance');
        Route::get('/reports/balance-sheet', [ReportsController::class, 'balanceSheet'])->name('reports.balance-sheet');
        Route::get('/reports/profit-loss', [ReportsController::class, 'profitLoss'])->name('reports.profit-loss');
        Route::get('/reports/gst-summary', [ReportsController::class, 'gstSummary'])->name('reports.gst-summary');
        Route::get('/reports/vat-summary', [ReportsController::class, 'vatSummary'])->name('reports.vat-summary');
        Route::get('/reports/notes-register', [ReportsController::class, 'notesRegister'])->name('reports.notes-register'); // Phase 8A
        Route::get('/reports/orders-outstanding', [ReportsController::class, 'ordersOutstanding'])->name('reports.orders-outstanding'); // Phase 8B
        Route::get('/reports/gst-returns', [ReportsController::class, 'gstReturns'])->name('reports.gst-returns'); // Phase 9A
        Route::get('/reports/vat-return', [ReportsController::class, 'vatReturn'])->name('reports.vat-return'); // Phase 9B
        Route::get('/reports/tds-summary', [ReportsController::class, 'tdsSummary'])->name('reports.tds-summary'); // Phase 10A
        Route::get('/reports/tds-returns', [ReportsController::class, 'tdsReturns'])->name('reports.tds-returns'); // Phase 10B
        Route::get('/reports/forex-revaluation', [ReportsController::class, 'forexRevaluation'])->name('reports.forex-revaluation'); // Phase 11
        Route::get('/reports/lot-provenance', [ReportsController::class, 'lotProvenance'])->name('reports.lot-provenance'); // Phase 12C-1
        Route::get('/reports/lot-ledger', [ReportsController::class, 'lotLedger'])->name('reports.lot-ledger'); // Phase 13
        Route::get('/reports/group-trial-balance', [ReportsController::class, 'groupTrialBalance'])->name('reports.group-trial-balance'); // Phase 12C-2
        Route::get('/reports/group-balance-sheet', [ReportsController::class, 'groupBalanceSheet'])->name('reports.group-balance-sheet'); // Phase 12C-2
        Route::get('/reports/group-profit-loss', [ReportsController::class, 'groupProfitLoss'])->name('reports.group-profit-loss'); // Phase 12C-2
        Route::get('/reports/tds/{section}/{ledger}', [ReportsController::class, 'tdsDeductee'])->name('reports.tds-deductee'); // Phase 10A
        Route::get('/reports/cost-centres', [ReportsController::class, 'costBreakup'])->name('reports.cost-breakup');
        Route::get('/reports/cost-centre/{costCentre}', [ReportsController::class, 'costCentre'])->name('reports.cost-centre');
        Route::get('/reports/stock-summary', [ReportsController::class, 'stockSummary'])->name('reports.stock-summary');
        Route::get('/reports/stock-item/{stockItem}/movement', [ReportsController::class, 'stockItem'])->name('reports.stock-item');
        Route::get('/reports/receivables', [ReportsController::class, 'receivables'])->name('reports.receivables');
        Route::get('/reports/payables', [ReportsController::class, 'payables'])->name('reports.payables');
        Route::get('/reports/bill/{ledger}', [ReportsController::class, 'bill'])->name('reports.bill');
        Route::get('/reports/ledger/{ledger}/vouchers', [ReportsController::class, 'ledger'])->name('reports.ledger');

        // Phase 15A — Budgets (targets + actual-vs-budget variance). Gated in the menu by the
        // F11 `budgets` flag; the routes themselves stay reachable so a direct link still works.
        Route::get('/reports/budgets', [ReportsController::class, 'budgetList'])->name('reports.budget-list');
        Route::get('/reports/budgets/editor', [ReportsController::class, 'budgetEditor'])->name('reports.budget-editor');
        Route::get('/reports/budgets/variance', [ReportsController::class, 'budgetVariance'])->name('reports.budget-variance');
        Route::get('/reports/budgets/summary', [ReportsController::class, 'budgetSummary'])->name('reports.budget-summary');

        // Phase 15B — Ratio Analysis (financial health ratios). Gated in the menu by the F11
        // ratio_analysis flag; the screens themselves redirect to the Gateway when it is off.
        Route::get('/reports/ratios', [ReportsController::class, 'ratioDashboard'])->name('reports.ratio-dashboard');
        Route::get('/reports/ratios/{ratio}', [ReportsController::class, 'ratioDrilldown'])->name('reports.ratio-drilldown');
        Route::get('/reports/ratio-thresholds', [ReportsController::class, 'ratioThresholds'])->name('reports.ratio-thresholds');

        // Phase 15C — Scenarios (provisional what-if vouchers). Gated in the menu AND at the
        // controller by the F11 `scenarios` flag; the screens redirect to the Gateway when off.
        Route::get('/reports/scenarios', [ReportsController::class, 'scenarioMaster'])->name('reports.scenario-master');
        Route::get('/reports/scenarios/manage', [ReportsController::class, 'scenarioManager'])->name('reports.scenario-manager');
        Route::get('/reports/scenarios/impact', [ReportsController::class, 'scenarioImpact'])->name('reports.scenario-impact');

        // Phase 4 — F11 Features (with the Phase 7B plan gate)
        Route::get('/features', [FeaturesController::class, 'index'])->name('features');

        // Phase 12A — multi-company: manage companies + switch the active one (F1).
        Route::get('/companies', [\App\Http\Controllers\CompaniesController::class, 'index'])->name('companies');
        Route::get('/companies/groups', [\App\Http\Controllers\CompaniesController::class, 'groups'])->name('companies.groups'); // Phase 12B
        Route::post('/company/switch', [\App\Http\Controllers\CompaniesController::class, 'switch'])->name('company.switch');
        // Phase 16 — create a company inline from the F1 picker when nothing matches.
        Route::post('/companies', [\App\Http\Controllers\CompaniesController::class, 'store'])->name('company.create');

        // NAS parity Phase 2 — "Display More Reports", the second level of the
        // Reports tree (Gateway ▸ M).
        Route::get('/reports/more', [GatewayController::class, 'reports'])->name('reports.more');

        // The keyboard harness is a DEVELOPER verification screen. It was reachable
        // by any signed-in customer in production and listed on the Gateway; it is
        // now confined to local/testing. The route is still NAMED in every
        // environment so route('dev.harness') never throws — it just 404s in
        // production.
        Route::get('/dev/keyboard-harness', function (KeyboardHarnessController $controller) {
            abort_unless(app()->environment(['local', 'testing']), 404);

            return $controller->index();
        })->name('dev.harness');
    });
});
