<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Livewire\Concerns\BuildsReportRows;
use App\Services\BalanceService;
use Livewire\Component;

class BalanceSheet extends Component
{
    use GuardsActiveCompany;
    use BuildsReportRows;

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
        $svc = app(BalanceService::class);
        [$from, $to] = $svc->withinFy($this->from, $this->to);
        $bs = $svc->balanceSheet($from, $to);

        // Left = Liabilities, Right = Assets
        $left = $this->flattenMag($bs['liability_roots'], 'left');
        if ($bs['is_profit'] && $bs['net'] > 0) {
            $left[] = $this->specialRow('netprofit', 'Nett Profit', BalanceService::money($bs['net']), 'left', 'net');
        }
        if ($bs['liability_diff'] > 0) {
            $left[] = $this->specialRow('ldiff', 'Difference in opening balances', BalanceService::money($bs['liability_diff']), 'left', 'diff');
        }
        $left[] = $this->specialRow('ltotal', 'Total', BalanceService::money($bs['liability_total']), 'left', 'total');

        $right = $this->flattenMag($bs['asset_roots'], 'right');
        if (! $bs['is_profit'] && $bs['net'] < 0) {
            $right[] = $this->specialRow('netloss', 'Nett Loss', BalanceService::money($bs['net']), 'right', 'net');
        }
        if ($bs['asset_diff'] > 0) {
            $right[] = $this->specialRow('adiff', 'Difference in opening balances', BalanceService::money($bs['asset_diff']), 'right', 'diff');
        }
        $right[] = $this->specialRow('atotal', 'Total', BalanceService::money($bs['asset_total']), 'right', 'total');

        return view('livewire.reports.balance-sheet', [
            'left' => $left,
            'right' => $right,
            'rows' => array_merge($left, $right),
            'balanced' => $bs['balanced'],
            'fromLabel' => $from->format('d-M-Y'),
            'toLabel' => $to->format('d-M-Y'),
        ]);
    }
}
