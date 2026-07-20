<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\SubscriptionExpired;
use App\Notifications\SubscriptionExpiring;
use Illuminate\Console\Command;

/**
 * Phase 14B — daily subscription reminder emails (scheduled in routes/console.php).
 *
 *   php artisan zerobook:subscription-reminders [--dry-run]
 *
 *   • "expiring soon" — to a tenant whose paid subscription lapses in exactly
 *     config('zerobook.reminder_days') days (default 7); fires once at that mark.
 *   • "expired"       — to a tenant whose grace window ended today (status just became
 *     expired_subscription); fires once.
 *
 * Emails go to the tenant's owner. Run AFTER zerobook:trial-check so an expired tenant's
 * status is already flipped when this looks for the expired case.
 */
class SubscriptionRemindersCommand extends Command
{
    protected $signature = 'zerobook:subscription-reminders {--dry-run : report without sending}';

    protected $description = 'Email tenants whose subscription is expiring soon or has just expired';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $reminderDays = (int) config('zerobook.reminder_days', 7);

        $expiring = 0;
        $expired = 0;

        // ── Expiring soon ────────────────────────────────────────────────────────
        Tenant::where('status', 'active')->whereNotNull('plan_ends_at')
            ->orderBy('id')
            ->chunkById(200, function ($tenants) use ($dry, $reminderDays, &$expiring) {
                foreach ($tenants as $tenant) {
                    if ($tenant->subscriptionDaysLeft() !== $reminderDays) {
                        continue;
                    }
                    $this->line("  expiring in {$reminderDays}d: {$tenant->id}");
                    if (! $dry && ($owner = $this->owner($tenant->id))) {
                        $owner->notify(new SubscriptionExpiring($tenant, $reminderDays));
                    }
                    $expiring++;
                }
            });

        // ── Expired today (grace ended) ──────────────────────────────────────────
        Tenant::where('status', 'expired_subscription')->whereNotNull('plan_ends_at')
            ->orderBy('id')
            ->chunkById(200, function ($tenants) use ($dry, &$expired) {
                foreach ($tenants as $tenant) {
                    if (! $tenant->accessEndsAt()?->isToday()) {
                        continue;
                    }
                    $this->line("  expired today: {$tenant->id}");
                    if (! $dry && ($owner = $this->owner($tenant->id))) {
                        $owner->notify(new SubscriptionExpired($tenant));
                    }
                    $expired++;
                }
            });

        $this->info(($dry ? '[dry-run] ' : '')."Reminders: {$expiring} expiring, {$expired} expired.");

        return self::SUCCESS;
    }

    private function owner(string $tenantId): ?TenantUser
    {
        return TenantUser::where('tenant_id', $tenantId)
            ->orderByRaw("role = 'owner' desc")->orderBy('id')->first();
    }
}
