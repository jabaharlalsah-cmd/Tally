<?php

namespace App\Services\Offboarding;

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\TenantLifecycleEvent;
use App\Models\TenantUser;
use App\Notifications\ArchiveImminent;
use App\Notifications\ArchiveReminder;
use App\Notifications\OffboardingInitiated;
use App\Notifications\PurgeImminent;
use App\Notifications\PurgeScheduled;
use App\Notifications\TenantPurged;
use App\Services\Backups\BackupService;
use App\Services\Exports\ExportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Stancl\Tenancy\Jobs\DeleteDatabase;

/**
 * Phase 14C — the offboarding state machine.
 *
 *   active/… ──initiate──► suspended(+offboarding) ──advance──► archived ──advance──►
 *   purge_scheduled ──advance──► purged (point of no return).
 *
 * Reversible via reactivate() until purge. Purge drops the tenant DB, deletes backups and
 * exports, and marks the CENTRAL row `purged` — the row (and its payment history) is
 * retained for legal reasons, but the data is gone for good.
 */
class OffboardingService
{
    public function __construct(
        private BackupService $backups,
        private ExportService $exports,
    ) {
    }

    /** Start account closure: read-only, pre-offboarding backup + export, confirmation email. */
    public function initiate(Tenant $tenant, TenantUser|PlatformAdmin $initiator, ?string $reason = null): void
    {
        if ($tenant->isClosed() || $tenant->isOffboarding()) {
            throw new RuntimeException('This account is already being offboarded or is closed.');
        }
        if (! $this->canInitiate($tenant)) {
            $days = (int) config('zerobook.offboard_min_days', 7);
            throw new RuntimeException("Account closure was requested recently. Please wait {$days} days or contact support.");
        }

        $init = now();
        $tenant->forceFill([
            'status' => 'suspended',
            'offboarding_initiated_at' => $init,
            'archive_scheduled_for' => $init->copy()->addDays($this->days('archive_days')),
            'purge_scheduled_for' => $init->copy()
                ->addDays($this->days('archive_days') + $this->days('purge_schedule_days') + $this->days('purge_days')),
        ])->save();

        // Safety net: a fresh backup + a downloadable export at the moment of closure.
        $this->backups->backupTenant($tenant, 'pre_offboarding');
        $this->exports->exportTenant(
            $tenant,
            $initiator instanceof TenantUser ? $initiator : null,
            $initiator instanceof PlatformAdmin ? $initiator : null,
        );

        $this->event($tenant, 'offboarding_initiated', $initiator, $reason);
        $this->notifyOwner($tenant, new OffboardingInitiated($tenant->fresh()));
    }

    /** Bring an offboarding/archived account back to life. Impossible once purged. */
    public function reactivate(Tenant $tenant, PlatformAdmin $admin): void
    {
        if ($tenant->isPurged()) {
            throw new RuntimeException('Purged tenants cannot be reactivated. Data has been permanently deleted.');
        }

        if (in_array($tenant->status, ['archived', 'purge_scheduled'], true)) {
            // The live DB was read-disabled; restore the pre-offboarding snapshot into it.
            $backup = $this->preOffboardingBackup($tenant);
            if (! $backup) {
                throw new RuntimeException('No pre-offboarding backup found to reactivate from.');
            }
            $this->backups->restoreInPlace($tenant, $backup);
        }

        $tenant->forceFill([
            'status' => 'active',
            'offboarding_initiated_at' => null,
            'archive_scheduled_for' => null,
            'purge_scheduled_for' => null,
        ])->save();

        $this->event($tenant, 'reactivated', $admin);
    }

    /** One state-machine step, based on offboarding_initiated_at + the configured windows. */
    public function advance(Tenant $tenant): void
    {
        $init = $tenant->offboarding_initiated_at;
        if (! $init && $tenant->status !== 'archived' && $tenant->status !== 'purge_scheduled') {
            return;
        }

        $archiveAt = $init?->copy()->addDays($this->days('archive_days'));
        $purgeScheduleAt = $init?->copy()->addDays($this->days('archive_days') + $this->days('purge_schedule_days'));
        $purgeAt = $init?->copy()->addDays($this->days('archive_days') + $this->days('purge_schedule_days') + $this->days('purge_days'));

        if ($tenant->isOffboarding() && $archiveAt && $archiveAt->isPast()) {
            $tenant->forceFill(['status' => 'archived'])->save();
            $this->event($tenant, 'archived', null, 'auto-advanced');

            return;
        }

        if ($tenant->status === 'archived' && $purgeScheduleAt && $purgeScheduleAt->isPast()) {
            $tenant->forceFill(['status' => 'purge_scheduled'])->save();
            $this->event($tenant, 'purge_scheduled', null, 'auto-advanced');
            $this->notifyOwner($tenant, new PurgeScheduled($tenant->fresh()));

            return;
        }

        if ($tenant->status === 'purge_scheduled' && $purgeAt && $purgeAt->isPast()) {
            $this->purge($tenant, null);

            return;
        }

        // No transition due — send an approaching-deadline reminder if one lands today.
        $this->maybeRemind($tenant, $archiveAt, $purgeAt);
    }

    /** The point of no return: drop the DB, delete backups + exports, keep the central row. */
    public function purge(Tenant $tenant, ?PlatformAdmin $admin): void
    {
        if ($tenant->isPurged()) {
            return;
        }

        // Drop the tenant database (keep the central tenants row + payment history).
        if ($this->databaseExists($tenant->database()->getName())) {
            dispatch_sync(new DeleteDatabase($tenant));
        }

        // Delete every backup + export file and row.
        foreach ($tenant->backups()->get() as $backup) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($backup->file_path);
            $backup->delete();
        }
        foreach ($tenant->exports()->get() as $export) {
            if ($export->file_path) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($export->file_path);
            }
            $export->delete();
        }

        $tenant->domains()->delete(); // the subdomain no longer resolves to a tenant

        $tenant->forceFill([
            'status' => 'purged',
            'offboarding_initiated_at' => null,
            'archive_scheduled_for' => null,
            'purge_scheduled_for' => null,
        ])->save();

        $this->event($tenant, 'purged', $admin);
        $this->notifyOwner($tenant, new TenantPurged($tenant->fresh()));
    }

    public function canInitiate(Tenant $tenant): bool
    {
        $recent = TenantLifecycleEvent::where('tenant_id', $tenant->id)
            ->where('event', 'offboarding_initiated')
            ->latest('id')->first();

        return ! $recent
            || $recent->created_at->addDays((int) config('zerobook.offboard_min_days', 7))->isPast();
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function maybeRemind(Tenant $tenant, ?Carbon $archiveAt, ?Carbon $purgeAt): void
    {
        if ($tenant->isOffboarding() && $archiveAt) {
            $daysToArchive = (int) ceil(now()->startOfDay()->floatDiffInDays($archiveAt->copy()->startOfDay(), false));
            if ($daysToArchive === 7) {
                $this->notifyOwner($tenant, new ArchiveReminder($tenant, 7));
            } elseif ($daysToArchive === 1) {
                $this->notifyOwner($tenant, new ArchiveImminent($tenant));
            }
        }

        if ($tenant->status === 'purge_scheduled' && $purgeAt) {
            $daysToPurge = (int) ceil(now()->startOfDay()->floatDiffInDays($purgeAt->copy()->startOfDay(), false));
            if ($daysToPurge === 7) {
                $this->notifyOwner($tenant, new PurgeImminent($tenant, 7));
            }
        }
    }

    private function preOffboardingBackup(Tenant $tenant): ?\App\Models\TenantBackup
    {
        return $tenant->backups()->where('type', 'pre_offboarding')->latest('id')->first();
    }

    private function days(string $key): int
    {
        return (int) config("zerobook.offboarding.{$key}", match ($key) {
            'archive_days' => 30, 'purge_schedule_days' => 90, 'purge_days' => 30, default => 30,
        });
    }

    private function databaseExists(string $db): bool
    {
        $central = config('tenancy.database.central_connection');

        return \Illuminate\Support\Facades\DB::connection($central)->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$db]
        ) !== null;
    }

    private function event(Tenant $tenant, string $event, TenantUser|PlatformAdmin|null $by, ?string $notes = null): void
    {
        TenantLifecycleEvent::create([
            'tenant_id' => $tenant->id,
            'event' => $event,
            'triggered_by_user_id' => $by instanceof TenantUser ? $by->id : null,
            'triggered_by_admin_id' => $by instanceof PlatformAdmin ? $by->id : null,
            'notes' => $notes,
        ]);
    }

    private function notifyOwner(Tenant $tenant, $notification): void
    {
        $owner = TenantUser::where('tenant_id', $tenant->id)
            ->orderByRaw("role = 'owner' desc")->orderBy('id')->first();
        if ($owner) {
            Notification::send($owner, $notification);
        }
    }
}
