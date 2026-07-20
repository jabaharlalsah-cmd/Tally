<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Services\BalanceService;
use App\Services\VatService;
use Livewire\Component;

/**
 * VAT Summary (Nepal) — the period's Output VAT, Input VAT and Net VAT Payable,
 * plus the taxable value on each side. A single flat rate, so no CGST/SGST/IGST
 * breakdown. Each VAT line drills to that duty ledger's Ledger Vouchers, reusing
 * the shared report drill (mirrors GST Summary).
 */
class VatSummary extends Component
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
        $vat = app(VatService::class);
        [$from, $to] = $bs->withinFy($this->from, $this->to);
        $s = $vat->summary($from, $to);

        $money = fn (int $p) => BalanceService::money($p);

        $rows = [];
        $rows[] = ['kind' => 'section', 'label' => 'Outward Supplies (Sales)', 'amount' => '', 'ledger_id' => null];
        $rows[] = ['kind' => 'row', 'label' => 'Taxable value', 'amount' => $money($s['taxable_sales']), 'ledger_id' => null];
        $rows[] = ['kind' => 'row', 'label' => 'Output VAT', 'amount' => $money($s['output']), 'ledger_id' => $s['output_ledger_id']];
        $rows[] = ['kind' => 'total', 'label' => 'Total Output VAT', 'amount' => $money($s['output']), 'ledger_id' => null];

        $rows[] = ['kind' => 'section', 'label' => 'Inward Supplies (Purchase)', 'amount' => '', 'ledger_id' => null];
        $rows[] = ['kind' => 'row', 'label' => 'Taxable value', 'amount' => $money($s['taxable_purchase']), 'ledger_id' => null];
        $rows[] = ['kind' => 'row', 'label' => 'Input VAT (credit)', 'amount' => $money($s['input']), 'ledger_id' => $s['input_ledger_id']];
        $rows[] = ['kind' => 'total', 'label' => 'Total Input VAT (credit)', 'amount' => $money($s['input']), 'ledger_id' => null];

        $net = $s['net_payable'];
        $rows[] = [
            'kind' => 'net',
            'label' => $net >= 0 ? 'Net VAT Payable' : 'Net VAT Credit Carried Forward',
            'amount' => $money(abs($net)),
            'ledger_id' => null,
        ];

        return view('livewire.reports.vat-summary', [
            'rows' => $rows,
            'vatEnabled' => $vat->enabled(),
            'companyPan' => $vat->companyPan(),
            'fromLabel' => $from->format('d-M-Y'),
            'toLabel' => $to->format('d-M-Y'),
        ]);
    }
}
