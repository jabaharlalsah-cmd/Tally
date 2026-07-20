<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\Voucher;
use App\Support\ScenarioContext;
use Carbon\Carbon;
use Livewire\Component;

class DayBook extends Component
{
    use GuardsActiveCompany;

    public string $from;
    public string $to;

    public function mount(): void
    {
        $today = Carbon::today();
        $fyStart = Voucher::fyStartFor($today);
        $this->from = Voucher::fyOpenFor($fyStart)->toDateString();
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
        return Voucher::with(['entries.ledger', 'stockEntries.stockItem.unit', 'stockEntries.godown'])
            ->whereBetween('date', [$this->from, $this->to])
            ->tap(fn ($q) => ScenarioContext::apply($q, 'vouchers'))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map->toRow()
            ->all();
    }

    /** Cancel/delete a voucher — cascade removes its postings cleanly. */
    public function cancel(int $id): array
    {
        $v = Voucher::find($id);
        if (! $v) {
            return ['ok' => false, 'message' => 'Voucher not found.'];
        }
        $label = $v->displayNumber();
        \Illuminate\Support\Facades\DB::transaction(fn () => $v->delete()); // Phase 12C-1 — cascade + lot refold commit together // entries cascade via FK

        return ['ok' => true, 'message' => 'Cancelled '.$label];
    }

    public function render()
    {
        return view('livewire.day-book', [
            'voucherRows' => $this->rows(),
            'fyLabel' => Voucher::fyLabel(Voucher::fyStartFor(Carbon::parse($this->from))),
        ]);
    }
}
