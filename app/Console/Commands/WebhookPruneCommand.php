<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\WebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Phase 16C — prune old finished webhook deliveries. Scheduled daily.
 *
 * The delivery log is a debugging aid, not an archive: once a delivery has succeeded (or is
 * exhausted and will never be retried) its row is only useful for a while. Without this the table
 * grows forever — a busy tenant emitting on every voucher would accumulate millions.
 *
 * Only FINISHED rows are pruned. A pending/failed/delivering row is still live work and is never
 * touched, however old — losing one would silently drop an event the customer is owed.
 */
class WebhookPruneCommand extends Command
{
    protected $signature = 'zerobook:webhook-prune {--tenant= : prune a single tenant by slug} {--days= : override the retention window}';

    protected $description = 'Delete succeeded/exhausted webhook deliveries past the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('webhooks.retention_days', 30));
        $cutoff = now()->subDays($days);
        $total = 0;
        $failed = 0;

        $query = Tenant::query()->orderBy('id');
        if ($slug = $this->option('tenant')) {
            $query->whereKey($slug);
        }

        $query->chunkById(100, function ($tenants) use (&$total, &$failed, $cutoff) {
            foreach ($tenants as $tenant) {
                try {
                    $deleted = $tenant->run(function () use ($cutoff) {
                        if (! Schema::hasTable('webhook_deliveries')) {
                            return 0;   // tenant not yet migrated to 16C
                        }

                        // FINISHED only — never a row still owed to the customer.
                        return WebhookDelivery::whereIn('status', [
                            WebhookDelivery::STATUS_SUCCEEDED,
                            WebhookDelivery::STATUS_EXHAUSTED,
                        ])->where('created_at', '<', $cutoff)->delete();
                    });

                    if ($deleted > 0) {
                        $this->line("  ✓ {$tenant->id}: pruned {$deleted}");
                    }
                    $total += $deleted;
                } catch (Throwable $e) {
                    report($e);
                    $this->error("  ✗ {$tenant->id}: ".$e->getMessage());
                    $failed++;
                    tenancy()->end();
                }
            }
        });

        $this->info("Pruned {$total} finished webhook delivery row(s); {$failed} tenant(s) failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
