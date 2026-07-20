<?php

namespace App\Livewire\Budgets;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\Budget;
use Livewire\Component;

/**
 * Phase 15A — the Budget List: every budget for the active company, with mark-primary and
 * delete. Company scoping is transparent (Budget uses BelongsToCompany), so this only ever
 * shows / touches the active company's budgets.
 */
class BudgetList extends Component
{
    use GuardsActiveCompany;

    public string $flash = '';

    /** Make one budget the sole primary (clears the flag on all others in this company). */
    public function markPrimary(int $id): void
    {
        Budget::query()->findOrFail($id)->makePrimary();
        $this->flash = 'Primary budget updated.';
    }

    /** Delete a budget (cascade removes its lines, periods and revisions). */
    public function remove(int $id): void
    {
        Budget::query()->whereKey($id)->delete();
        $this->flash = 'Budget deleted.';
    }

    public function render()
    {
        $budgets = Budget::query()
            ->withCount('lines')
            ->orderByDesc('is_primary')
            ->orderByDesc('fiscal_year_start')
            ->orderBy('name')
            ->get()
            ->map(fn (Budget $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'fy' => $b->fyLabel(),
                'lines' => $b->lines_count,
                'is_primary' => (bool) $b->is_primary,
                'created' => $b->created_at?->format('d-M-Y'),
            ])->all();

        return view('livewire.budgets.list', ['budgets' => $budgets]);
    }
}
