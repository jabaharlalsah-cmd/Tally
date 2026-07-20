<?php

namespace App\Livewire\Reports;

use App\Services\GroupConsolidationService;

/** Phase 12C-2 — Group Balance Sheet (eliminations + unrealised stock adjustment). */
class GroupBalanceSheet extends GroupReportBase
{
    public function render()
    {
        $svc = app(GroupConsolidationService::class);
        $shell = $this->shellData($svc);
        [$from, $to] = $this->period();

        return view('livewire.reports.group-balance-sheet', array_merge($shell, [
            'report' => $shell['group'] ? $svc->groupBalanceSheet($shell['group'], $from, $to) : null,
        ]));
    }
}
