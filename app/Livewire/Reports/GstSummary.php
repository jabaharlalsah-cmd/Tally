<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Services\BalanceService;
use App\Services\GstService;
use Livewire\Component;

/**
 * GST Summary — the period's output tax, input tax (ITC) and net tax payable,
 * plus the taxable value on each side. A liability/summary view (not GSTR return
 * filing, which is a later phase). Each tax line drills to that duty ledger's
 * Ledger Vouchers, reusing the Phase 4 drill.
 */
class GstSummary extends Component
{
    use GuardsActiveCompany;

    public ?string $from = null;
    public ?string $to = null;

    public function mount(): void
    {
        [$f, $t] = app(BalanceService::class)->withinFy(null, null);
        $this->from = $f->toDateString();
        $this->to = $t->toDateString();
    }

    public function render()
    {
        $bs = app(BalanceService::class);
        $gst = app(GstService::class);
        [$from, $to] = $bs->withinFy($this->from, $this->to);
        $s = $gst->summary($from, $to);

        $money = fn (int $p) => BalanceService::money($p);

        $rows = [];
        $rows[] = ['kind' => 'section', 'label' => 'Outward Supplies (Sales)', 'amount' => '', 'ledger_id' => null];
        $rows[] = ['kind' => 'row', 'label' => 'Taxable value', 'amount' => $money($s['taxable_sales']), 'ledger_id' => null];
        $rows[] = ['kind' => 'row', 'label' => 'Output CGST', 'amount' => $money($s['output']['central']), 'ledger_id' => $s['output_ledger_ids']['central']];
        $rows[] = ['kind' => 'row', 'label' => 'Output SGST', 'amount' => $money($s['output']['state']), 'ledger_id' => $s['output_ledger_ids']['state']];
        $rows[] = ['kind' => 'row', 'label' => 'Output IGST', 'amount' => $money($s['output']['integrated']), 'ledger_id' => $s['output_ledger_ids']['integrated']];
        $rows[] = ['kind' => 'total', 'label' => 'Total Output Tax', 'amount' => $money($s['output_total']), 'ledger_id' => null];

        $rows[] = ['kind' => 'section', 'label' => 'Inward Supplies (Purchase)', 'amount' => '', 'ledger_id' => null];
        $rows[] = ['kind' => 'row', 'label' => 'Taxable value', 'amount' => $money($s['taxable_purchase']), 'ledger_id' => null];
        $rows[] = ['kind' => 'row', 'label' => 'Input CGST (ITC)', 'amount' => $money($s['input']['central']), 'ledger_id' => $s['input_ledger_ids']['central']];
        $rows[] = ['kind' => 'row', 'label' => 'Input SGST (ITC)', 'amount' => $money($s['input']['state']), 'ledger_id' => $s['input_ledger_ids']['state']];
        $rows[] = ['kind' => 'row', 'label' => 'Input IGST (ITC)', 'amount' => $money($s['input']['integrated']), 'ledger_id' => $s['input_ledger_ids']['integrated']];
        $rows[] = ['kind' => 'total', 'label' => 'Total Input Tax (ITC)', 'amount' => $money($s['input_total']), 'ledger_id' => null];

        $net = $s['net_payable'];
        $rows[] = [
            'kind' => 'net',
            'label' => $net >= 0 ? 'Net GST Payable' : 'Net ITC Carried Forward',
            'amount' => $money(abs($net)),
            'ledger_id' => null,
        ];

        return view('livewire.reports.gst-summary', [
            'rows' => $rows,
            'gstEnabled' => $gst->enabled(),
            'companyState' => $gst->companyState(),
            'fromLabel' => $from->format('d-M-Y'),
            'toLabel' => $to->format('d-M-Y'),
        ]);
    }
}
