<?php

namespace App\Livewire\Budgets;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\AccountGroup;
use App\Models\Budget;
use App\Models\Ledger;
use App\Models\Voucher;
use App\Services\BudgetService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * Phase 15A — the Budget Editor grid. Two modes:
 *   • create (budgetId null)   — name, FY, add ledger/group lines, per-month targets, save new.
 *   • revise (budgetId set)     — an effective date + new targets for the EXISTING lines; the
 *                                 service locks history before the boundary and rewrites forward.
 *
 * Each line carries an allocation method (even / custom / seasonal). "months" holds the 12
 * fiscal-month amounts shown in the grid; on save the create/revise service is handed exactly
 * the method contract it expects (even → annual, custom → month amounts, seasonal → the template
 * weights applied to the annual).
 */
class BudgetEditor extends Component
{
    use GuardsActiveCompany;

    public ?int $budgetId = null;
    public bool $reviseMode = false;

    public string $name = '';
    public int $fyStart;
    public bool $isPrimary = false;
    public string $effectiveFrom = '';
    public string $revisionNote = '';

    /** @var array<int,array{target_kind:string,target_id:int,name:string,nature:?string,allocation_method:string,annual:string,months:array<int,string>,line_id:?int}> */
    public array $lines = [];

    public string $flash = '';

    // Picker sinks (event-driven add).
    public function mount(?int $budget = null, bool $revise = false): void
    {
        $this->fyStart = Voucher::fyStartFor(now());

        if ($budget) {
            $model = Budget::query()->with('lines.ledger.group', 'lines.accountGroup')->findOrFail($budget);
            $this->budgetId = $model->id;
            $this->reviseMode = $revise;
            $this->name = $model->name;
            $this->fyStart = (int) $model->fiscal_year_start;
            $this->isPrimary = (bool) $model->is_primary;
            $this->effectiveFrom = now()->toDateString();

            $svc = app(BudgetService::class);
            foreach ($model->lines as $line) {
                $months = array_map(fn ($r) => $this->fmt($r), $svc->lineCurrentMonths($line));
                $this->lines[] = [
                    'target_kind' => $line->isGroupLine() ? 'group' : 'ledger',
                    'target_id' => (int) ($line->isGroupLine() ? $line->account_group_id : $line->ledger_id),
                    'name' => $line->targetName(),
                    'nature' => $line->nature(),
                    'allocation_method' => $line->allocation_method,
                    'annual' => $this->fmt(array_sum(array_map('floatval', $months))),
                    'months' => $months,
                    'line_id' => $line->id,
                ];
            }
        }
    }

    #[Computed]
    public function fyLabel(): string
    {
        return Voucher::fyLabel($this->fyStart);
    }

    #[Computed]
    public function monthLabels(): array
    {
        $open = Voucher::fyOpenFor($this->fyStart);
        $labels = [];
        for ($i = 0; $i < 12; $i++) {
            $labels[] = $open->copy()->addMonthsNoOverflow($i)->format('M');
        }

        return $labels;
    }

    public function setFy(int $year): void
    {
        if ($this->reviseMode) {
            return; // an existing budget's FY is fixed
        }
        $this->fyStart = max(2000, min(2100, $year));
    }

    // ── line management ──────────────────────────────────────────────────────────

    public function addLedger(int $id): void
    {
        if ($this->reviseMode) {
            return;
        }
        $ledger = Ledger::query()->with('group')->find($id);
        if (! $ledger) {
            return;
        }
        foreach ($this->lines as $ln) {
            if ($ln['target_kind'] === 'ledger' && (int) $ln['target_id'] === $id) {
                return; // no duplicate
            }
        }
        $this->lines[] = $this->blankLine('ledger', $ledger->id, $ledger->name, $ledger->group?->nature);
    }

    public function addGroup(int $id): void
    {
        if ($this->reviseMode) {
            return;
        }
        $group = AccountGroup::query()->find($id);
        if (! $group) {
            return;
        }
        foreach ($this->lines as $ln) {
            if ($ln['target_kind'] === 'group' && (int) $ln['target_id'] === $id) {
                return;
            }
        }
        $this->lines[] = $this->blankLine('group', $group->id, $group->name, $group->nature);
    }

    private function blankLine(string $kind, int $id, string $name, ?string $nature): array
    {
        return [
            'target_kind' => $kind,
            'target_id' => $id,
            'name' => $name,
            'nature' => $nature,
            'allocation_method' => 'even',
            'annual' => '0.00',
            'months' => array_fill(0, 12, '0.00'),
            'line_id' => null,
        ];
    }

    public function removeLine(int $i): void
    {
        if ($this->reviseMode) {
            return;
        }
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    /** Recompute a line's month cells from its method + annual (even / seasonal), or sync annual (custom). */
    public function recompute(int $i): void
    {
        if (! isset($this->lines[$i])) {
            return;
        }
        $line = &$this->lines[$i];
        $method = $line['allocation_method'];
        $annual = (float) $line['annual'];

        if ($method === 'even') {
            // Round the per-month figure first, then let the last cell absorb the remainder, so the
            // 12 cells sum EXACTLY to the annual (a later switch to custom then persists the intent).
            $per = round($annual / 12, 2);
            $line['months'] = array_fill(0, 12, $this->fmt($per));
            $line['months'][11] = $this->fmt($annual - $per * 11);
        } elseif ($method === 'seasonal') {
            $w = BudgetService::SEASONAL_TEMPLATE;
            $total = array_sum($w);
            $acc = 0.0;
            for ($m = 0; $m < 12; $m++) {
                $amt = round($annual * $w[$m] / $total, 2);
                $line['months'][$m] = $this->fmt($amt);
                $acc += $amt;
            }
            $line['months'][11] = $this->fmt((float) $line['months'][11] + ($annual - $acc));
        } else { // custom — annual follows the sum of the cells
            $line['annual'] = $this->fmt(array_sum(array_map('floatval', $line['months'])));
        }
    }

    // ── save ──────────────────────────────────────────────────────────────────────

    public function save()
    {
        $this->name = trim($this->name);
        $this->resetErrorBag();

        if ($this->lines === []) {
            $this->addError('lines', 'Add at least one target line.');

            return null;
        }
        if (! $this->reviseMode && $this->name === '') {
            $this->addError('name', 'Give the budget a name.');

            return null;
        }

        try {
            $svc = app(BudgetService::class);
            $userId = Auth::guard('tenant')->id();

            if ($this->reviseMode && $this->budgetId) {
                $budget = Budget::query()->findOrFail($this->budgetId);
                $newLines = [];
                foreach ($this->lines as $ln) {
                    if (empty($ln['line_id'])) {
                        continue;
                    }
                    $newLines[(int) $ln['line_id']] = $this->serviceLine($ln);
                }
                $svc->reviseBudget($budget, $newLines, Carbon::parse($this->effectiveFrom), $userId, $this->revisionNote ?: null);

                return redirect()->route('reports.budget-list');
            }

            $lines = array_map(fn ($ln) => $this->serviceLine($ln, withTarget: true), $this->lines);
            $svc->createBudget($this->name, $this->fyStart, $lines, $userId, $this->isPrimary);

            return redirect()->route('reports.budget-list');
        } catch (Throwable $e) {
            $this->addError('save', $e->getMessage());

            return null;
        }
    }

    /** Translate one editor line into the BudgetService line/allocation contract. */
    private function serviceLine(array $ln, bool $withTarget = false): array
    {
        $entry = [
            'annual_target' => (float) $ln['annual'],
            'allocation_method' => $ln['allocation_method'],
        ];
        if ($withTarget) {
            if ($ln['target_kind'] === 'group') {
                $entry['account_group_id'] = (int) $ln['target_id'];
            } else {
                $entry['ledger_id'] = (int) $ln['target_id'];
            }
        }
        if ($ln['allocation_method'] === 'custom') {
            $entry['months'] = array_map('floatval', $ln['months']);
        } elseif ($ln['allocation_method'] === 'seasonal') {
            $entry['months'] = BudgetService::SEASONAL_TEMPLATE; // weights, resolved by the service
        }

        return $entry;
    }

    private function fmt(float $n): string
    {
        return number_format($n, 2, '.', '');
    }

    public function render()
    {
        return view('livewire.budgets.editor', [
            'masters' => [
                'groups' => AccountGroup::query()->orderBy('name')->get()->map->toCache()->all(),
                'ledgers' => Ledger::query()->with('group')->orderBy('name')->get()->map->toCache()->all(),
            ],
        ]);
    }
}
