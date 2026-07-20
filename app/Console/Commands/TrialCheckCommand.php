<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Phase 14A/14B — daily expiry sweep (scheduled in routes/console.php).
 *
 *   php artisan zerobook:trial-check [--dry-run]
 *
 * Flips an ACTIVE tenant to a read-only status when neither its trial nor its paid
 * subscription (grace included) is still valid:
 *   • no paid subscription, trial lapsed        → expired_trial        (14A)
 *   • paid subscription lapsed past grace        → expired_subscription (14B)
 *
 * A tenant with an active trial OR an in-grace subscription stays active. A tenant with
 * NEITHER a trial_ends_at NOR a plan_ends_at is a legacy/unlimited account (e.g. hand-
 * provisioned before 14A) and is never expired. Reversible: recording a payment
 * reactivates the tenant. Idempotent.
 */
class TrialCheckCommand extends Command
{
    protected $signature = 'zerobook:trial-check {--dry-run : report what would expire without changing anything}';

    protected $description = 'Expire lapsed trials and subscriptions — flip active tenants past their grace to expired_trial / expired_subscription';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $graceDays = (int) config('zerobook.grace_days', 7);

        $expired = 0;

        Tenant::where('status', 'active')
            ->where(function ($q) {
                $q->whereNotNull('trial_ends_at')->orWhereNotNull('plan_ends_at');
            })
            ->orderBy('id')
            ->chunkById(200, function ($tenants) use ($dry, $graceDays, &$expired) {
                foreach ($tenants as $tenant) {
                    $trialActive = $tenant->trial_ends_at && $tenant->trial_ends_at->isFuture();
                    $subActive = $tenant->plan_ends_at && $tenant->plan_ends_at->copy()->addDays($graceDays)->isFuture();

                    if ($trialActive || $subActive) {
                        continue; // still inside a valid window
                    }

                    $newStatus = $tenant->plan_ends_at !== null ? 'expired_subscription' : 'expired_trial';

                    if ($dry) {
                        $this->line("  would expire: {$tenant->id} → {$newStatus}");
                    } else {
                        $tenant->status = $newStatus;
                        $tenant->save();
                        $this->line("  expired: {$tenant->id} → {$newStatus}");
                    }
                    $expired++;
                }
            });

        if ($expired === 0) {
            $this->info('Nothing to expire.');
        } else {
            $this->info(($dry ? 'Would expire ' : 'Expired ').$expired.' tenant(s).');
        }

        return self::SUCCESS;
    }
}
