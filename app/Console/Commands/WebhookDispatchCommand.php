<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Api\Webhooks\WebhookDispatcher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 16C — deliver due webhook events. Scheduled every minute.
 *
 * This is the delivery runtime. There is no queue worker in this deployment (QUEUE_CONNECTION=sync
 * with nothing to drain a database queue), so the retry cadence — 5s, 30s, 5m, 30m, 3h — is driven
 * by a table of due rows and a per-minute tick. That is a deliberate fit to the existing cron-only
 * infrastructure: nothing here needs sub-second dispatch.
 *
 * Runs per tenant, because webhook tables are per-tenant. A failure on one tenant is isolated and
 * never stops the sweep (mirroring tenant-backup-all / api-idempotency-prune).
 *
 * Only ACTIVE tenants deliver. A suspended, expired, archived or purge-scheduled account should not
 * be pushing events to a customer's systems — its books are frozen, so anything it emitted would
 * describe a state the customer cannot act on.
 */
class WebhookDispatchCommand extends Command
{
    protected $signature = 'zerobook:webhook-dispatch {--tenant= : dispatch a single tenant by slug}';

    protected $description = 'Deliver due outbound webhook events (retries with exponential backoff)';

    /** Only a live account emits to a customer's systems. */
    private const DISPATCH_STATUSES = ['active'];

    public function handle(WebhookDispatcher $dispatcher): int
    {
        $attempted = $succeeded = $failed = 0;
        $tenantsFailed = 0;

        $query = Tenant::query()->orderBy('id');
        if ($slug = $this->option('tenant')) {
            $query->whereKey($slug);
        } else {
            $query->whereIn('status', self::DISPATCH_STATUSES);
        }

        $query->chunkById(100, function ($tenants) use ($dispatcher, &$attempted, &$succeeded, &$failed, &$tenantsFailed) {
            foreach ($tenants as $tenant) {
                try {
                    // stancl's Tenant::run() has no try/finally — a throw inside it skips the
                    // context revert and leaks tenancy into the next iteration. Outbound HTTP is
                    // far more throw-prone than a DELETE, so the body guards itself.
                    [$a, $s, $f] = $tenant->run(function () use ($dispatcher) {
                        try {
                            return $dispatcher->dispatchDue();
                        } catch (Throwable $e) {
                            report($e);

                            return [0, 0, 0];
                        }
                    });

                    $attempted += $a;
                    $succeeded += $s;
                    $failed += $f;

                    if ($a > 0) {
                        $this->line("  ✓ {$tenant->id}: {$s} delivered, {$f} failed");
                    }
                } catch (Throwable $e) {
                    // Isolated: a failure on this tenant does not stop the rest.
                    report($e);
                    $this->error("  ✗ {$tenant->id}: ".$e->getMessage());
                    $tenantsFailed++;
                    tenancy()->end();   // the run() above may have left tenancy initialized
                }
            }
        });

        if ($attempted > 0 || $tenantsFailed > 0) {
            $this->info("Attempted {$attempted}: {$succeeded} delivered, {$failed} failed; {$tenantsFailed} tenant(s) errored.");
        }

        return $tenantsFailed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
