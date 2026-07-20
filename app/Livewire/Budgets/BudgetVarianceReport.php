<?php

namespace App\Livewire\Budgets;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\Budget;
use App\Models\Voucher;
use App\Services\BalanceService;
use App\Services\BudgetService;
use Carbon\Carbon;
use Livewire\Component;

/**
 * Phase 15A — Budget vs Actual Variance. For a chosen budget (defaults to primary) over the
 * F2 period: per line target vs actual vs variance vs variance %, drillable to the underlying
 * vouchers via the existing ledger drill. Untracked income/expense ledgers show as "No target"
 * (distinct from a zero target).
 */
class BudgetVarianceReport extends Component
{
    use GuardsActiveCompany;

    public ?int $budgetId = null;
    public ?string $from = null;
    public ?string $to = null;

    public function mount(): void
    {
        $budget = Budget::primaryForCompany();
        $this->budgetId = $budget?->id;
        $fy = $budget ? (int) $budget->fiscal_year_start : Voucher::fyStartFor(now());
        $this->from = Voucher::fyOpenFor($fy)->toDateString();
        $this->to = now()->toDateString();
    }

    public function render()
    {
        $budget = $this->budgetId
            ? Budget::query()->find($this->budgetId)
            : Budget::primaryForCompany();

        $rows = [];
        $fromLabel = $toLabel = '';
        // Net (revenue − expense) totals — summing target/actual across mixed Income + Expense
        // natures with a single favorability test is meaningless, so we net them like the Summary.
        $revT = $revA = $expT = $expA = 0;

        if ($budget) {
            $from = Carbon::parse($this->from);
            $to = Carbon::parse($this->to);
            $data = app(BudgetService::class)->variance($budget, $from, $to, true);
            // variance() clamps the window to the budget's fiscal year — reflect that in the labels.
            $fromLabel = Carbon::parse($data['from'])->format('d-M-Y');
            $toLabel = Carbon::parse($data['to'])->format('d-M-Y');

            foreach ($data['lines'] as $r) {
                // Net roll-up excludes "No target" rows and ledgers already counted inside a
                // budgeted group, and nets income against expense.
                if (! $r['no_target'] && ! $r['redundant_in_total']) {
                    if ($r['nature'] === 'Income') {
                        $revT += (int) $r['target'];
                        $revA += (int) $r['actual'];
                    } elseif ($r['nature'] === 'Expenses') {
                        $expT += (int) $r['target'];
                        $expA += (int) $r['actual'];
                    }
                }

                $rows[] = [
                    'key' => $r['key'],
                    'kind' => $r['kind'],
                    'name' => $r['name'],
                    'nature' => $r['nature'],
                    'no_target' => $r['no_target'],
                    'target' => $r['target'] === null ? null : BalanceService::money((int) $r['target']),
                    'actual' => BalanceService::money((int) $r['actual']),
                    'variance' => $r['variance'] === null ? null : $this->signed((int) $r['variance']),
                    'variance_pct' => $r['variance_pct'],
                    'is_favorable' => $r['is_favorable'],
                    'revised' => $r['revised'],
                    'drill_ledger_id' => $r['drill_ledger_id'],
                    // engine contract: flat list, nothing collapses
                    'ancestors' => [],
                    'collapsible' => false,
                ];
            }
        }

        $budgets = Budget::query()
            ->orderByDesc('is_primary')->orderByDesc('fiscal_year_start')->orderBy('name')
            ->get(['id', 'name', 'is_primary'])
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name.($b->is_primary ? ' (primary)' : '')])
            ->all();

        return view('livewire.budgets.variance', [
            'budget' => $budget,
            'rows' => $rows,
            'budgets' => $budgets,
            'fromLabel' => $fromLabel,
            'toLabel' => $toLabel,
            // Net position: budgeted (revenue − expense) vs actual (revenue − expense). Favorable
            // when actual net ≥ budgeted net. This is a meaningful single figure; a raw sum across
            // income + expense is not.
            'totals' => [
                'target' => BalanceService::money($revT - $expT),
                'actual' => BalanceService::money($revA - $expA),
                'variance' => $this->signed(($revA - $expA) - ($revT - $expT)),
                'favorable' => ($revA - $expA) >= ($revT - $expT),
            ],
        ]);
    }

    /** A signed rupee string (+ for over-actual, − for under), magnitude via money(). */
    private function signed(int $paise): string
    {
        $sign = $paise > 0 ? '+' : ($paise < 0 ? '−' : '');

        return $sign.BalanceService::money($paise);
    }
}
