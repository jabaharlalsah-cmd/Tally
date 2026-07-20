<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\CostCentre;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Cost Centre master (Phase 5D) — Create (single + multiple), Display, Alter,
 * Delete. Mirrors the Group/Ledger master conventions but stands alone so the
 * shared masterWorkspace (groups/ledgers) is untouched. A cost centre may sit
 * under a parent cost centre; acyclicity is enforced here (server authority).
 */
class CostCentreWorkspace extends Component
{
    use GuardsActiveCompany;

    // Single create
    public string $name = '';
    public ?int $parent_id = null;
    public string $parent_label = '';

    // Multiple create
    public ?int $multi_parent_id = null;
    public string $multi_parent_label = '';
    /** @var array<int,array{name:string}> */
    public array $rows = [];

    // Alter
    public ?int $alter_id = null;
    public string $a_name = '';
    public ?int $a_parent_id = null;
    public string $a_parent_label = '';

    public function mount(): void
    {
        $this->rows = array_fill(0, 10, ['name' => '']);
    }

    /** Cost-centre snapshot for the client-side cache/pickers. */
    public function costCache(): array
    {
        return CostCentre::orderBy('name')->get()->map->toCache()->all();
    }

    public function saveSingle(): ?array
    {
        $this->validate([
            'name' => ['required', 'string', 'max:191', Rule::unique('cost_centres', 'name')->where('company_id', \App\Support\ActiveCompany::check())],
            'parent_id' => ['nullable', 'integer', Rule::exists('cost_centres', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
        ], [], ['parent_id' => 'under']);

        $cc = CostCentre::create([
            'name' => trim($this->name),
            'parent_id' => $this->parent_id,
        ]);

        $this->reset('name', 'parent_id', 'parent_label');

        return $cc->toCache();
    }

    public function saveMulti(): array
    {
        $this->validate(['multi_parent_id' => ['nullable', 'integer', Rule::exists('cost_centres', 'id')->where('company_id', \App\Support\ActiveCompany::check())]], [], ['multi_parent_id' => 'under']);

        $seen = [];
        $v = \Illuminate\Support\Facades\Validator::make([], []);
        foreach ($this->rows as $i => $row) {
            $rawName = trim((string) ($row['name'] ?? ''));
            if ($rawName === '') {
                continue;
            }
            $key = mb_strtolower($rawName);
            if (isset($seen[$key])) {
                $v->errors()->add("rows.$i.name", "“{$rawName}” is duplicated in this batch.");

                continue;
            }
            if (CostCentre::whereRaw('LOWER(name) = ?', [$key])->exists()) {
                $v->errors()->add("rows.$i.name", "“{$rawName}” already exists.");
            }
            $seen[$key] = true;
        }
        if (empty($seen)) {
            $v->errors()->add('rows.0.name', 'Enter at least one cost centre name.');
        }
        if ($v->errors()->isNotEmpty()) {
            throw new ValidationException($v);
        }

        $created = DB::transaction(function () {
            $made = [];
            foreach ($this->rows as $row) {
                $rawName = trim((string) ($row['name'] ?? ''));
                if ($rawName === '') {
                    continue;
                }
                $cc = CostCentre::create(['name' => $rawName, 'parent_id' => $this->multi_parent_id]);
                $made[] = $cc->toCache();
            }

            return $made;
        });

        $this->reset('multi_parent_id', 'multi_parent_label');
        $this->rows = array_fill(0, 10, ['name' => '']);

        return $created;
    }

    public function loadForAlter(int $id): void
    {
        $cc = CostCentre::findOrFail($id);
        $this->alter_id = $cc->id;
        $this->a_name = $cc->name;
        $this->a_parent_id = $cc->parent_id;
        $this->a_parent_label = $cc->parent?->name ?? '';
        $this->resetErrorBag();
    }

    public function saveAlter(): ?array
    {
        $cc = CostCentre::findOrFail($this->alter_id);

        // Prevent cyclic re-parenting (parent cannot be self or a descendant).
        $banned = $this->descendantIds($cc->id);
        $banned[] = $cc->id;

        $this->validate([
            'a_name' => ['required', 'string', 'max:191', Rule::unique('cost_centres', 'name')->where('company_id', \App\Support\ActiveCompany::check())->ignore($cc->id)],
            'a_parent_id' => ['nullable', 'integer', Rule::exists('cost_centres', 'id')->where('company_id', \App\Support\ActiveCompany::check()), Rule::notIn($banned)],
        ], [
            'a_parent_id.not_in' => 'A cost centre cannot be placed under itself or one of its own sub-centres.',
        ], ['a_parent_id' => 'under', 'a_name' => 'name']);

        $cc->update([
            'name' => trim($this->a_name),
            'parent_id' => $this->a_parent_id,
        ]);

        return $cc->fresh()->toCache();
    }

    public function deleteMaster(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $cc = CostCentre::lockForUpdate()->find($id);
            if (! $cc) {
                return ['ok' => false, 'message' => 'Cost centre not found.'];
            }
            if (CostCentre::where('parent_id', $cc->id)->exists()) {
                return ['ok' => false, 'message' => 'Cost centre has sub-centres — cannot delete.'];
            }
            if (\App\Models\CostAllocation::where('cost_centre_id', $cc->id)->exists()) {
                return ['ok' => false, 'message' => 'Cost centre is used in vouchers — cannot delete.'];
            }
            $cc->delete();

            return ['ok' => true, 'message' => 'Deleted.'];
        });
    }

    /** @return int[] */
    private function descendantIds(int $id): array
    {
        $ids = [];
        $frontier = [$id];
        $guard = 0;
        while ($frontier && $guard++ < 100) {
            $children = CostCentre::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
    }

    public function render()
    {
        return view('livewire.cost-centre-workspace');
    }
}
