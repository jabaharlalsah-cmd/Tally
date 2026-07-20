<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Offboarding\OffboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Phase 14C — platform-admin offboarding actions: initiate closure, reactivate a
 * suspended/archived account, and the irreversible purge (typed-confirmation guarded).
 */
class OffboardingController extends Controller
{
    public function initiate(Request $request, Tenant $tenant, OffboardingService $offboarding)
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;

        try {
            $offboarding->initiate($tenant, Auth::guard('platform')->user(), $reason);
        } catch (Throwable $e) {
            return back()->withErrors(['offboarding' => $e->getMessage()]);
        }

        return back()->with('flash', "Offboarding initiated for “{$tenant->id}” — pre-offboarding backup + export created, customer emailed.");
    }

    public function reactivate(Tenant $tenant, OffboardingService $offboarding)
    {
        try {
            $offboarding->reactivate($tenant, Auth::guard('platform')->user());
        } catch (Throwable $e) {
            return back()->withErrors(['offboarding' => $e->getMessage()]);
        }

        return back()->with('flash', "“{$tenant->id}” reactivated — status active, data restored to its pre-offboarding state.");
    }

    public function purge(Request $request, Tenant $tenant, OffboardingService $offboarding)
    {
        $confirm = $request->validate(['confirm' => ['required', 'string']])['confirm'];
        if (strtolower(trim($confirm)) !== strtolower($tenant->id)) {
            return back()->withErrors(['purge' => "Type the subdomain (“{$tenant->id}”) to confirm the permanent purge."]);
        }

        try {
            $offboarding->purge($tenant, Auth::guard('platform')->user());
        } catch (Throwable $e) {
            return back()->withErrors(['purge' => $e->getMessage()]);
        }

        return back()->with('flash', "“{$tenant->id}” PURGED — tenant database dropped, backups & exports deleted. Payment history retained.");
    }
}
