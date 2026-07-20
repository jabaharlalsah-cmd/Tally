<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminAction;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\Platform\PlatformActions;
use App\Support\TenantGate;
use App\Support\TenantUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 14A — the tenant-subdomain end of platform-admin impersonation.
 *
 * consume(): receives the short-lived signed hand-off minted on the admin surface,
 * verifies it names a real impersonate_start for THIS tenant + user, logs the admin in
 * AS the tenant's owner (ordinary tenant guard → full tenancy isolation applies), and
 * stamps the impersonation session flags TenantGate reads. Writes are OFF unless the
 * hand-off enabled them.
 *
 * toggleWrite(): flips the view-only ↔ read-write toggle from the banner (logged).
 * exit(): ends the session, clears the flags, and returns to the admin surface (logged).
 */
class ImpersonationController extends Controller
{
    /** GET /_impersonate/consume — behind signed:relative. */
    public function consume(Request $request)
    {
        $actionId = (int) $request->query('action');
        $userId = (int) $request->query('user');
        $write = (bool) ((int) $request->query('write', 0));

        $action = PlatformAdminAction::find($actionId);

        // The link must name a genuine impersonation start, for this exact tenant + user.
        if (! $action
            || $action->action !== 'impersonate_start'
            || $action->tenant_id !== tenant('id')
            || (int) $action->target_user_id !== $userId) {
            abort(403, 'This impersonation link is invalid.');
        }

        $user = TenantUser::where('tenant_id', tenant('id'))->whereKey($userId)->first();
        if (! $user) {
            abort(403, 'The impersonation target no longer exists.');
        }

        Auth::guard('tenant')->login($user);

        session([
            TenantGate::SESSION_ADMIN_ID => $action->admin_id,
            TenantGate::SESSION_ADMIN_EMAIL => $action->admin?->email ?? 'platform admin',
            TenantGate::SESSION_TENANT => tenant('id'),
            TenantGate::SESSION_TARGET_USER => $user->id,
            TenantGate::SESSION_WRITE => $write,
            TenantGate::SESSION_ACTION_ID => $action->id,
            TenantGate::SESSION_REASON => $action->reason,
        ]);

        $request->session()->regenerate();

        return redirect()->route('gateway');
    }

    /** POST /_impersonate/write — flip the write toggle (logged separately). */
    public function toggleWrite(Request $request, PlatformActions $actions)
    {
        if (! TenantGate::isImpersonating()) {
            abort(403);
        }

        $enable = ! TenantGate::impersonationWriteEnabled();
        session([TenantGate::SESSION_WRITE => $enable]);

        $actions->log(
            PlatformAdmin::find(session(TenantGate::SESSION_ADMIN_ID)),
            $enable ? 'impersonate_write_on' : 'impersonate_write_off',
            Tenant::find(session(TenantGate::SESSION_TENANT)),
            [
                'target_user_id' => session(TenantGate::SESSION_TARGET_USER),
                'reason' => session(TenantGate::SESSION_REASON),
            ],
        );

        return back();
    }

    /** POST /_impersonate/exit — end the session and return to the admin surface. */
    public function exit(Request $request, PlatformActions $actions)
    {
        $tenantSlug = session(TenantGate::SESSION_TENANT) ?? tenant('id');

        if (TenantGate::isImpersonating()) {
            $actions->log(
                PlatformAdmin::find(session(TenantGate::SESSION_ADMIN_ID)),
                'impersonate_end',
                Tenant::find($tenantSlug),
                [
                    'target_user_id' => session(TenantGate::SESSION_TARGET_USER),
                    'reason' => session(TenantGate::SESSION_REASON),
                ],
            );
        }

        // Compute the base domain BEFORE tearing the session down (we're on the tenant
        // host <slug>.<base>, so the base is behind the slug label, not an admin./www. prefix).
        $base = TenantUrl::baseDomainWithoutTenant($tenantSlug);

        Auth::guard('tenant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->away(TenantUrl::admin('/admin/tenants/'.$tenantSlug, $base));
    }
}
