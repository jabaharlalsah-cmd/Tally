<?php

namespace App\Livewire\Budgets;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\Budget;
use App\Services\BalanceService;
use App\Services\BudgetService;
use Carbon\Carbon;
use Livewire\Component;

/**
 * Phase 15A — the single-page Budget Summary: total revenue / expense budget vs actual and
 * projected vs actual net profit, for a chosen budget as of a date (F2). Defaults to the
 * company's primary budget.
 */
class BudgetSummary extends Component
{
    use GuardsActiveCompany;

    public ?int $budgetId = null;
    public ?string $asOf = null;

    public function mount(): void
    {
        $this->budgetId = Budget::primaryForCompany()?->id;
        $this->asOf = now()->toDateString();
    }

    public function render()
    {
        $budget = $this->budgetId
            ? Budget::query()->find($this->budgetId)
            : Budget::primaryForCompany();

        $data = null;
        if ($budget) {
            $data = app(BudgetService::class)->summary($budget, Carbon::parse($this->asOf));
        }

        $budgets = Budget::query()
            ->orderByDesc('is_primary')->orderByDesc('fiscal_year_start')->orderBy('name')
            ->get(['id', 'name', 'is_primary'])
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name.($b->is_primary ? ' (primary)' : '')])
            ->all();

        return view('livewire.budgets.summary', [
            'budget' => $budget,
            'data' => $data,
            'budgets' => $budgets,
            'money' => fn (?int $paise) => $paise === null ? '—' : BalanceService::money((int) $paise),
        ]);
    }
}
