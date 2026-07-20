<?php

namespace App\Http\Controllers;

use App\Models\CostCentre;
use App\Models\Ledger;
use App\Models\StockItem;
use App\Models\TdsSection;
use App\Services\BalanceService;
use App\Services\BillService;
use App\Services\CostCentreService;
use App\Services\TdsService;
use App\Support\Shell;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function trialBalance()
    {
        return view('reports.trial-balance', $this->shell('Reports · Trial Balance'));
    }

    public function balanceSheet()
    {
        return view('reports.balance-sheet', $this->shell('Reports · Balance Sheet'));
    }

    public function profitLoss()
    {
        return view('reports.profit-loss', $this->shell('Reports · Profit & Loss'));
    }

    public function gstSummary()
    {
        return view('reports.gst-summary', $this->shell('Reports · GST Summary'));
    }

    public function vatSummary()
    {
        return view('reports.vat-summary', $this->shell('Reports · VAT Summary'));
    }

    public function notesRegister()
    {
        return view('reports.notes-register', $this->shell('Reports · Notes Register'));
    }

    /** Phase 9A — GSTR-1 / GSTR-3B preview + JSON export for the GST portal. */
    public function gstReturns()
    {
        return view('reports.gst-returns', $this->shell('Reports · GST Returns'));
    }

    /** Phase 9B — Nepal VAT return (अनुसूची-१०) preview + transcription document. */
    public function vatReturn()
    {
        return view('reports.vat-return', $this->shell('Reports · VAT Return'));
    }

    /** Phase 10B — Form 26Q quarterly TDS returns: status, download, token log. */
    public function tdsReturns()
    {
        return view('reports.tds-returns', $this->shell('Reports · 26Q Returns'));
    }

    /** Phase 8B — Sales / Purchase Orders still awaiting delivery (?scope=sales|purchase). */
    public function ordersOutstanding(Request $request)
    {
        $scope = in_array($request->query('scope'), ['sales', 'purchase'], true) ? $request->query('scope') : 'sales';

        return view('reports.orders-outstanding', array_merge($this->shell('Reports · Orders Outstanding'), [
            'scope' => $scope,
        ]));
    }

    public function costBreakup()
    {
        return view('reports.cost-breakup', $this->shell('Reports · Cost Centre Breakup'));
    }

    /** Phase 10A — TDS deducted, per section and deductee, with what is still payable. */
    public function tdsSummary()
    {
        return view('reports.tds-summary', $this->shell('Reports · TDS Deduction Summary'));
    }

    /** The Payment vouchers behind one (section, deductee) pair in the period. */
    public function tdsDeductee(Request $request, TdsSection $section, Ledger $ledger)
    {
        [$from, $to] = app(BalanceService::class)->withinFy($request->query('from'), $request->query('to'));

        return view('reports.tds-deductee-vouchers', array_merge($this->shell('Reports · TDS Deductions'), [
            'sectionCode' => $section->code,
            'sectionLabel' => $section->label,
            'deducteeName' => $ledger->name,
            'rows' => app(TdsService::class)->deducteeVouchers($section->id, $ledger->id, $from, $to),
            'fromLabel' => $from->format('d-M-Y'),
            'toLabel' => $to->format('d-M-Y'),
        ]));
    }

    /** Phase 12C-2 — the three group consolidation reports (grouped tenants only). */
    public function groupTrialBalance()
    {
        return view('reports.group-trial-balance', $this->shell('Reports · Group Trial Balance'));
    }

    public function groupBalanceSheet()
    {
        return view('reports.group-balance-sheet', $this->shell('Reports · Group Balance Sheet'));
    }

    public function groupProfitLoss()
    {
        return view('reports.group-profit-loss', $this->shell('Reports · Group P&L'));
    }

    /** Phase 12C-1 — inter-company lot provenance (the 12C-2 elimination trace). */
    public function lotProvenance()
    {
        return view('reports.lot-provenance', $this->shell('Reports · Lot Provenance'));
    }

    /** Phase 13 — the FIFO/LIFO lot ledger for any perpetual-costed stock item. */
    public function lotLedger()
    {
        return view('reports.lot-ledger', $this->shell('Reports · Lot Ledger'));
    }

    /** Phase 11 — the forex revaluation report (unrealised gain/loss on open foreign bills). */
    public function forexRevaluation()
    {
        return view('reports.forex-revaluation', $this->shell('Reports · Forex Revaluation'));
    }

    public function stockSummary()
    {
        return view('reports.stock-summary', $this->shell('Reports · Stock Summary'));
    }

    /** One stock item's movement history in the period (drill from Stock Summary). */
    public function stockItem(Request $request, StockItem $stockItem)
    {
        return view('reports.stock-item-movement', array_merge($this->shell('Reports · Stock Item Movement'), [
            'stockItem' => $stockItem,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]));
    }

    /** The vouchers behind a single cost centre in the period. */
    public function costCentre(Request $request, CostCentre $costCentre)
    {
        [$from, $to] = app(BalanceService::class)->withinFy($request->query('from'), $request->query('to'));

        return view('reports.cost-centre-vouchers', array_merge($this->shell('Reports · Cost Centre Vouchers'), [
            'centreName' => $costCentre->name,
            'rows' => app(CostCentreService::class)->centreVouchers($costCentre->id, $from, $to),
            'fromLabel' => $from->format('d-M-Y'),
            'toLabel' => $to->format('d-M-Y'),
        ]));
    }

    public function receivables()
    {
        return view('reports.receivables', $this->shell('Reports · Receivables'));
    }

    public function payables()
    {
        return view('reports.payables', $this->shell('Reports · Payables'));
    }

    /** The vouchers behind a single bill (ledger + ?ref=). */
    public function bill(Request $request, Ledger $ledger)
    {
        $ref = (string) $request->query('ref', '');

        return view('reports.bill-vouchers', array_merge($this->shell('Reports · Bill Vouchers'), [
            'ledgerName' => $ledger->name,
            'refName' => $ref,
            'rows' => app(BillService::class)->billVouchers($ledger->id, $ref),
        ]));
    }

    public function ledger(Request $request, Ledger $ledger)
    {
        return view('reports.ledger-vouchers', array_merge($this->shell('Reports · Ledger Vouchers'), [
            'ledger' => $ledger,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]));
    }

    // ── Phase 15A — Budgets ──────────────────────────────────────────────────

    public function budgetList()
    {
        return $this->budgetsGate() ?? view('reports.budget-list', $this->shell('Reports · Budgets'));
    }

    public function budgetEditor(Request $request)
    {
        return $this->budgetsGate() ?? view('reports.budget-editor', array_merge($this->shell('Reports · Budget Editor'), [
            'budgetId' => $request->query('budget') !== null ? (int) $request->query('budget') : null,
            'revise' => (bool) $request->query('revise', false),
        ]));
    }

    public function budgetVariance()
    {
        return $this->budgetsGate() ?? view('reports.budget-variance', $this->shell('Reports · Budget vs Actual'));
    }

    public function budgetSummary()
    {
        return $this->budgetsGate() ?? view('reports.budget-summary', $this->shell('Reports · Budget Summary'));
    }

    /** When the F11 Budgets flag is off, the Budget screens are not accessible (back to Gateway). */
    private function budgetsGate(): ?\Illuminate\Http\RedirectResponse
    {
        if (! (bool) \App\Models\CompanyFeature::current()->budgets) {
            return redirect()->route('gateway');
        }

        return null;
    }

    // ── Phase 15B — Ratio Analysis ───────────────────────────────────────────

    public function ratioDashboard()
    {
        return $this->ratiosGate() ?? view('reports.ratio-dashboard', $this->shell('Reports · Ratio Analysis'));
    }

    public function ratioDrilldown(Request $request, string $ratio)
    {
        return $this->ratiosGate() ?? view('reports.ratio-drilldown', array_merge($this->shell('Reports · Ratio Inputs'), [
            'ratio' => $ratio,
            'asOf' => $request->query('asOf'),
        ]));
    }

    public function ratioThresholds()
    {
        return $this->ratiosGate() ?? view('reports.ratio-thresholds', $this->shell('Reports · Ratio Thresholds'));
    }

    /** When the F11 Ratio Analysis flag is off, the ratio screens are not accessible. */
    private function ratiosGate(): ?\Illuminate\Http\RedirectResponse
    {
        if (! (bool) \App\Models\CompanyFeature::current()->ratio_analysis) {
            return redirect()->route('gateway');
        }

        return null;
    }

    // ── Phase 15C — Scenarios ────────────────────────────────────────────────

    /** The Scenario Master — create / rename / activate / typed-delete scenarios. */
    public function scenarioMaster()
    {
        return $this->scenariosGate() ?? view('reports.scenario-master', $this->shell('Reports · Scenarios'));
    }

    /** The Scenario Manager — review a scenario's provisional vouchers and promote it. */
    public function scenarioManager(Request $request)
    {
        return $this->scenariosGate() ?? view('reports.scenario-manager', array_merge($this->shell('Reports · Scenario Manager'), [
            'scenarioId' => $request->query('scenario') !== null ? (int) $request->query('scenario') : null,
        ]));
    }

    /** The Impact Report — real vs real+scenario, drillable to the scenario's vouchers. */
    public function scenarioImpact(Request $request)
    {
        return $this->scenariosGate() ?? view('reports.scenario-impact', array_merge($this->shell('Reports · Scenario Impact'), [
            'scenarioId' => $request->query('scenario') !== null ? (int) $request->query('scenario') : null,
            'asOf' => $request->query('asOf'),
        ]));
    }

    /** When the F11 Scenarios flag is off, the scenario screens are not accessible. */
    private function scenariosGate(): ?\Illuminate\Http\RedirectResponse
    {
        if (! (bool) \App\Models\CompanyFeature::current()->scenarios) {
            return redirect()->route('gateway');
        }

        return null;
    }

    private function shell(string $region): array
    {
        return [
            'zbConfig' => Shell::config(),
            'zbNav' => Shell::nav(),
            'region' => $region,
        ];
    }
}
