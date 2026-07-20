<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Services\BalanceService;
use App\Services\BillService;
use Carbon\Carbon;
use Livewire\Component;

/**
 * Outstandings — Receivables (Sundry Debtors) / Payables (Sundry Creditors).
 * Per party, each open bill with ref, dates, original, pending and overdue days;
 * party subtotals and a grand total that reconciles with the ledger balances
 * (the on-account remainder is surfaced, never hidden). Drills to the vouchers
 * behind a bill.
 */
class Outstandings extends Component
{
    use GuardsActiveCompany;

    public string $mode = 'receivable';
    public ?string $to = null;

    public function mount(string $mode = 'receivable'): void
    {
        $this->mode = $mode === 'payable' ? 'payable' : 'receivable';
        [, $t] = app(BalanceService::class)->withinFy(null, null);
        $this->to = $t->toDateString();
    }

    public function render()
    {
        $svc = app(BillService::class);
        $asOf = $this->to ? Carbon::parse($this->to) : Carbon::today();
        $data = $svc->outstandings($this->mode, $asOf);

        // Flatten into keyboard-navigable rows; only 'bill' rows drill.
        $rows = [];
        foreach ($data['parties'] as $p) {
            $rows[] = ['kind' => 'party', 'label' => $p['name'], 'ledger_id' => $p['ledger_id'], 'ref' => null,
                'date' => '', 'due' => '', 'original' => '', 'pending' => '', 'overdue' => ''];
            foreach ($p['rows'] as $b) {
                $rows[] = [
                    'kind' => 'bill', 'label' => $b['ref_name'], 'ledger_id' => $p['ledger_id'], 'ref' => $b['ref_name'],
                    'date' => $b['bill_date'], 'due' => $b['due_date'], 'original' => $b['original'],
                    'pending' => $b['pending'], 'overdue' => $b['overdue_days'] > 0 ? $b['overdue_days'].'d' : '',
                ];
            }
            if ((float) str_replace(',', '', $p['on_account']) != 0) {
                $rows[] = ['kind' => 'onaccount', 'label' => 'On account / opening (unbilled)', 'ledger_id' => $p['ledger_id'], 'ref' => null,
                    'date' => '', 'due' => '', 'original' => '', 'pending' => $p['on_account'], 'overdue' => ''];
            }
            $rows[] = ['kind' => 'subtotal', 'label' => 'Total for '.$p['name'], 'ledger_id' => null, 'ref' => null,
                'date' => '', 'due' => '', 'original' => '', 'pending' => $p['closing'], 'overdue' => ''];
        }

        return view('livewire.reports.outstandings', [
            'rows' => $rows,
            'title' => $this->mode === 'payable' ? 'Payables (Outstandings)' : 'Receivables (Outstandings)',
            'colLabel' => $this->mode === 'payable' ? 'We owe' : 'Owed to us',
            'grandPending' => $data['grand_pending'],
            'grandOnAccount' => $data['grand_on_account'],
            'grandClosing' => $data['grand_closing'],
            'reconciles' => $data['reconciles'],
            'group' => $data['group'],
            'asOf' => $data['as_of'],
            'empty' => empty($data['parties']),
        ]);
    }
}
