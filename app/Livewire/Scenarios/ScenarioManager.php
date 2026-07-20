<?php

namespace App\Livewire\Scenarios;

use App\Exceptions\ScenarioPromotionException;
use App\Livewire\Concerns\GuardsActiveCompany;
use App\Services\BalanceService;
use App\Services\ScenarioService;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Phase 15C — the Scenario Manager. Selects ONE scenario, lists its provisional vouchers, and
 * promotes it into the real books. Promotion is one-way, irreversible and transactional: the
 * service re-validates every voucher first and rolls the whole thing back on any failure, so a
 * failed promotion changes nothing. The typed "PROMOTE" confirmation is the last guard.
 */
class ScenarioManager extends Component
{
    use GuardsActiveCompany;

    /** Hydrated from the route via the wrapper blade's :scenario-id. */
    public ?int $scenarioId = null;

    public string $flash = '';
    public string $error = '';

    /** Per-voucher reason strings from a failed promotion (empty on success). */
    public array $reasons = [];

    /** The typed confirmation — must equal PROMOTE to proceed. */
    public string $confirm = '';

    public function mount(ScenarioService $scenarios): void
    {
        if ($this->scenarioId === null) {
            $this->scenarioId = $scenarios->activeScenarios()->first()?->id;
        }
    }

    public function updatedScenarioId(): void
    {
        $this->confirm = '';
        $this->flash = '';
        $this->error = '';
        $this->reasons = [];
    }

    public function promote(ScenarioService $scenarios): void
    {
        $this->error = '';
        $this->reasons = [];

        if (trim($this->confirm) !== 'PROMOTE') {
            $this->error = 'Type PROMOTE to confirm — this cannot be undone.';

            return;
        }

        $scenario = $this->scenarioId ? $scenarios->find($this->scenarioId) : null;
        if (! $scenario) {
            $this->error = 'Select a scenario.';

            return;
        }

        try {
            $res = $scenarios->promote($scenario, auth()->id());
            $this->flash = "Promoted {$res['count']} voucher(s) into the real books. This scenario is now closed.";
            $this->confirm = '';
            $this->scenarioId = null;
        } catch (ScenarioPromotionException $e) {
            $this->error = 'Promotion cancelled — nothing changed. Fix these and retry:';
            $this->reasons = $e->reasons;
        }
    }

    public function render(ScenarioService $scenarios)
    {
        $list = $scenarios->activeScenarios()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])
            ->all();

        $selected = $this->scenarioId ? $scenarios->find($this->scenarioId) : null;

        $vouchers = [];
        if ($selected) {
            $vouchers = $selected->vouchers()
                ->with('entries')
                ->orderBy('date')
                ->orderBy('id')
                ->get()
                ->map(function ($v) {
                    $dr = 0;
                    foreach ($v->entries as $e) {
                        if ($e->dr_cr === 'Dr') {
                            $dr += (int) round(((float) $e->amount) * 100);
                        }
                    }

                    return [
                        'id' => $v->id,
                        'date' => Carbon::parse($v->date)->format('d-M-Y'),
                        'type' => $v->type,
                        'number' => $v->number,
                        'narration' => $v->narration,
                        'amount' => $dr,
                    ];
                })
                ->all();
        }

        return view('livewire.scenarios.manager', [
            'scenarios' => $list,
            'vouchers' => $vouchers,
            'selected' => $selected,
            'money' => fn (int $p) => BalanceService::money($p),
            'selectedId' => $this->scenarioId,
        ]);
    }
}
