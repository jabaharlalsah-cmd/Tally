<?php

namespace App\Console\Concerns;

use App\Models\Tenant;

/**
 * Scopes an artisan command to a tenant's database (Phase 7B).
 *
 * A command that touches accounting data must never run against the CENTRAL
 * database (which holds none). This concern resolves the scope:
 *   • already inside a tenant (a parent command initialized tenancy) → run inline;
 *   • a --tenant=<subdomain> was passed → initialize it, run, then revert;
 *   • otherwise → REFUSE (return null) so the command exits without touching central.
 */
trait RunsInTenantContext
{
    /**
     * Returns a wrapper `fn(callable $work) => mixed` that runs $work in the tenant
     * context, or null if the command must refuse (already reported to the console).
     */
    protected function resolveTenantScope(): ?callable
    {
        // A parent already put us in a tenant (e.g. zerobook:prove-multi-tenant).
        if (tenancy()->initialized) {
            return fn (callable $work) => $work();
        }

        $slug = $this->option('tenant');
        if (! $slug) {
            $this->error('Refusing to run against the CENTRAL database — this command needs a tenant.');
            $this->line('  Pass --tenant=<subdomain>, e.g. --tenant=alpha');

            return null;
        }

        $tenant = Tenant::find($slug);
        if (! $tenant) {
            $this->error("Unknown tenant “{$slug}”. Provision it first: php artisan zerobook:tenant-provision {$slug}");

            return null;
        }

        return function (callable $work) use ($tenant) {
            tenancy()->initialize($tenant);
            try {
                return $work();
            } finally {
                tenancy()->end();
            }
        };
    }
}
