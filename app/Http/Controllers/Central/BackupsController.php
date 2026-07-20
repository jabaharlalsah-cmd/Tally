<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantBackup;
use App\Services\Backups\BackupService;
use App\Services\Platform\PlatformActions;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Phase 14C — platform-admin backup actions from the tenant detail screen.
 */
class BackupsController extends Controller
{
    public function takeNow(Tenant $tenant, BackupService $backups, PlatformActions $actions)
    {
        try {
            $backup = $backups->backupTenant($tenant, 'on_demand');
        } catch (Throwable $e) {
            return back()->withErrors(['backup' => 'Backup failed: '.$e->getMessage()]);
        }

        $actions->log(Auth::guard('platform')->user(), 'backup_taken', $tenant, [
            'meta' => ['backup_id' => $backup->id, 'size' => $backup->humanSize(), 'on_demand' => true],
        ]);

        return back()->with('flash', "On-demand backup #{$backup->id} taken & verified ({$backup->humanSize()}).");
    }

    public function restore(Tenant $tenant, TenantBackup $backup, BackupService $backups, PlatformActions $actions)
    {
        if ($backup->tenant_id !== $tenant->id) {
            abort(404);
        }

        try {
            $restored = $backups->restoreTenant($tenant, $backup);
        } catch (Throwable $e) {
            return back()->withErrors(['backup' => 'Restore failed: '.$e->getMessage()]);
        }

        $actions->log(Auth::guard('platform')->user(), 'restore', $tenant, [
            'meta' => ['from_backup' => $backup->id, 'restored_into' => $restored->id],
        ]);

        return back()->with('flash', "Restored backup #{$backup->id} into review copy [{$restored->id}] (database {$restored->database()->getName()}). Verify it, then decide whether to swap or discard.");
    }
}
