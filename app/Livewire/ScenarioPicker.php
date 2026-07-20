<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Services\ScenarioService;
use Livewire\Component;

/**
 * Phase 15C — the scenario picker + inclusion banner shown on EVERY report.
 *
 * Toggling a scenario writes the selection to the session (company-scoped, validated) and reloads
 * the page: the outer report re-runs its request, and its service reads resolve the new selection
 * through {@see \App\Support\ScenarioContext} — no report component needs to know this exists. When
 * nothing is selected the session key is absent, so every report is byte-identical to pre-15C.
 */
class ScenarioPicker extends Component
{
    use GuardsActiveCompany;

    /** @var int[] currently-selected scenario ids */
    public array $selected = [];

    /** @var array<int,array{id:int,name:string}> the active scenarios to offer */
    public array $options = [];

    public function mount(ScenarioService $scenarios): void
    {
        $this->options = $scenarios->activeScenarios()
            ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])
            ->all();
        $this->selected = $scenarios->sessionSelected();
    }

    public function toggle(int $id, ScenarioService $scenarios): void
    {
        $set = in_array($id, $this->selected, true)
            ? array_values(array_filter($this->selected, fn ($x) => $x !== $id))
            : array_merge($this->selected, [$id]);

        $this->selected = $scenarios->setSessionSelected($set);
        $this->reloadReport();
    }

    public function clearAll(ScenarioService $scenarios): void
    {
        $scenarios->clearSession();
        $this->selected = [];
        $this->reloadReport();
    }

    /**
     * Recompute the outer report with the new context. A full reload re-runs the report request,
     * whose service reads now resolve the updated session selection (query-string period/as-of
     * survive the reload; there is no in-place recompute because the picker is not the report).
     */
    private function reloadReport(): void
    {
        $this->js('window.location.reload()');
    }

    public function render(ScenarioService $scenarios)
    {
        return view('livewire.scenario-picker', [
            'labels' => $scenarios->selectedLabels($this->selected),
        ]);
    }
}
