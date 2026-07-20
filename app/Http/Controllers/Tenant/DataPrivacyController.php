<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantExport;
use App\Services\Exports\ExportService;
use App\Services\Offboarding\OffboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Phase 14C — the tenant's Settings → Data & Privacy screen: download all your data
 * (rate-limited), see past exports, and close your account (typed-confirmation guard).
 */
class DataPrivacyController extends Controller
{
    public function index(ExportService $exports)
    {
        $tenant = Tenant::find(tenant('id'));

        $history = TenantExport::where('tenant_id', $tenant->id)->orderByDesc('id')->limit(20)->get();
        $links = [];
        foreach ($history as $export) {
            if ($export->isDownloadable()) {
                $links[$export->id] = $exports->signedDownloadUrl($export);
            }
        }

        return view('tenant.data-privacy', [
            'tenant' => $tenant,
            'exports' => $history,
            'links' => $links,
            'canExport' => $exports->canExport($tenant),
        ]);
    }

    /** POST /account/data/export — request a fresh export (async, emailed when ready). */
    public function export(Request $request, ExportService $exports)
    {
        $tenant = Tenant::find(tenant('id'));

        if (! $exports->canExport($tenant)) {
            $hours = (int) config('zerobook.export_min_hours', 24);

            return back()->withErrors(['export' => "You can request one export per {$hours} hours. Please try again later."]);
        }

        $exports->exportTenant($tenant, Auth::guard('tenant')->user());

        return back()->with('flash', 'Export started — we\'ll email you a secure download link when it\'s ready (usually within a few minutes).');
    }

    /** POST /account/close — initiate account closure (typed-confirmation guard). */
    public function close(Request $request, OffboardingService $offboarding)
    {
        $tenant = Tenant::find(tenant('id'));

        $data = $request->validate([
            'confirm' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if (strtolower(trim($data['confirm'])) !== strtolower($tenant->id)) {
            return back()->withErrors(['confirm' => "Type your subdomain (“{$tenant->id}”) exactly to confirm closure."]);
        }

        try {
            $offboarding->initiate($tenant, Auth::guard('tenant')->user(), $data['reason'] ?? null);
        } catch (Throwable $e) {
            return back()->withErrors(['confirm' => $e->getMessage()]);
        }

        return redirect()->route('subscription')
            ->with('flash', 'Account closure started. We\'ve emailed you a download link for your data and the timeline. You can reactivate any time before archival by contacting support.');
    }
}
