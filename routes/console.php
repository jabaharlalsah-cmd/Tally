<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Phase 14A — scheduled jobs
|--------------------------------------------------------------------------
| Trial expiry runs once a day: any active trial tenant past its end date is
| flipped to 'expired_trial' (read-only until extended or converted). Requires the
| Laravel scheduler to be driven by cron / Windows Task Scheduler in production:
|   * * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
*/
Schedule::command('zerobook:trial-check')
    ->dailyAt('02:15')
    ->withoutOverlapping()
    ->description('Expire lapsed ZeroBook trials & subscriptions');

// Phase 14B — renewal reminders run AFTER the expiry sweep so "expired today" is accurate.
Schedule::command('zerobook:subscription-reminders')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->description('Email tenants whose subscription is expiring soon or just expired');

// Phase 14C — nightly per-tenant backups (+ retention prune) and the offboarding sweep.
Schedule::command('zerobook:tenant-backup-all')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->description('Back up every tenant DB and prune old backups');

Schedule::command('zerobook:offboarding-advance')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->description('Advance the offboarding state machine (archive / purge_scheduled / purge)');

// Phase 16B — sweep expired API idempotency rows (48h TTL) in every tenant.
Schedule::command('zerobook:api-idempotency-prune')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->description('Prune expired API idempotency rows in every tenant');

// Phase 16C — outbound webhook delivery. The FIRST sub-daily schedule in this app.
//
// This is the webhook retry runtime: there is no queue worker (QUEUE_CONNECTION=sync, nothing
// drains a database queue), so due deliveries are picked up on a per-minute tick. The backoff
// ladder is 5s/30s/5m/30m/3h, so a minute of granularity costs at most ~55s of extra latency on
// the first retry and nothing measurable thereafter.
//
// withoutOverlapping() keeps two ticks from running concurrently, but it is NOT what makes
// delivery single-flight — a row is claimed with a conditional UPDATE (see WebhookDispatcher), so
// even overlapping runs cannot double-POST.
// The 5 is the lock's EXPIRY IN MINUTES, and it matters: withoutOverlapping() defaults to 1440,
// so an ungracefully killed tick (deploy, OOM, power loss) would leave a lock nothing releases and
// silently stop ALL webhook delivery for 24 hours. A minute-ly command needs a minute-ish lock.
// Letting it expire early is safe — overlapping ticks cannot double-POST, because the conditional
// claim UPDATE is what actually makes delivery single-flight.
Schedule::command('zerobook:webhook-dispatch')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->description('Deliver due outbound webhook events (exponential backoff)');

// Phase 16C — keep the delivery log bounded (finished rows only; live work is never pruned).
Schedule::command('zerobook:webhook-prune')
    ->dailyAt('03:45')
    ->withoutOverlapping(60)   // never inherit the 1440 default — see webhook-dispatch above
    ->description('Prune finished webhook deliveries past the retention window');
