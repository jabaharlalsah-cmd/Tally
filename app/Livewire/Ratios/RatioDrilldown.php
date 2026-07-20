<?php

namespace App\Livewire\Ratios;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Services\BalanceService;
use App\Services\RatioService;
use Carbon\Carbon;
use Livewire\Component;

/**
 * Phase 15B — the Ratio Drill-down: for one ratio, its traced input figures and the contributing
 * ledgers (each drillable to its vouchers via the existing ledger drill).
 */
class RatioDrilldown extends Component
{
    use GuardsActiveCompany;

    public string $ratioKey = '';
    public ?string $asOf = null;

    public function mount(string $ratio, ?string $asOf = null): void
    {
        $this->ratioKey = $ratio;
        $this->asOf = $asOf ?: now()->toDateString();
    }

    public function render()
    {
        $svc = app(RatioService::class);
        $asOf = Carbon::parse($this->asOf);

        $detail = $svc->detail($this->ratioKey, $asOf);
        $contrib = $detail ? $svc->contributingLedgers($this->ratioKey, $asOf) : [];

        return view('livewire.ratios.drilldown', [
            'detail' => $detail,
            'contrib' => $contrib,
            'asOfLabel' => $asOf->format('d-M-Y'),
            'asOf' => $asOf->toDateString(),
            'from' => \App\Models\Voucher::fyOpenFor(\App\Models\Voucher::fyStartFor($asOf))->toDateString(),
            'money' => fn (int $p) => BalanceService::money($p),
        ]);
    }
}
