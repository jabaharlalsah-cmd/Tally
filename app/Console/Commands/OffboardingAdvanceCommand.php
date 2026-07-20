<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Offboarding\OffboardingService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 14C — daily offboarding state-machine step (scheduled in routes/console.php).
 *
 *   php artisan zerobook:offboarding-advance [--dry-run]
 *
 * Walks tenants that are mid-offboarding (suspended-with-offboarding), archived, or
 * purge_scheduled and advances each by at most one step when its configured window has
 * passed, also sending the approaching-deadline reminders. Each tenant is isolated — a
 * failure on one does not stop the rest.
 */
class OffboardingAdvanceCommand extends Command
{
    protected $signature = 'zerobook:offboarding-advance {--dry-run : report transitions without applying them}';

    protected $description = 'Advance the offboarding lifecycle (archive / purge_scheduled / purge) and send reminders';

    public function handle(OffboardingService $offboarding): int
    {
        $moved = 0;

        Tenant::query()
            ->where(function ($q) {
                $q->whereNotNull('offboarding_initiated_at')
                    ->orWhereIn('status', ['archived', 'purge_scheduled']);
            })
            ->whereIn('status', ['suspended', 'archived', 'purge_scheduled'])
            ->orderBy('id')
            ->chunkById(100, function ($tenants) use ($offboarding, &$moved) {
                foreach ($tenants as $tenant) {
                    $before = $tenant->status;
                    if ($this->option('dry-run')) {
                        $this->line("  {$tenant->id}: {$before} (offboarding since {$tenant->offboarding_initiated_at?->format('d-M-Y')})");

                        continue;
                    }
                    try {
                        $offboarding->advance($tenant);
                        $after = $tenant->fresh()->status;
                        if ($after !== $before) {
                            $this->line("  {$tenant->id}: {$before} → {$after}");
                            $moved++;
                        }
                    } catch (Throwable $e) {
                        report($e);
                        $this->error("  ✗ {$tenant->id}: ".$e->getMessage());
                    }
                }
            });

        $this->info(($this->option('dry-run') ? '[dry-run] ' : '')."Advanced {$moved} tenant(s).");

        return self::SUCCESS;
    }
}
