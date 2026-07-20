<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\TenantExport;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 14C — serve a customer's export archive from PRIVATE storage.
 *
 * The route is behind `signed:relative`, so the emailed link authorises the download
 * (no login needed — the customer may be mid-offboarding). The signature's expiry equals
 * the export's download_expires_at. Belt-and-suspenders: the export must belong to THIS
 * tenant subdomain and still be downloadable.
 */
class DataExportController extends Controller
{
    public function download(TenantExport $export)
    {
        abort_unless($export->tenant_id === tenant('id'), 403);
        abort_unless($export->isDownloadable() && $export->file_path && Storage::disk('local')->exists($export->file_path), 404);

        if (! $export->downloaded_at) {
            $export->forceFill(['downloaded_at' => now()])->save();
        }

        return Storage::disk('local')->download($export->file_path, 'zerobook-export-'.$export->tenant_id.'.zip');
    }
}
