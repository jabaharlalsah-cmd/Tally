<?php

namespace App\Console\Commands;

use App\Models\ApiIdempotencyKey;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 16B — prune expired API idempotency rows, per tenant.
 *
 * Idempotency rows keep a 48h window so a client retry stays meaningful; past that they are dead
 * weight. This sweep runs daily. It MUST run inside each tenant context — api_idempotency_keys is
 * a per-tenant table, so a single central pass would touch nothing. A failure on one tenant is
 * isolated and never stops the rest (mirroring tenant-backup-all).
 */
class ApiIdempotencyPruneCommand extends Command
{
    protected $signature = 'zerobook:api-idempotency-prune {--tenant= : prune a single tenant by slug}';

    protected $description = 'Delete expired API idempotency rows in every tenant (48h TTL)';

    public function handle(): int
    {
        $total = 0;
        $failed = 0;

        $query = Tenant::query()->orderBy('id');
        if ($slug = $this->option('tenant')) {
            $query->whereKey($slug);
        }

        $query->chunkById(100, function ($tenants) use (&$total, &$failed) {
            foreach ($tenants as $tenant) {
                try {
                    $deleted = $tenant->run(function () {
                        if (! \Illuminate\Support\Facades\Schema::hasTable('api_idempotency_keys')) {
                            return 0;   // tenant not yet migrated to 16B
                        }

                        return ApiIdempotencyKey::where('expires_at', '<', now())->delete();
                    });

                    if ($deleted > 0) {
                        $this->line("  ✓ {$tenant->id}: pruned {$deleted}");
                    }
                    $total += $deleted;
                } catch (Throwable $e) {
                    report($e);
                    $this->error("  ✗ {$tenant->id}: ".$e->getMessage());
                    $failed++;
                }
            }
        });

        $this->info("Pruned {$total} expired idempotency row(s); {$failed} tenant(s) failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
