<?php

namespace App\Services\Tenancy;

use App\Models\Company;
use App\Models\CompanyFeature;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\ActiveCompany;
use App\Support\Subdomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\DeleteDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;
use Throwable;

/**
 * Provisions (and tears down) tenants.
 *
 * A new tenant starts life with a working, seeded, correct chart of accounts — its
 * own database migrated with the ENTIRE tenant migration set and seeded (28 predefined
 * groups, Cash + Profit & Loss A/c, Main Location godown, GST/VAT duty ledgers) — not
 * an empty DB.
 *
 * Two entry points, ONE code path underneath (Phase 14A):
 *
 *   • provision()           — the CLI / ops path (zerobook:tenant-provision). Idempotent
 *                             and repair-forward: a provision that failed halfway is
 *                             completed by simply running it again, never double-seeded.
 *                             Ends 'active'. An operator can re-run to fix a partial.
 *
 *   • provisionForSignup()  — the PUBLIC self-signup path. A prospective customer cannot
 *                             re-run a repair, so a partial provision is UNACCEPTABLE:
 *                             any failure rolls the whole thing back (drops the tenant
 *                             database, deletes the central row — which cascades the
 *                             half-created admin user) so the tenant is fully absent and
 *                             the subdomain is free to try again. Ends
 *                             'pending_verification' with the admin user created but
 *                             unable to log in until they confirm their email.
 *
 * Both share the private DB steps (ensureDatabase → migrate → seed → regime).
 */
class TenantProvisioner
{
    /**
     * CLI / ops provisioning. Idempotent, repair-forward, ends 'active'.
     *
     * $country (optional) sets the default company's tax regime + base currency
     * (india → GST/INR, nepal → VAT/NPR); omit to leave the seeded all-off F11 as is.
     */
    public function provision(string $slug, ?string $name = null, ?string $planTier = null, ?string $country = null): Tenant
    {
        // Never carry a previous tenant's active company into this provisioning run
        // (one artisan process can provision several tenants).
        ActiveCompany::set(null);

        $slug = $this->normalizeSlug($slug);
        $plan = $this->resolvePlan($planTier);

        // 1) Central registry row (idempotent on the slug PK).
        $tenant = Tenant::firstOrCreate(
            ['id' => $slug],
            ['name' => $name ?: ucfirst($slug), 'plan_id' => $plan->id, 'status' => 'provisioning'],
        );
        // Keep name/plan in sync if given on a repair run.
        $tenant->fill(array_filter([
            'name' => $name,
            'plan_id' => $planTier ? $plan->id : null,
        ]));
        if ($tenant->isDirty()) {
            $tenant->save();
        }

        $this->ensureDomain($tenant, $slug);
        $this->ensureDatabase($tenant);
        $this->migrate($tenant);
        $this->seedOnce($tenant);

        if ($country !== null) {
            $this->applyCountryRegime($tenant, $country);
        }

        $tenant->status = 'active';
        $tenant->provisioned_at = $tenant->provisioned_at ?? now();
        $tenant->save();

        return $tenant->refresh();
    }

    /**
     * Phase 16 — ADOPT a manually pre-created tenant database (shared-hosting path).
     *
     * On hosts where the app's MySQL user cannot CREATE DATABASE (e.g. Hostinger shared
     * hosting), an operator creates the schema out-of-band and records its coordinates on
     * the tenant (tenancy_db_name and, optionally, tenancy_db_username / tenancy_db_password
     * — set by the caller BEFORE calling this). This skips database CREATION entirely and
     * runs the exact same migrate → seed → regime → active pipeline against the adopted DB.
     *
     * Idempotent / repair-forward, like provision(): re-running completes a partial link.
     */
    public function adoptExistingDatabase(Tenant $tenant, ?string $country = null): Tenant
    {
        ActiveCompany::set(null);

        $this->ensureDomain($tenant, $tenant->getKey());
        // No ensureDatabase() — the schema was created manually; its name/credentials live
        // on the tenant and drive the tenant connection (see Stancl DatabaseConfig).
        $this->migrate($tenant);
        $this->seedOnce($tenant);

        if ($country !== null) {
            $this->applyCountryRegime($tenant, $country);
        }

        $tenant->status = 'active';
        $tenant->provisioned_at = $tenant->provisioned_at ?? now();
        $tenant->save();

        return $tenant->refresh();
    }

    /**
     * Public self-signup provisioning: transactional & fully reversible.
     *
     * $data keys: subdomain, company_name, country (india|nepal),
     *             admin_name, admin_email, admin_password (plain — hashed here).
     *
     * Returns ['tenant' => Tenant, 'admin' => TenantUser]. The caller sends the
     * verification email; this method only builds recoverable state.
     *
     * $afterProvision is an optional hook run once the tenant is fully built (DB created,
     * migrated, seeded, regime applied, admin user created) but before it is marked
     * ready. A future phase (e.g. 14B billing) can attach post-provision setup here; if
     * it throws, the WHOLE provision rolls back — which is also how prove-signup forces a
     * mid-provisioning failure to exercise the rollback path.
     */
    public function provisionForSignup(array $data, ?callable $afterProvision = null): array
    {
        ActiveCompany::set(null);

        $slug = $this->normalizeSlug($data['subdomain']);
        $country = $this->assertCountry($data['country']);
        $plan = $this->resolvePlan(config('zerobook.signup_plan_tier', 'trial'));
        $trialDays = (int) config('zerobook.trial_days', 30);

        // Guard against a race: never provision over an existing slug.
        if (Subdomain::isTaken($slug)) {
            throw new RuntimeException("The subdomain “{$slug}” is already taken.");
        }
        if (Subdomain::isReserved($slug)) {
            throw new RuntimeException("The subdomain “{$slug}” is reserved.");
        }

        // 1) Central row — status 'provisioning', on the trial plan, with a trial window.
        $tenant = Tenant::create([
            'id' => $slug,
            'name' => trim($data['company_name']),
            'plan_id' => $plan->id,
            'status' => 'provisioning',
            'trial_ends_at' => now()->addDays($trialDays),
        ]);

        try {
            $this->ensureDomain($tenant, $slug);        // 2a) DNS label
            $this->ensureDatabase($tenant);             // 2b) create the tenant database
            $this->migrate($tenant);                    // 3) run tenant migrations
            $this->seedOnce($tenant);                   // 4) seed (+ default company)
            $this->applyCountryRegime($tenant, $country); // 5) regime on the default company
            $admin = $this->createAdminUser($tenant, $data); // 6) admin login (unverified)

            if ($afterProvision !== null) {
                $afterProvision($tenant, $admin); // optional post-provision setup (14B seam)
            }

            // 7) Ready to be verified — nobody can log in until the email is confirmed.
            $tenant->status = 'pending_verification';
            $tenant->provisioned_at = now();
            $tenant->save();
        } catch (Throwable $e) {
            // 8) Roll back cleanly — never leave a half-provisioned tenant. teardown()
            //    drops the DB (if created) and deletes the central row, which cascades
            //    the domain and any admin user created above.
            $this->safeTeardown($slug);

            throw $e;
        }

        return ['tenant' => $tenant->refresh(), 'admin' => $admin];
    }

    /** Drop a tenant's database and central records. Idempotent. */
    public function teardown(string $slug): void
    {
        $slug = $this->normalizeSlug($slug);
        $tenant = Tenant::find($slug);
        if (! $tenant) {
            return;
        }

        $dbName = $tenant->database()->getName();
        if ($this->databaseExists($dbName)) {
            dispatch_sync(new DeleteDatabase($tenant));
        }

        // Phase 14A — forget this database's BelongsToCompany schema verdicts, so a retry
        // that recreates a same-named DB in this process re-checks the (now absent) column
        // during its early migrations instead of failing closed on a stale "present" memo.
        \App\Support\CompanySchemaMemo::forgetDatabase($dbName);

        $tenant->domains()->delete();
        // Deleting the tenant cascades tenant_users (FK cascadeOnDelete).
        $tenant->delete();
    }

    // ── shared private steps ──────────────────────────────────────────────────

    private function ensureDomain(Tenant $tenant, string $slug): void
    {
        // The LABEL only ('acme', not the FQDN). Guard the unique key.
        if (! $tenant->domains()->where('domain', $slug)->exists()) {
            $tenant->createDomain(['domain' => $slug]);
        }
    }

    private function ensureDatabase(Tenant $tenant): void
    {
        // CreateDatabase would throw if the schema already existed.
        if (! $this->databaseExists($tenant->database()->getName())) {
            dispatch_sync(new CreateDatabase($tenant));
        }
    }

    private function migrate(Tenant $tenant): void
    {
        // Inherently idempotent (Laravel skips already-applied migrations). Pulls
        // --path=database/migrations/tenant, --realpath, --force from config.
        dispatch_sync(new MigrateDatabase($tenant));
    }

    private function seedOnce(Tenant $tenant): void
    {
        // DatabaseSeeder is not idempotent for all rows; gate on a flag in `data`.
        if (! $tenant->getInternal('seeded')) {
            dispatch_sync(new SeedDatabase($tenant));
            $tenant->setInternal('seeded', true);
            $tenant->save();
        }
    }

    /**
     * Set the default company's tax regime + base currency from the country choice.
     * Runs inside the tenant's database (the connection is switched by run()).
     *
     *   india → GST regime, INR base (INR is already the seeded base — no-op currency)
     *   nepal → VAT regime, NPR base (create NPR, mark it base, un-mark INR)
     *
     * Idempotent: re-running sets the same flags and re-marks the same base currency.
     */
    private function applyCountryRegime(Tenant $tenant, string $country): void
    {
        $cfg = (array) (config("zerobook.countries.{$country}") ?? []);
        $regime = $cfg['regime'] ?? 'gst'; // 'gst' | 'vat'

        $tenant->run(function () use ($regime, $cfg) {
            $company = Company::defaultCompany();
            if (! $company) {
                throw new RuntimeException('Provisioning produced no default company to configure.');
            }

            ActiveCompany::runAs($company->id, function () use ($company, $regime, $cfg) {
                // Regime flags are mutually exclusive at the company level.
                $features = CompanyFeature::current();
                $features->gst = ($regime === 'gst');
                $features->vat = ($regime === 'vat');
                $features->save();

                // Base currency. India keeps the seeded INR base. Nepal switches to NPR.
                $wantCode = $cfg['currency'] ?? 'INR';
                if ($wantCode !== 'INR') {
                    $base = Currency::firstOrCreate(
                        ['code' => $wantCode],
                        [
                            'symbol' => $cfg['currency_symbol'] ?? $wantCode,
                            'name' => $cfg['currency_name'] ?? $wantCode,
                            'decimal_places' => 2,
                            'is_base' => false,
                        ],
                    );

                    DB::transaction(function () use ($base, $company) {
                        // Scoped Currency model → touches only this company's rows.
                        Currency::query()->update(['is_base' => false]);
                        Currency::whereKey($base->id)->update(['is_base' => true]);
                        $company->update(['base_currency_id' => $base->id]);
                        ActiveCompany::refresh();
                    });
                }
            });
        });
    }

    private function createAdminUser(Tenant $tenant, array $data): TenantUser
    {
        // TenantUser is central (UsesCentralConnection) — created regardless of the
        // active tenant connection, keyed (tenant_id, email). The owner starts
        // UNVERIFIED (verified_at null); email confirmation flips the tenant to active.
        return TenantUser::create([
            'tenant_id' => $tenant->id,
            'name' => trim($data['admin_name']),
            'email' => strtolower(trim($data['admin_email'])),
            'password' => Hash::make($data['admin_password']),
            'role' => 'owner',
            'verified_at' => null,
        ]);
    }

    /** teardown() but never throws — used inside the rollback catch. */
    private function safeTeardown(string $slug): void
    {
        try {
            $this->teardown($slug);
        } catch (Throwable $e) {
            report($e); // best-effort; the original signup failure is what we rethrow
        }
    }

    /** Whether a database of this name exists (checked on the CENTRAL connection). */
    private function databaseExists(string $dbName): bool
    {
        $central = config('tenancy.database.central_connection');

        return DB::connection($central)->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$dbName],
        ) !== null;
    }

    private function resolvePlan(?string $tier): Plan
    {
        $tier = $tier ?: 'starter';
        $plan = Plan::where('tier', $tier)->first();
        if (! $plan) {
            $available = Plan::pluck('tier')->implode(', ');
            throw new RuntimeException("Unknown plan tier “{$tier}”. Available: {$available}.");
        }

        return $plan;
    }

    private function assertCountry(string $country): string
    {
        $country = strtolower(trim($country));
        if (! array_key_exists($country, (array) config('zerobook.countries', []))) {
            $known = implode(', ', array_keys((array) config('zerobook.countries', [])));
            throw new RuntimeException("Unknown country “{$country}”. Available: {$known}.");
        }

        return $country;
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $slug)) {
            throw new RuntimeException("Invalid subdomain “{$slug}” — use lowercase letters, digits and hyphens (a valid DNS label).");
        }

        return $slug;
    }
}
