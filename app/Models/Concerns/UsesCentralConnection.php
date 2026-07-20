<?php

namespace App\Models\Concerns;

/**
 * Pins a model to the CENTRAL database connection (Phase 7B).
 *
 * When a tenant is initialized, stancl/tenancy remaps the DEFAULT connection to
 * that tenant's database. Central-only models (Plan, TenantUser, PlatformAdmin —
 * and the Tenant registry itself) must NOT follow that remap, or a lookup made
 * while a tenant is active (e.g. reading the tenant's plan on the F11 screen)
 * would hit the tenant DB and find nothing — or, worse, the wrong tenant's row.
 *
 * Resolving the connection name from config at call time (rather than a hard-coded
 * string) keeps it correct even if the central connection is renamed.
 */
trait UsesCentralConnection
{
    public function getConnectionName()
    {
        return config('tenancy.database.central_connection');
    }
}
