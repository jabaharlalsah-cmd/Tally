<?php

namespace App\Livewire\Reports;

use App\Services\GroupConsolidationService;

/** Phase 12C-2 — Group Trial Balance (adjusted rows by account group, Dr = Cr). */
class GroupTrialBalance extends GroupReportBase
{
    public function render()
    {
        $svc = app(GroupConsolidationService::class);
        $shell = $this->shellData($svc);
        [$from, $to] = $this->period();

        return view('livewire.reports.group-trial-balance', array_merge($shell, [
            'report' => $shell['group'] ? $svc->groupTrialBalance($shell['group'], $from, $to) : null,
        ]));
    }
}
