<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * Phase 14A — the single authority on whether the CURRENT tenant request may WRITE.
 *
 * A tenant's books stay readable in every state, but writes (posting/altering/cancelling
 * vouchers, and the desktop sync push) are blocked when:
 *
 *   • the tenant is 'suspended' or 'expired_trial' (subscription not active), OR
 *   • the request is a platform-admin impersonation session that has NOT enabled the
 *     explicit write toggle (impersonation is view-only by default).
 *
 * Consulted from two places so the rule lives once:
 *   - VoucherScreen::post()/cancelVoucher() (the interactive Livewire write chokepoint)
 *     and the sync push controller call assertWritable();
 *   - the RequireActiveTenant middleware calls writable()/blockMessage() for plain HTTP
 *     non-GET requests.
 *
 * The tenant status is read FRESH (a PK lookup on the central `tenants` row) so a
 * suspend that just happened in the admin surface takes effect on the very next write.
 */
class TenantGate
{
    // Session keys stamped onto a tenant session while a platform admin is impersonating.
    public const SESSION_ADMIN_ID = 'zb_impersonator_admin_id';

    public const SESSION_ADMIN_EMAIL = 'zb_impersonator_admin_email';

    public const SESSION_TENANT = 'zb_impersonator_tenant';

    public const SESSION_TARGET_USER = 'zb_impersonator_target_user_id';

    public const SESSION_WRITE = 'zb_impersonator_write_enabled';

    public const SESSION_ACTION_ID = 'zb_impersonator_action_id';

    public const SESSION_REASON = 'zb_impersonator_reason';

    /** The current tenant's central row, read fresh (null outside a tenant context). */
    public static function tenant(): ?Tenant
    {
        if (! function_exists('tenant') || ! tenant()) {
            return null;
        }

        return Tenant::find(tenant('id'));
    }

    public static function status(): ?string
    {
        return self::tenant()?->status;
    }

    /** Status-only writability (ignores impersonation). Central context → always true. */
    public static function statusWritable(): bool
    {
        $tenant = self::tenant();

        return $tenant ? $tenant->isWritable() : true;
    }

    // ── impersonation ─────────────────────────────────────────────────────────

    private static function sessionActive(): bool
    {
        return app()->bound('session') && app('session')->isStarted();
    }

    public static function isImpersonating(): bool
    {
        return self::sessionActive() && (bool) session(self::SESSION_ADMIN_ID);
    }

    public static function impersonationWriteEnabled(): bool
    {
        return self::sessionActive() && (bool) session(self::SESSION_WRITE, false);
    }

    /** The banner payload (null when not impersonating). */
    public static function impersonationContext(): ?array
    {
        if (! self::isImpersonating()) {
            return null;
        }

        return [
            'admin_email' => session(self::SESSION_ADMIN_EMAIL),
            'tenant' => session(self::SESSION_TENANT),
            'target_user_id' => session(self::SESSION_TARGET_USER),
            'write_enabled' => self::impersonationWriteEnabled(),
            'reason' => session(self::SESSION_REASON),
        ];
    }

    // ── the gate ─────────────────────────────────────────────────────────────

    /** The final verdict: subscription active AND (not impersonating || write enabled). */
    public static function writable(): bool
    {
        if (! self::statusWritable()) {
            return false;
        }

        if (self::isImpersonating() && ! self::impersonationWriteEnabled()) {
            return false;
        }

        return true;
    }

    /** The clear, user-facing reason a write is blocked. */
    public static function blockMessage(): string
    {
        // Impersonation view-only takes precedence when the subscription itself is fine.
        if (self::statusWritable() && self::isImpersonating() && ! self::impersonationWriteEnabled()) {
            return 'View-only impersonation — writes are disabled. Turn on “Enable writes” in the impersonation banner to make changes.';
        }

        return self::tenant()?->writeBlockMessage()
            ?? 'Your subscription is not active. Contact support.';
    }

    /**
     * Throw a ValidationException (surfaced by Livewire as the 'tenant' error, and by
     * plain requests as a 422) unless the current request may write.
     */
    public static function assertWritable(): void
    {
        if (! self::writable()) {
            throw ValidationException::withMessages(['tenant' => self::blockMessage()]);
        }
    }
}
