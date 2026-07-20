<?php

namespace App\Services\Platform;

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantUrl;
use Illuminate\Support\Facades\URL;

/**
 * Phase 14A — platform-admin impersonation, with a safe cross-domain hand-off.
 *
 * The admin surface (admin.zerobook.local) and a tenant app (<slug>.zerobook.local) are
 * different origins with separate sessions, so an admin cannot simply "be logged in" on
 * the tenant. Instead start() records the intent in the audit log and mints a SHORT-LIVED,
 * host-independent SIGNED URL to the tenant's consume route; the browser follows it onto
 * the tenant subdomain, where the tenancy middleware switches to the tenant DB and the
 * consume controller logs the admin in AS the tenant's owner and stamps the impersonation
 * session flags (TenantGate::SESSION_*). Isolation is preserved by construction — the
 * admin becomes an ordinary tenant-scoped session on exactly one tenant.
 *
 * Writes are OFF by default (view-only); the write toggle is carried in the signed URL
 * and can be flipped later from the banner (each flip logged separately).
 */
class ImpersonationService
{
    /** How long the hand-off link is valid. */
    private const HANDOFF_TTL_MINUTES = 5;

    public function __construct(private PlatformActions $actions)
    {
    }

    /**
     * Record the start and return the signed cross-domain consume URL to redirect to.
     */
    public function start(PlatformAdmin $admin, Tenant $tenant, TenantUser $target, bool $write, string $reason): string
    {
        $action = $this->actions->log($admin, 'impersonate_start', $tenant, [
            'target_user_id' => $target->id,
            'reason' => $reason,
            'meta' => ['write' => $write],
        ]);

        $relative = URL::temporarySignedRoute(
            'impersonate.consume',
            now()->addMinutes(self::HANDOFF_TTL_MINUTES),
            [
                'action' => $action->id,
                'user' => $target->id,
                'write' => $write ? 1 : 0,
            ],
            absolute: false,
        );

        return TenantUrl::forTenant($tenant->id, $relative);
    }
}
