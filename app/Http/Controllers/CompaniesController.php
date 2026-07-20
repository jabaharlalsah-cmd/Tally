<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\CompanyProvisioner;
use App\Support\Shell;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 12A — multi-company: the management screen and the switch endpoint.
 *
 * Switching is deliberately a full POST + client-side full-page redirect to the
 * Gateway (never an in-place mutation): every client cache — ZB_CONFIG, ZB_NAV,
 * masters store seeds, Livewire snapshots — is a page-load snapshot of the OLD
 * company, so the only correct invalidation is a fresh page under the new one.
 * Open voucher/report screens are discarded, exactly as Tally does it.
 */
class CompaniesController extends Controller
{
    /** The Companies master screen (create / rename / deactivate). */
    public function index()
    {
        return view('masters.companies', [
            'zbConfig' => Shell::config(),
            'zbNav' => Shell::nav(),
        ]);
    }

    /** Phase 12B — the Groups screen (create groups, manage membership). */
    public function groups()
    {
        return view('masters.company-groups', [
            'zbConfig' => Shell::config(),
            'zbNav' => Shell::nav(),
        ]);
    }

    /**
     * Phase 16 — POST /companies: create a fully seeded company and switch to it.
     *
     * Reached from the F1 company picker when the typed name matches nothing, so a CA can
     * add a client book without leaving the flow. Deliberately a real form POST ending in
     * a redirect to the Gateway — same cache-invalidation contract as switch(): every
     * client cache is a page-load snapshot of the OLD company, so only a fresh page under
     * the new one is correct.
     */
    public function store(Request $request, CompanyProvisioner $provisioner)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'slug' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            // Seeds the full chart: 28 groups, Cash + P&L, duty ledgers, TDS catalog,
            // base currency, forex ledgers, Main Location, fresh all-off F11.
            $company = $provisioner->create(trim($data['name']), ($data['slug'] ?? '') ?: null);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $request->session()->put('active_company_id', $company->id);

        return redirect()->route('gateway')
            ->with('flash', "Company “{$company->name}” created and selected.");
    }

    /** POST /company/switch — set the session's active company. */
    public function switch(Request $request)
    {
        $data = $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
        ]);

        $company = Company::find($data['company_id']);

        if (! $company->is_active) {
            return response()->json(['ok' => false, 'message' => 'That company is deactivated.'], 422);
        }

        $request->session()->put('active_company_id', $company->id);

        return response()->json([
            'ok' => true,
            'company' => $company->toCache(),
            'url' => route('gateway'),
        ]);
    }
}
