<?php

namespace App\Services\Tenancy;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Hostinger\HostingerService;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 16 — end-to-end auto-provisioning on Hostinger shared hosting.
 *
 * The app's MySQL user cannot CREATE DATABASE, so this orchestrates the Hostinger API to
 * create the per-tenant infrastructure, then runs the normal tenant build:
 *
 *   subdomain vhost  →  MySQL database (+ user + password)  →  migrate  →  seed  →  active
 *
 * Idempotent / repair-forward: re-running completes a partial (subdomain + DB creates are
 * skipped when they already exist; migrate/seed are themselves idempotent).
 */
class InfraProvisioner
{
    public function __construct(
        private HostingerService $hostinger,
        private TenantProvisioner $provisioner,
    ) {}

    public function provision(string $slug, string $name, string $planTier, string $country): Tenant
    {
        $slug = strtolower(trim($slug));
        $plan = Plan::where('tier', $planTier)->firstOrFail();

        // Central registry row (idempotent on the slug PK).
        $tenant = Tenant::firstOrCreate(
            ['id' => $slug],
            ['name' => $name, 'plan_id' => $plan->id, 'status' => 'provisioning'],
        );
        $tenant->name = $name ?: $tenant->name;
        $tenant->plan_id = $plan->id;
        if ($tenant->isDirty()) {
            $tenant->save();
        }

        // 1) Subdomain vhost — serves the app's public directory. Idempotent + async.
        $this->hostinger->ensureSubdomain($slug);

        // 2) Database + user. Create only if we haven't already recorded credentials for it.
        if (! $tenant->getInternal('db_name') || ! $tenant->getInternal('db_password')) {
            $dbName = $this->hostinger->databaseNameFor($slug);

            if ($this->hostinger->databaseExists($dbName)) {
                throw new RuntimeException("Database “{$dbName}” already exists but no password is stored on the tenant. Delete it in hPanel and retry, or link it manually.");
            }

            $password = Str::random(24);              // alphanumeric — safe everywhere
            $this->hostinger->createDatabase($slug, $password);
            $this->hostinger->waitForDatabase($dbName);

            $tenant->setInternal('db_name', $dbName);
            $tenant->setInternal('db_username', $dbName);   // Hostinger names the user like the DB
            $tenant->setInternal('db_password', $password);
            $tenant->save();
        }

        // 3) Migrate + seed + tax regime + activate (no CREATE DATABASE anywhere).
        return $this->provisioner->adoptExistingDatabase($tenant, $country);
    }
}
