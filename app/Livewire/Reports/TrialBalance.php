<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Livewire\Concerns\BuildsReportRows;
use App\Services\BalanceService;
use Livewire\Component;

class TrialBalance extends Component
{
    use GuardsActiveCompany;
    use BuildsReportRows;

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
        $svc = app(BalanceService::class);
        [$from, $to] = $svc->withinFy($this->from, $this->to);
        $tb = $svc->trialBalance($from, $to);

        $rows = $this->flattenTb($tb['roots']);
        $rows[] = [
            'key' => 'total', 'kind' => 'total', 'depth' => 0, 'label' => 'Grand Total',
            'dr' => BalanceService::money($tb['total_dr']),
            'cr' => BalanceService::money($tb['total_cr']),
            'open_dr' => '', 'open_cr' => '', 'closing_paise' => 0,
            'amount' => '', 'ledger_id' => null, 'group_id' => null,
            'ancestors' => [], 'collapsible' => false, 'col' => 'full',
        ];

        return view('livewire.reports.trial-balance', [
            'rows' => $rows,
            'balanced' => $tb['balanced'],
            'grandTotal' => $tb['total_dr'],
            'fromLabel' => $from->format('d-M-Y'),
            'toLabel' => $to->format('d-M-Y'),
        ]);
    }
}
