<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Livewire\Concerns\BuildsReportRows;
use App\Services\BalanceService;
use Livewire\Component;

class ProfitLoss extends Component
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
        $pl = $svc->profitAndLoss($from, $to);

        $M = fn ($p) => BalanceService::money($p);
        // A company with no inventory sees the EXACT pre-6D flat P&L — byte-identical
        // output, the regression gate. With inventory we render Tally's two-section
        // horizontal format (Trading account → Gross Profit → P&L account), where each
        // section balances, so every visible column adds up to its own Total.
        // Sign-aware: closing stock can go negative (an item oversold past its cost
        // basis), which still shifts net — so gate on != 0, not > 0, or the flat
        // branch would run while net differs from ledger_net and a column wouldn't sum.
        $hasStock = $pl['opening_stock'] != 0 || $pl['closing_stock'] != 0;

        if (! $hasStock) {
            $grand = max($pl['total_expenses'] + max($pl['net'], 0), $pl['total_income'] + max(-$pl['net'], 0));
            $left = $this->flattenMag($pl['expense_roots'], 'left');
            if ($pl['is_profit'] && $pl['net'] > 0) {
                $left[] = $this->specialRow('netprofit', 'Nett Profit', $M($pl['net']), 'left', 'net');
            }
            $left[] = $this->specialRow('ltotal', 'Total', $M($grand), 'left', 'total');
            $right = $this->flattenMag($pl['income_roots'], 'right');
            if (! $pl['is_profit'] && $pl['net'] < 0) {
                $right[] = $this->specialRow('netloss', 'Nett Loss', $M($pl['net']), 'right', 'net');
            }
            $right[] = $this->specialRow('rtotal', 'Total', $M($grand), 'right', 'total');
        } else {
            $gp = $pl['gross_profit'];
            $net = $pl['net'];
            // Trading account: Dr (Opening + Purchase/Direct-Exp + GP c/d) = Cr
            // (Sales/Direct-Inc + Closing + Gross-Loss c/d). P&L account: Dr (Indirect
            // Exp + Gross-Loss b/d + Nett Profit) = Cr (GP b/d + Indirect Inc + Nett Loss).
            $close = $pl['closing_stock'];
            // Positive closing stock sits on the Cr (income) side; a negative closing
            // (oversold) sits on the Dr side. Trading Total uses the magnitudes so the
            // section balances whichever way closing stock falls.
            $tradingTotal = $pl['trading_income'] + max($close, 0) + max(-$gp, 0);
            $plTotal = max($gp, 0) + $pl['indirect_income'] + max(-$net, 0);

            $left = [];
            if ($pl['opening_stock'] > 0) {
                $left[] = $this->specialRow('openstock', 'Opening Stock', $M($pl['opening_stock']), 'left', 'stock');
            }
            $left = array_merge($left, $this->flattenMag($pl['trading_expense_roots'], 'left'));
            if ($close < 0) {
                $left[] = $this->specialRow('closestockneg', 'Closing Stock (deficit)', $M($close), 'left', 'stock');
            }
            if ($gp >= 0) {
                $left[] = $this->specialRow('gpcd', 'Gross Profit c/d', $M($gp), 'left', 'gross');
            }
            $left[] = $this->specialRow('ltrade', 'Trading Total', $M($tradingTotal), 'left', 'subtotal');
            $left = array_merge($left, $this->flattenMag($pl['indirect_expense_roots'], 'left'));
            if ($gp < 0) {
                $left[] = $this->specialRow('glbd', 'Gross Loss b/d', $M($gp), 'left', 'gross');
            }
            if ($net > 0) {
                $left[] = $this->specialRow('netprofit', 'Nett Profit', $M($net), 'left', 'net');
            }
            $left[] = $this->specialRow('ltotal', 'Total', $M($plTotal), 'left', 'total');

            $right = $this->flattenMag($pl['trading_income_roots'], 'right');
            if ($close > 0) {
                $right[] = $this->specialRow('closestock', 'Closing Stock', $M($close), 'right', 'stock');
            }
            if ($gp < 0) {
                $right[] = $this->specialRow('glcd', 'Gross Loss c/d', $M($gp), 'right', 'gross');
            }
            $right[] = $this->specialRow('rtrade', 'Trading Total', $M($tradingTotal), 'right', 'subtotal');
            if ($gp >= 0) {
                $right[] = $this->specialRow('gpbd', 'Gross Profit b/d', $M($gp), 'right', 'gross');
            }
            $right = array_merge($right, $this->flattenMag($pl['indirect_income_roots'], 'right'));
            if ($net < 0) {
                $right[] = $this->specialRow('netloss', 'Nett Loss', $M($net), 'right', 'net');
            }
            $right[] = $this->specialRow('rtotal', 'Total', $M($plTotal), 'right', 'total');
        }

        return view('livewire.reports.profit-loss', [
            'left' => $left,
            'right' => $right,
            'rows' => array_merge($left, $right),
            'isProfit' => $pl['is_profit'],
            'net' => BalanceService::money($pl['net']),
            'fromLabel' => $from->format('d-M-Y'),
            'toLabel' => $to->format('d-M-Y'),
        ]);
    }
}
