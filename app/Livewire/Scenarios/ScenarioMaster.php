<?php

namespace App\Livewire\Scenarios;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Services\ScenarioService;
use Livewire\Component;

/**
 * Phase 15C — the Scenario Master: create scenarios, toggle them active/inactive, and
 * typed-delete them (which also removes their provisional vouchers). A scenario is a named
 * set of what-if vouchers, excluded from the real books until promoted via the Manager.
 */
class ScenarioMaster extends Component
{
    use GuardsActiveCompany;

    public string $newName = '';
    public string $newDescription = '';
    public string $flash = '';

    /** id => typed confirmation text for the typed-delete guard. */
    public array $confirmName = [];

    public function create(ScenarioService $scenarios): void
    {
        $this->validate([
            'newName' => 'required|string|min:2',
        ]);

        $scenario = $scenarios->create(
            $this->newName,
            $this->newDescription !== '' ? $this->newDescription : null,
            auth()->id()
        );

        $name = $scenario->name;
        $this->reset('newName', 'newDescription');
        $this->flash = 'Scenario "'.$name.'" created.';
    }

    public function setActive(int $id, bool $active, ScenarioService $scenarios): void
    {
        $scenario = $scenarios->find($id);
        if ($scenario === null) {
            return;
        }

        $scenarios->setActive($scenario, $active);
        $this->flash = $active
            ? 'Scenario "'.$scenario->name.'" activated.'
            : 'Scenario "'.$scenario->name.'" deactivated.';
    }

    public function remove(int $id, ScenarioService $scenarios): void
    {
        $scenario = $scenarios->find($id);
        if ($scenario === null) {
            return;
        }

        $typed = trim((string) ($this->confirmName[$id] ?? ''));
        if ($typed !== trim($scenario->name)) {
            $this->flash = 'Type the exact scenario name to confirm deletion.';

            return;
        }

        $count = $scenarios->delete($scenario);
        unset($this->confirmName[$id]);
        $this->flash = "Deleted scenario and its {$count} provisional voucher(s).";
    }

    public function render(ScenarioService $scenarios)
    {
        $rows = $scenarios->allScenarios()->map(fn ($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'description' => $s->description,
            'active' => (bool) $s->is_active,
            'vouchers' => $s->voucherCount(),
            'created' => $s->created_at?->format('d-M-Y'),
        ])->all();

        return view('livewire.scenarios.master', ['rows' => $rows]);
    }
}
