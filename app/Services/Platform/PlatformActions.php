<?php

namespace App\Services\Platform;

use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminAction;
use App\Models\Tenant;

/**
 * Phase 14A — the platform-admin tenant-lifecycle actions, each audit-logged.
 *
 * Suspend / reactivate / extend-trial / plan-change all funnel through here so every
 * one writes a platform_admin_actions row (who, what, which tenant, why, when, from
 * where) before returning. The admin surface never mutates a tenant's status/plan
 * directly — it calls these.
 */
class PlatformActions
{
    /** Append one row to the platform-admin audit log. */
    public function log(?PlatformAdmin $admin, string $action, ?Tenant $tenant, array $opts = []): PlatformAdminAction
    {
        return PlatformAdminAction::create([
            'admin_id' => $admin?->id,
            'action' => $action,
            'tenant_id' => $tenant?->id,
            'target_user_id' => $opts['target_user_id'] ?? null,
            'reason' => $opts['reason'] ?? null,
            'meta' => $opts['meta'] ?? null,
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 512),
            'created_at' => now(),
        ]);
    }

    /** Soft-block writes: tenant users keep read access but cannot post. */
    public function suspend(PlatformAdmin $admin, Tenant $tenant, ?string $reason = null): void
    {
        if ($tenant->status === 'suspended') {
            return;
        }

        $from = $tenant->status;
        $tenant->status = 'suspended';
        $tenant->save();
        $this->log($admin, 'suspend', $tenant, ['reason' => $reason, 'meta' => ['from' => $from]]);
    }

    /** Restore write access (from suspended or expired_trial). */
    public function reactivate(PlatformAdmin $admin, Tenant $tenant, ?string $reason = null): void
    {
        if ($tenant->status === 'active') {
            return;
        }

        $from = $tenant->status;
        $tenant->status = 'active';
        $tenant->save();
        $this->log($admin, 'reactivate', $tenant, ['reason' => $reason, 'meta' => ['from' => $from]]);
    }

    /** Add N days to the trial window; re-activates an already-expired trial. */
    public function extendTrial(PlatformAdmin $admin, Tenant $tenant, int $days, ?string $reason = null): void
    {
        // Extend from the current end date if it is still in the future, else from now.
        $base = ($tenant->trial_ends_at && $tenant->trial_ends_at->isFuture())
            ? $tenant->trial_ends_at->copy()
            : now();

        $tenant->trial_ends_at = $base->addDays($days);

        if ($tenant->status === 'expired_trial') {
            $tenant->status = 'active';
        }
        $tenant->save();

        $this->log($admin, 'extend_trial', $tenant, [
            'reason' => $reason,
            'meta' => ['days' => $days, 'trial_ends_at' => $tenant->trial_ends_at->toDateTimeString()],
        ]);
    }

    /** Manually set a tenant's plan (e.g. trial → paid-monthly) before billing (14B). */
    public function changePlan(PlatformAdmin $admin, Tenant $tenant, string $tier, ?string $reason = null): void
    {
        $plan = Plan::where('tier', $tier)->firstOrFail();
        $old = $tenant->plan?->tier;

        $tenant->plan_id = $plan->id;

        // A paid plan has no trial window and cannot be trial-expired.
        if ($tier !== 'trial') {
            $tenant->trial_ends_at = null;
            if ($tenant->status === 'expired_trial') {
                $tenant->status = 'active';
            }
        }
        $tenant->save();

        $this->log($admin, 'plan_change', $tenant, [
            'reason' => $reason,
            'meta' => ['from' => $old, 'to' => $tier],
        ]);
    }
}
