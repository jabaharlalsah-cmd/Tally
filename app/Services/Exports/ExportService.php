<?php

namespace App\Services\Exports;

use App\Jobs\ExportTenantJob;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\TenantExport;
use App\Models\TenantUser;
use App\Support\TenantUrl;
use Illuminate\Support\Facades\URL;

/**
 * Phase 14C — customer data export. Creates an export record and dispatches the (async)
 * job that walks the tenant DB, writes the CSVs + reports + mysqldump, zips them, and
 * emails a signed download link. With the sync queue driver the job runs inline; switching
 * QUEUE_CONNECTION to database/redis + a worker makes it truly background.
 */
class ExportService
{
    public function exportTenant(Tenant $tenant, ?TenantUser $initiatedBy = null, ?PlatformAdmin $initiatedByAdmin = null): TenantExport
    {
        $export = TenantExport::create([
            'tenant_id' => $tenant->id,
            'initiated_by_user_id' => $initiatedBy?->id,
            'initiated_by_admin_id' => $initiatedByAdmin?->id,
            'status' => 'queued',
        ]);

        ExportTenantJob::dispatch($export->id);

        return $export->refresh();
    }

    /** A signed, host-independent download URL on the tenant subdomain, valid until expiry. */
    public function signedDownloadUrl(TenantExport $export): string
    {
        $expires = $export->download_expires_at ?? now()->addDays((int) config('zerobook.export_link_days', 7));

        $relative = URL::temporarySignedRoute(
            'export.download',
            $expires,
            ['export' => $export->id],
            absolute: false,
        );

        return TenantUrl::tenantConfig($export->tenant_id, $relative);
    }

    /** Rate limit: at most one export per config('zerobook.export_min_hours') (default 24h). */
    public function canExport(Tenant $tenant): bool
    {
        $recent = TenantExport::where('tenant_id', $tenant->id)
            ->whereIn('status', ['queued', 'processing', 'completed'])
            ->latest('id')->first();

        if (! $recent) {
            return true;
        }

        return $recent->created_at
            ->addHours((int) config('zerobook.export_min_hours', 24))
            ->isPast();
    }
}
