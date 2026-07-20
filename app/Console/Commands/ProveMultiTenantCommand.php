<?php

namespace App\Console\Commands;

use App\Livewire\FeaturesScreen;
use App\Livewire\VoucherScreen;
use App\Models\AccountGroup;
use App\Models\CompanyFeature;
use App\Models\Ledger;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\PlanGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Phase 7B — the multi-tenant correctness proof.
 *
 * Provisions two fresh tenants (alpha, beta), then asserts:
 *   1. Every one of the ten prior prove-* commands passes IN EACH tenant's own
 *      database — every Phase 1–7A invariant still holds per-tenant.
 *   2. Data written in alpha is INVISIBLE from beta and vice-versa (database-per-
 *      tenant isolation), and a cross-read via the central connection is rejected.
 *   3. The CENTRAL database contains no accounting data at all — the accounting
 *      tables don't even exist there.
 *   4. Each tenant is a distinct physical database on its own plan.
 *
 * Tears the two tenants down afterwards (drops their databases) unless --keep.
 */
class ProveMultiTenantCommand extends Command
{
    protected $signature = 'zerobook:prove-multi-tenant {--keep : keep the alpha/beta tenants provisioned}';

    protected $description = 'Provision two tenants, run the full prove battery in each, and prove complete cross-tenant isolation';

    /** The full prior prove battery — all ten run inside each tenant. */
    private const PROVES = [
        'prove-balance', 'prove-sales-purchase', 'prove-gst', 'prove-vat',
        'prove-billwise', 'prove-costcentre', 'prove-item-invoice',
        'prove-stock-journal', 'prove-inventory-integration', 'prove-tally-import',
    ];

    private bool $ok = true;

    public function handle(TenantProvisioner $provisioner): int
    {
        $tenants = ['alpha' => 'starter', 'beta' => 'professional'];

        try {
            // ── provision two fresh tenants ──────────────────────────────────
            $this->section('Provisioning two fresh tenants');
            foreach ($tenants as $slug => $tier) {
                $provisioner->teardown($slug); // start clean even if a prior run left them
                $t = $provisioner->provision($slug, ucfirst($slug).' Books', $tier);
                $this->expect("Provisioned {$slug} → DB {$t->database()->getName()} [{$t->plan->tier}]", $t->status, 'active');
            }

            // ── 1. the full prove battery, inside each tenant ────────────────
            $this->section('Running all 10 prove-* commands inside each tenant');
            foreach (array_keys($tenants) as $slug) {
                $tenant = Tenant::find($slug);
                tenancy()->initialize($tenant);
                try {
                    foreach (self::PROVES as $p) {
                        $code = Artisan::call('zerobook:'.$p);
                        $this->expect(sprintf('[%-5s] zerobook:%s', $slug, $p), $code, 0);
                        if ($code !== 0) {
                            $this->line('        '.trim(Artisan::output()));
                        }
                    }
                } finally {
                    tenancy()->end();
                }
            }

            // ── 2. cross-tenant isolation ────────────────────────────────────
            $this->section('Cross-tenant isolation');
            // Write a distinctive, PERSISTENT marker ledger in each tenant.
            foreach (array_keys($tenants) as $slug) {
                Tenant::find($slug)->run(function () use ($slug) {
                    \App\Support\ActiveCompany::set(\App\Models\Company::defaultCompany()->id); // Phase 12A — pin THIS tenant's default company
                    $gid = AccountGroup::where('name', 'Sundry Debtors')->value('id');
                    Ledger::firstOrCreate(['name' => 'MARK-'.strtoupper($slug)], ['group_id' => $gid, 'country' => 'India']);
                });
            }
            // Phase 12A — each probe pins its own tenant's default company first.
            $probe = function (string $slug, string $marker) {
                return Tenant::find($slug)->run(function () use ($marker) {
                    \App\Support\ActiveCompany::set(\App\Models\Company::defaultCompany()->id);

                    return Ledger::where('name', $marker)->exists();
                });
            };
            $alphaSeesBeta = $probe('alpha', 'MARK-BETA');
            $betaSeesAlpha = $probe('beta', 'MARK-ALPHA');
            $alphaSeesOwn = $probe('alpha', 'MARK-ALPHA');
            $betaSeesOwn = $probe('beta', 'MARK-BETA');
            $this->expect('alpha sees its OWN marker', $alphaSeesOwn, true);
            $this->expect('beta sees its OWN marker', $betaSeesOwn, true);
            $this->expect('alpha CANNOT see beta\'s data', $alphaSeesBeta, false);
            $this->expect('beta CANNOT see alpha\'s data', $betaSeesAlpha, false);

            // A deliberate cross-read via the WRONG (central) connection is rejected:
            // the accounting tables do not exist there, so the query throws.
            $central = config('tenancy.database.central_connection');
            $crossReadRejected = false;
            try {
                DB::connection($central)->table('ledgers')->where('name', 'MARK-ALPHA')->exists();
            } catch (Throwable $e) {
                $crossReadRejected = true;
            }
            $this->expect('Cross-read of tenant data via central connection is REJECTED', $crossReadRejected, true);

            // ── 3. central holds no accounting data ──────────────────────────
            $this->section('Central database is clean of accounting data');
            foreach (['ledgers', 'vouchers', 'voucher_entries', 'stock_entries', 'account_groups'] as $table) {
                $this->expect("central has NO `{$table}` table", Schema::connection($central)->hasTable($table), false);
            }
            $this->expect('central DOES have the tenants registry', Schema::connection($central)->hasTable('tenants'), true);

            // ── 4. distinct physical databases, distinct plans ───────────────
            $this->section('Distinct databases + plans');
            $this->expect('alpha DB = tenantalpha', Tenant::find('alpha')->database()->getName(), 'tenantalpha');
            $this->expect('beta DB = tenantbeta', Tenant::find('beta')->database()->getName(), 'tenantbeta');
            $this->expect('alpha plan = starter', Tenant::find('alpha')->plan->tier, 'starter');
            $this->expect('beta plan = professional', Tenant::find('beta')->plan->tier, 'professional');
            $this->expect('the two tenant databases are different', Tenant::find('alpha')->database()->getName() !== Tenant::find('beta')->database()->getName(), true);

            // ── 5. the interactive path, per-tenant ──────────────────────────
            // Post a Payment through the REAL Livewire method (what the browser
            // subdomain flow ultimately calls) in alpha, and confirm it lands in
            // alpha's Day Book — and only alpha's.
            $this->section('Interactive path (create ledger → post Payment → Day Book)');
            $postedNumber = Tenant::find('alpha')->run(function () {
                \App\Support\ActiveCompany::set(\App\Models\Company::defaultCompany()->id); // Phase 12A
                DB::beginTransaction();
                $party = Ledger::create(['name' => 'IX Supplier', 'group_id' => AccountGroup::where('name', 'Sundry Creditors')->value('id'), 'country' => 'India']);
                $res = (new VoucherScreen())->post(['type' => 'payment', 'date' => '2026-07-10', 'narration' => 'Interactive', 'lines' => [
                    ['ledger_id' => $party->id, 'dr_cr' => 'Dr', 'amount' => 1500],
                    ['ledger_id' => Ledger::where('name', 'Cash')->value('id'), 'dr_cr' => 'Cr', 'amount' => 1500],
                ]]);
                $inDayBook = Voucher::with('entries.ledger')->find($res['voucher']['id'])?->toRow()['amount'];
                DB::rollBack(); // keep alpha pristine; the point is that it WORKED per-tenant

                return [$res['voucher']['number'], $inDayBook];
            });
            $this->expect('Payment posts + shows in alpha Day Book at 1,500', $postedNumber, [1, 1500.0]);

            // ── 6. plan gate, per plan tier ──────────────────────────────────
            $this->section('F11 plan gate');
            // alpha = starter: cost_centres + multi_currency locked.
            Tenant::find('alpha')->run(function () {
                \App\Support\ActiveCompany::set(\App\Models\Company::defaultCompany()->id); // Phase 12A
                $this->expect('[alpha/starter] GST allowed', PlanGate::allows('gst'), true);
                $this->expect('[alpha/starter] Cost Centres LOCKED', PlanGate::allows('cost_centres'), false);
                $this->expect('[alpha/starter] enabling Cost Centres yields a violation', PlanGate::violation(['cost_centres']) !== null, true);
                // server-side rejection: the F11 save refuses a locked feature and does not persist it.
                DB::beginTransaction();
                $fs = new FeaturesScreen();
                $fs->mount();
                $fs->cost_centres = true;
                $rejected = empty($fs->save()) && $fs->getErrorBag()->has('plan');
                $persisted = (bool) CompanyFeature::current()->cost_centres;
                DB::rollBack();
                $this->expect('[alpha/starter] F11 save REJECTS locked Cost Centres', $rejected, true);
                $this->expect('[alpha/starter] …and does NOT persist it', $persisted, false);
            });
            // beta = professional: cost_centres allowed, multi_currency still locked.
            Tenant::find('beta')->run(function () {
                \App\Support\ActiveCompany::set(\App\Models\Company::defaultCompany()->id); // Phase 12A
                $this->expect('[beta/professional] Cost Centres allowed', PlanGate::allows('cost_centres'), true);
                $this->expect('[beta/professional] Multi-Currency LOCKED', PlanGate::allows('multi_currency'), false);
                $this->expect('[beta/professional] enabling Cost Centres is permitted', PlanGate::violation(['cost_centres']), null);
            });
        } catch (Throwable $e) {
            $this->ok = false;
            $this->error('Fatal: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());
        } finally {
            if ($this->option('keep')) {
                $this->line('');
                $this->info('Tenants alpha + beta KEPT (--keep). Their databases remain provisioned.');
            } else {
                foreach (array_keys($tenants) as $slug) {
                    $provisioner->teardown($slug);
                }
                $this->line('');
                $this->info('Tenants alpha + beta torn down (databases dropped).');
            }
        }

        $this->line('');
        $this->info($this->ok
            ? 'ALL MULTI-TENANT ASSERTIONS PASSED — isolation holds; every invariant holds per-tenant.'
            : 'MULTI-TENANT ASSERTIONS FAILED.');

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->line('── '.$title.' '.str_repeat('─', max(0, 58 - strlen($title))));
    }

    private function expect(string $label, $actual, $expected): void
    {
        $pass = $actual === $expected;
        $shown = is_bool($actual) ? ($actual ? 'true' : 'false') : (is_scalar($actual) ? (string) $actual : gettype($actual));
        $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.$shown);
        $this->ok = $this->ok && $pass;
    }
}
