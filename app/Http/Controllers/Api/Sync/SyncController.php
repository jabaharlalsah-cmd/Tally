<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Controller;
use App\Services\Sync\SyncService;
use Illuminate\Http\Request;

/**
 * Phase 7C — the desktop sync endpoints (tenant subdomain, tenant-authenticated).
 *
 * Thin HTTP layer over {@see SyncService}: the connection is already the tenant's
 * (subdomain middleware), and all posting goes through VoucherScreen::post(). The
 * desktop's background worker calls these; the web SaaS never does.
 */
class SyncController extends Controller
{
    /** Drain a desktop outbox → post chronologically, server-authoritative. */
    public function push(Request $request, SyncService $sync)
    {
        $this->refuseFallbackCompany($request);

        $data = $request->validate([
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.client_uuid' => ['required', 'uuid'],
            'entries.*.seq' => ['nullable', 'integer'],
            'entries.*.payload' => ['required', 'array'],
        ]);

        return response()->json($sync->push($data['entries']));
    }

    /** Changes the desktop hasn't seen (since=0 → full snapshot for the first sync). */
    public function pull(Request $request, SyncService $sync)
    {
        $this->refuseFallbackCompany($request);

        $since = max(0, (int) $request->query('since', 0));

        return response()->json($sync->pull($since));
    }

    /**
     * Phase 12A — a sync request whose company was FALLBACK-resolved (the session
     * pointed at a company that no longer exists or was deactivated) must FAIL,
     * not silently continue: the desktop's cursor and outbox belong to the company
     * it was syncing, and serving the default company's books against that cursor
     * would merge two companies' mirrors. Interactive pages fall back gracefully;
     * machines re-select and re-sync.
     */
    private function refuseFallbackCompany(Request $request): void
    {
        if ($request->attributes->get('active_company_was_fallback')) {
            abort(409, 'The company this session was syncing is no longer active. Re-select the company and sync again.');
        }
    }
}
