<?php

namespace App\Http\Controllers;

use App\Support\Shell;

class MastersController extends Controller
{
    public function index()
    {
        return view('masters.index', [
            'zbConfig' => Shell::config(),
            'zbNav' => Shell::nav(),
            'items' => array_merge([
                ['letter' => 'G', 'label' => 'Groups', 'desc' => 'Account groups — Create / Display / Alter', 'kind' => 'nav', 'href' => route('masters.groups')],
                ['letter' => 'L', 'label' => 'Ledgers', 'desc' => 'Ledger accounts — Create / Display / Alter', 'kind' => 'nav', 'href' => route('masters.ledgers')],
            ], \App\Models\CompanyFeature::current()->cost_centres ? [
                ['letter' => 'C', 'label' => 'Cost Centres', 'desc' => 'Cost centres — Create / Display / Alter', 'kind' => 'nav', 'href' => route('masters.cost-centres')],
            ] : [], \App\Models\CompanyFeature::current()->tds ? [
                ['letter' => 'T', 'label' => 'TDS Sections', 'desc' => 'The TDS rate table — rates, thresholds, effective years', 'kind' => 'nav', 'href' => route('masters.tds-sections')],
            ] : [], \App\Models\CompanyFeature::current()->multi_currency ? [
                ['letter' => 'U', 'label' => 'Currencies', 'desc' => 'Currencies & exchange rates — multi-currency', 'kind' => 'nav', 'href' => route('masters.currencies')],
            ] : [], [
                // NAS parity Phase 3 — the chart of accounts as DATA, not as a
                // menu of places to go. Last, as in TallyPrime.
                ['letter' => 'A', 'label' => 'List of Accounts', 'desc' => 'The whole tree — groups & ledgers by nature', 'kind' => 'nav', 'href' => route('masters.list-of-accounts')],
            ]),
        ]);
    }

    public function groups()
    {
        return view('masters.groups', [
            'zbConfig' => Shell::config(),
            'zbNav' => Shell::nav(),
        ]);
    }

    public function ledgers()
    {
        return view('masters.ledgers', [
            'zbConfig' => Shell::config(),
            'zbNav' => Shell::nav(),
        ]);
    }

    public function costCentres()
    {
        return view('masters.cost-centres', [
            'zbConfig' => Shell::config(),
            'zbNav' => Shell::nav(),
        ]);
    }

    /** Phase 11 — the currency + exchange-rate master. Reachable whether or not
     *  multi-currency is on, so a user can set up currencies before enabling it. */
    public function currencies()
    {
        return view('masters.currencies', [
            'zbConfig' => Shell::config(),
            'zbNav' => Shell::nav(),
        ]);
    }

    /** Phase 10A — the TDS rate table. Reachable whether or not TDS is switched on, so a
     *  user can review the seeded rates before enabling the feature. */
    public function tdsSections()
    {
        return view('masters.tds-sections', [
            'zbConfig' => Shell::config(),
            'zbNav' => Shell::nav(),
        ]);
    }

    /**
     * List of Accounts — the Nature → Group → Ledger tree in one screen.
     *
     * Groups and ledgers are passed whole and the tree is built client-side:
     * expanding a node must not cost a round-trip, and a chart of accounts is
     * small enough to ship in one payload.
     */
    public function listOfAccounts()
    {
        return view('masters.list-of-accounts', [
            'zbConfig' => Shell::config(),
            'zbNav' => Shell::nav(),
            'groups' => \App\Models\AccountGroup::orderBy('name')->get()->map->toCache()->all(),
            'ledgers' => \App\Models\Ledger::with('group')->orderBy('name')->get()->map->toCache()->all(),
        ]);
    }
}
