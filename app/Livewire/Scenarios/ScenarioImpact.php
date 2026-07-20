<?php

namespace App\Livewire\Scenarios;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Services\BalanceService;
use App\Services\ScenarioService;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Phase 15C — Scenario Impact: the real books contrasted against real+scenario, as of a date.
 * Headline figures (net profit, income, expenses, assets, liabilities, stock), a per-ledger
 * closing-delta table you can drill into (Enter → that ledger's vouchers), and the scenario's
 * own provisional voucher list. Everything is computed by {@see ScenarioService::impactReport}
 * under an explicit ScenarioContext, so the "real" column is guaranteed actuals-only.
 */
class ScenarioImpact extends Component
{
    use GuardsActiveCompany;

    public ?int $scenarioId = null;
    public ?string $asOf = null;

    public function mount(ScenarioService $scenarios): void
    {
        if ($this->scenarioId === null) {
            $this->scenarioId = $scenarios->activeScenarios()->first()?->id;
        }
    }

    public function render(ScenarioService $scenarios)
    {
        $list = $scenarios->activeScenarios()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])
            ->values();

        $selected = $this->scenarioId ? $scenarios->find($this->scenarioId) : null;

        $report = null;
        if ($selected) {
            $asOfC = $this->asOf ? Carbon::parse($this->asOf) : null;
            $report = $scenarios->impactReport($selected, $asOfC);
        }

        $money = fn (int $p) => BalanceService::money($p);

        return view('livewire.scenarios.impact', [
            'list' => $list,
            'report' => $report,
            'selected' => $selected,
            'money' => $money,
            'metricLabels' => [
                'net_profit' => 'Net Profit',
                'total_income' => 'Total Income',
                'total_expenses' => 'Total Expenses',
                'total_assets' => 'Total Assets',
                'total_liabilities' => 'Total Liabilities',
                'closing_stock' => 'Closing Stock',
            ],
        ]);
    }
}
