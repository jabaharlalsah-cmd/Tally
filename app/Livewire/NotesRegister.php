<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\Ledger;
use App\Models\Voucher;
use Carbon\Carbon;
use Livewire\Component;

/**
 * Phase 8A — the Notes register: a chronological list of just Debit & Credit Notes,
 * filterable by party and F2 period, drillable to the voucher. A small convenience
 * over the Day Book (Notes are ordinary vouchers, so every other report already picks
 * them up automatically).
 */
class NotesRegister extends Component
{
    use GuardsActiveCompany;

    public string $from;
    public string $to;
    public ?int $partyId = null;
    public string $kind = 'all'; // all | credit_note | debit_note

    public function mount(): void
    {
        $today = Carbon::today();
        $this->from = Voucher::fyOpenFor(Voucher::fyStartFor($today))->toDateString();
        $this->to = $today->toDateString();
    }

    /** Set the review period (F2). */
    public function setPeriod(string $from, string $to): array
    {
        $this->from = Carbon::parse($from)->toDateString();
        $this->to = Carbon::parse($to)->toDateString();

        return $this->rows();
    }

    public function rows(): array
    {
        $types = $this->kind === 'all' ? Voucher::NOTE_TYPES : [$this->kind];

        return Voucher::with(['partyLedger', 'referenceVoucher'])
            ->whereIn('type', $types)
            ->whereBetween('date', [$this->from, $this->to])
            ->when($this->partyId, fn ($q) => $q->where('party_ledger_id', $this->partyId))
            ->orderBy('date')->orderBy('id')
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'type' => $v->type,
                'type_label' => Voucher::TYPES[$v->type]['label'] ?? ucfirst($v->type),
                'display_number' => $v->displayNumber(),
                'date_label' => $v->date->format('d-M-Y'),
                'party' => $v->partyLedger?->name ?? '—',
                'reference' => $v->referenceVoucher?->displayNumber(),
                'amount' => $v->totalDr(),
                'narration' => $v->narration,
            ])->all();
    }

    public function render()
    {
        $rows = $this->rows();

        return view('livewire.notes-register', [
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'amount')),
            'parties' => Ledger::whereNotNull('group_id')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name])->all(),
            'fyLabel' => Voucher::fyLabel(Voucher::fyStartFor(Carbon::parse($this->from))),
        ]);
    }
}
