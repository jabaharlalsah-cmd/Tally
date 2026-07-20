<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Services\BalanceService;
use App\Services\TdsService;
use Livewire\Component;

/**
 * Phase 10A — the TDS Deduction Summary.
 *
 * Per section, per deductee: the base paid, the tax deducted, and how much of it is still
 * owed to the Revenue. Remitting TDS is an ordinary Payment against the TDS Payable ledger
 * (one challan clears many deductions, and carries no section tag), so the outstanding
 * figure is allocated FIFO across the year's deductions. That allocation reconciles exactly
 * to the TDS Payable ledger's closing balance — see TdsService::summary().
 */
class TdsSummary extends Component
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
        $tds = app(TdsService::class);
        [$from, $to] = app(BalanceService::class)->withinFy($this->from, $this->to);

        return view('livewire.reports.tds-summary', array_merge($tds->summary($from, $to), [
            'enabled' => $tds->enabled(),
            'fromLabel' => $from->format('d-M-Y'),
            'toLabel' => $to->format('d-M-Y'),
        ]));
    }
}
