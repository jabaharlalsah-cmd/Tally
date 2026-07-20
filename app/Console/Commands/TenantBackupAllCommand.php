<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Backups\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 14C — nightly backup of every tenant that holds data (scheduled in
 * routes/console.php). Each tenant is backed up and pruned independently — a failure on
 * one tenant is logged/alerted and does NOT stop the others.
 *
 *   php artisan zerobook:tenant-backup-all [--dry-run]
 */
class TenantBackupAllCommand extends Command
{
    protected $signature = 'zerobook:tenant-backup-all {--dry-run : list tenants without backing up}';

    protected $description = 'Back up every active/suspended/expired/archived tenant, then prune old backups';

    /** Statuses that hold a live tenant DB worth backing up (purged has none; restored are copies). */
    private const BACKUP_STATUSES = ['active', 'suspended', 'expired_trial', 'expired_subscription', 'archived', 'purge_scheduled'];

    public function handle(BackupService $backups): int
    {
        $ok = 0;
        $failed = 0;
        $pruned = 0;

        Tenant::whereIn('status', self::BACKUP_STATUSES)->orderBy('id')
            ->chunkById(100, function ($tenants) use ($backups, &$ok, &$failed, &$pruned) {
                foreach ($tenants as $tenant) {
                    if ($this->option('dry-run')) {
                        $this->line("  would back up: {$tenant->id} ({$tenant->status})");
                        $ok++;

                        continue;
                    }
                    try {
                        $backup = $backups->backupTenant($tenant, 'scheduled');
                        $pruned += $backups->pruneBackups($tenant);
                        $this->line("  ✓ {$tenant->id} → backup #{$backup->id} ({$backup->humanSize()})");
                        $ok++;
                    } catch (Throwable $e) {
                        // Isolated: a failure on this tenant does not stop the rest.
                        report($e);
                        $this->error("  ✗ {$tenant->id}: ".$e->getMessage());
                        $failed++;
                    }
                }
            });

        $this->info(($this->option('dry-run') ? '[dry-run] ' : '')."Backed up {$ok}, failed {$failed}, pruned {$pruned} old backup(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
