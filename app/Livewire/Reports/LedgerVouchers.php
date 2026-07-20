<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\Ledger;
use App\Services\BalanceService;
use Livewire\Component;

class LedgerVouchers extends Component
{
    use GuardsActiveCompany;

    public int $ledgerId;
    public string $ledgerName = '';
    public ?string $from = null;
    public ?string $to = null;

    public function mount(Ledger $ledger, ?string $from = null, ?string $to = null): void
    {
        $this->ledgerId = $ledger->id;
        $this->ledgerName = $ledger->name;
        [$f, $t] = app(BalanceService::class)->withinFy($from, $to);
        $this->from = $f->toDateString();
        $this->to = $t->toDateString();
    }

    public function render()
    {
        $svc = app(BalanceService::class);
        [$from, $to] = $svc->withinFy($this->from, $this->to);
        $data = $svc->ledgerVouchers($this->ledgerId, $from, $to);

        $rows = array_map(function ($r) {
            $r['amount_str'] = BalanceService::money($r['amount']);
            $r['running_str'] = BalanceService::money($r['running']);
            $r['running_side'] = BalanceService::drcr($r['running']);

            return $r;
        }, $data['rows']);

        return view('livewire.reports.ledger-vouchers', [
            'rows' => $rows,
            'ledgerName' => $data['ledger']['name'] ?? $this->ledgerName,
            'openingStr' => BalanceService::money($data['opening']),
            'openingSide' => BalanceService::drcr($data['opening']),
            'closingStr' => BalanceService::money($data['closing']),
            'closingSide' => BalanceService::drcr($data['closing']),
            'fromLabel' => $from->format('d-M-Y'),
            'toLabel' => $to->format('d-M-Y'),
        ]);
    }
}
