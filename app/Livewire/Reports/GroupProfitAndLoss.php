<?php

namespace App\Livewire\Reports;

use App\Services\GroupConsolidationService;

/** Phase 12C-2 — Group P&L (inter-company sales/purchases eliminated, net ties to the BS). */
class GroupProfitAndLoss extends GroupReportBase
{
    public function render()
    {
        $svc = app(GroupConsolidationService::class);
        $shell = $this->shellData($svc);
        [$from, $to] = $this->period();

        return view('livewire.reports.group-profit-loss', array_merge($shell, [
            'report' => $shell['group'] ? $svc->groupProfitAndLoss($shell['group'], $from, $to) : null,
        ]));
    }
}
