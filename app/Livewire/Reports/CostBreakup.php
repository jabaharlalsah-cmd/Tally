<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Services\BalanceService;
use App\Services\CostCentreService;
use Carbon\Carbon;
use Livewire\Component;

/**
 * Cost Centre Breakup — per cost centre, the ledger-wise amounts and total for
 * the period. Keyboard-navigable; a cost-centre header drills (Enter) to the
 * vouchers behind it. Cost allocations are analytical, so this reads them without
 * touching the accounting.
 */
class CostBreakup extends Component
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
        $svc = app(CostCentreService::class);
        [$from, $to] = app(BalanceService::class)->withinFy($this->from, $this->to);
        $data = $svc->breakup($from, $to);

        // Flatten: centre header (drillable) → ledger rows → centre total.
        $rows = [];
        $anyActivity = false;
        foreach ($data['centres'] as $c) {
            if (! $c['has_activity']) {
                continue;
            }
            $anyActivity = true;
            $rows[] = ['kind' => 'centre', 'label' => $c['name'], 'sub' => $c['path'], 'amount' => $c['total'], 'cost_centre_id' => $c['cost_centre_id']];
            foreach ($c['ledgers'] as $l) {
                $rows[] = ['kind' => 'ledger', 'label' => $l['name'], 'sub' => '', 'amount' => $l['amount'], 'cost_centre_id' => null];
            }
            $rows[] = ['kind' => 'subtotal', 'label' => 'Total for '.$c['name'], 'sub' => '', 'amount' => $c['total'], 'cost_centre_id' => null];
        }

        return view('livewire.reports.cost-breakup', [
            'rows' => $rows,
            'grandTotal' => $data['grand_total'],
            'empty' => ! $anyActivity,
            'fromLabel' => $from->format('d-M-Y'),
            'toLabel' => $to->format('d-M-Y'),
        ]);
    }
}
