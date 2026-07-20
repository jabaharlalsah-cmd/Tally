<?php

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Phase 7B — the tenant registry (CENTRAL database).
 *
 * One tenant = one company = one MySQL database. The primary key is the subdomain
 * slug (e.g. "alpha"), passed explicitly at creation, so the tenant's own database
 * is named "tenant" + id (e.g. "tenantalpha"). Real columns are declared in
 * getCustomColumns(); any other attribute overflows into the JSON `data` column.
 *
 * The base model already pins the CENTRAL connection (CentralConnection concern) and
 * provides run()/getInternal()/setInternal() — so a Tenant lookup is never confused
 * by an active tenant's switched default connection.
 *
 * Phase 14A adds the SaaS-lifecycle columns (trial_ends_at / verified_at /
 * last_active_at) and the status vocabulary self-signup uses:
 *   provisioning → pending_verification → active → (suspended | expired_trial) → active
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    /** Statuses in which the tenant's users may WRITE (post vouchers, edit masters). */
    public const WRITABLE_STATUSES = ['active'];

    /** Statuses that soft-block writes but still allow reading the books. */
    public const READ_ONLY_STATUSES = ['suspended', 'expired_trial', 'expired_subscription'];

    /** Phase 14C — the account is closed: NO tenant access at all (admin may still restore). */
    public const NO_ACCESS_STATUSES = ['archived', 'purge_scheduled', 'purged'];

    public static function getCustomColumns(): array
    {
        return [
            'id', 'name', 'plan_id', 'status', 'provisioned_at',
            // Phase 14A — declared as REAL columns so they do not overflow into `data`.
            'trial_ends_at', 'verified_at', 'last_active_at',
            // Phase 14B — the paid-subscription end date.
            'plan_ends_at',
            // Phase 14C — the offboarding schedule.
            'offboarding_initiated_at', 'archive_scheduled_for', 'purge_scheduled_for',
        ];
    }

    protected $casts = [
        'provisioned_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'verified_at' => 'datetime',
        'last_active_at' => 'datetime',
        'plan_ends_at' => 'datetime',
        'offboarding_initiated_at' => 'datetime',
        'archive_scheduled_for' => 'datetime',
        'purge_scheduled_for' => 'datetime',
    ];

    /** The subscription plan this tenant is on (central DB). */
    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /** The people who can log into this tenant's app (central DB). */
    public function users()
    {
        return $this->hasMany(TenantUser::class, 'tenant_id');
    }

    /** Phase 14B — this tenant's manual payments (central DB). */
    public function payments()
    {
        return $this->hasMany(Payment::class, 'tenant_id');
    }

    /** Phase 14C — backups / exports / lifecycle events (central DB). */
    public function backups()
    {
        return $this->hasMany(TenantBackup::class, 'tenant_id');
    }

    public function exports()
    {
        return $this->hasMany(TenantExport::class, 'tenant_id');
    }

    public function lifecycleEvents()
    {
        return $this->hasMany(TenantLifecycleEvent::class, 'tenant_id');
    }

    // ── Phase 14A — status helpers ────────────────────────────────────────────

    /** Writes allowed? (post/alter/cancel vouchers, edit masters). */
    public function isWritable(): bool
    {
        return in_array($this->status, self::WRITABLE_STATUSES, true);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isTrialExpired(): bool
    {
        return $this->status === 'expired_trial';
    }

    public function isSubscriptionExpired(): bool
    {
        return $this->status === 'expired_subscription';
    }

    /** True once the admin user has verified their signup email. */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** Is this tenant currently in a trial window (a trial plan with an end date)? */
    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null && ($this->plan?->tier === 'trial');
    }

    /** Days left in the trial (0 if lapsed or not on trial). */
    public function trialDaysLeft(): int
    {
        if (! $this->trial_ends_at) {
            return 0;
        }

        return max(0, (int) ceil(now()->floatDiffInDays($this->trial_ends_at, false)));
    }

    // ── Phase 14B — paid subscription ─────────────────────────────────────────

    public function hasPaidSubscription(): bool
    {
        return $this->plan_ends_at !== null;
    }

    /** Whole days until the PAID subscription lapses (negative once past). */
    public function subscriptionDaysLeft(): ?int
    {
        if (! $this->plan_ends_at) {
            return null;
        }

        return (int) ceil(now()->startOfDay()->floatDiffInDays($this->plan_ends_at->copy()->startOfDay(), false));
    }

    private static function graceDays(): int
    {
        return (int) config('zerobook.grace_days', 7);
    }

    /** The last moment paid access is granted, grace included (null if no subscription). */
    public function accessEndsAt(): ?\Carbon\CarbonInterface
    {
        return $this->plan_ends_at?->copy()->addDays(self::graceDays());
    }

    /** Subscription lapsed but still inside the grace window (writes still work). */
    public function inGracePeriod(): bool
    {
        return $this->plan_ends_at !== null
            && $this->plan_ends_at->isPast()
            && $this->accessEndsAt()->isFuture();
    }

    /**
     * A UI hint for the app-wide banner: 'expiring_soon' | 'grace' | 'expired' | null.
     * Based on the live dates, independent of the (daily-computed) status.
     */
    public function subscriptionBanner(): ?string
    {
        if ($this->status === 'expired_subscription') {
            return 'expired';
        }
        if (! $this->plan_ends_at) {
            return null;
        }
        if ($this->inGracePeriod()) {
            return 'grace';
        }
        $daysLeft = $this->subscriptionDaysLeft();
        if ($daysLeft !== null && $daysLeft >= 0 && $daysLeft <= 7) {
            return 'expiring_soon';
        }

        return null;
    }

    // ── Phase 14C — offboarding lifecycle ─────────────────────────────────────

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function isPurged(): bool
    {
        return $this->status === 'purged';
    }

    /** The account is closed — no tenant access at all (admin may still restore). */
    public function isClosed(): bool
    {
        return in_array($this->status, self::NO_ACCESS_STATUSES, true);
    }

    /** Offboarding has been initiated but not yet archived (reversible by reactivation). */
    public function isOffboarding(): bool
    {
        return $this->offboarding_initiated_at !== null && $this->status === 'suspended';
    }

    public function lifecycleLabel(): string
    {
        return match ($this->status) {
            'archived' => 'Archived — will be scheduled for purge on '.($this->purge_scheduled_for?->format('d-M-Y') ?? '—'),
            'purge_scheduled' => 'Purge scheduled — data will be permanently deleted after the final warning',
            'purged' => 'Purged — tenant data permanently deleted (payment history retained)',
            'restored' => 'Restored review copy',
            default => ucfirst($this->status),
        };
    }

    /**
     * The clear message shown when a write is blocked because the tenant is not active.
     */
    public function writeBlockMessage(): string
    {
        return match ($this->status) {
            'suspended' => $this->isOffboarding()
                ? 'Account closure is in progress. Your books are read-only. Contact support to cancel and reactivate.'
                : 'This company is suspended. Your subscription is not active — please contact support. You can still view your books.',
            'expired_trial' => 'Your free trial has ended. Please record a payment to continue. You can still view your books.',
            'expired_subscription' => 'Your subscription has expired. Please renew to continue posting. You can still view your books.',
            'archived', 'purge_scheduled' => 'This account has been closed and archived. Contact support to restore it.',
            'purged' => 'This account has been permanently closed and its data deleted.',
            default => 'Your subscription is not active. Contact support.',
        };
    }
}
