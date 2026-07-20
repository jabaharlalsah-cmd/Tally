<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantBackup;
use App\Services\Backups\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 14C — restore a backup into a NEW tenant database (never overwrites the original).
 *
 *   php artisan zerobook:tenant-restore {tenant_id} {backup_id} [--force]
 *
 * Platform-admin operation. Creates a fresh tenant DB, imports the backup, and verifies
 * structure + voucher counts + double-entry balance; a failed verification drops the fresh
 * DB and aborts. The admin then decides whether to swap the original's DB reference or keep
 * the restore as a parallel review copy.
 */
class TenantRestoreCommand extends Command
{
    protected $signature = 'zerobook:tenant-restore {tenant : the original tenant id} {backup : the backup id} {--force : skip the confirmation prompt}';

    protected $description = 'Restore a tenant backup into a fresh database (verified), as a review copy';

    public function handle(BackupService $backups): int
    {
        $tenant = Tenant::find($this->argument('tenant'));
        if (! $tenant) {
            $this->error("Unknown tenant [{$this->argument('tenant')}].");

            return self::FAILURE;
        }

        $backup = TenantBackup::where('tenant_id', $tenant->id)->find($this->argument('backup'));
        if (! $backup) {
            $this->error("Backup #{$this->argument('backup')} not found for tenant [{$tenant->id}].");

            return self::FAILURE;
        }

        $this->line("Backup #{$backup->id} · {$backup->humanSize()} · taken {$backup->taken_at?->format('d-M-Y H:i')} · {$backup->file_path}");
        if (! $this->option('force') && ! $this->confirm('Restore this backup into a NEW database (the original is untouched)?', true)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        try {
            $restored = $backups->restoreTenant($tenant, $backup);
        } catch (Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("✓ Restored into [{$restored->id}] (database {$restored->database()->getName()}), status 'restored'.");
        $this->line('  Review it, then decide whether to swap the original tenant\'s DB reference or discard the copy.');

        return self::SUCCESS;
    }
}
