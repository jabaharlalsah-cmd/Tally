<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Livewire\Concerns\CreatesStockGroups;
use App\Models\StockGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Stock Groups master (Phase 6A) — hierarchical, mirroring GroupWorkspace.
 * Create (single + multiple), Display, Alter (with acyclic re-parent check),
 * Delete. The CreatesStockGroups concern also powers inline Alt+C from the Stock
 * Item form.
 */
class StockGroupWorkspace extends Component
{
    use GuardsActiveCompany;
    use CreatesStockGroups;

    // Single create
    public string $name = '';
    public ?string $alias = null;
    public ?int $parent_id = null;
    public string $parent_label = '';

    // Multiple create
    public ?int $multi_parent_id = null;
    public string $multi_parent_label = '';
    public array $rows = [];

    // Alter
    public ?int $alter_id = null;
    public string $g_name = '';
    public ?string $g_alias = null;
    public ?int $g_parent_id = null;
    public string $g_parent_label = '';

    public function mount(): void
    {
        $this->rows = array_fill(0, 10, ['name' => '', 'alias' => '']);
    }

    public function cache(): array
    {
        return StockGroup::orderBy('name')->get()->map->toCache()->all();
    }

    public function saveSingle(): ?array
    {
        $this->validate([
            'name' => ['required', 'string', 'max:191', Rule::unique('stock_groups', 'name')->where('company_id', \App\Support\ActiveCompany::check())],
            'parent_id' => ['nullable', 'integer', Rule::exists('stock_groups', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
        ], [], ['parent_id' => 'under']);

        $group = $this->persistStockGroup(trim($this->name), $this->alias ? trim($this->alias) : null, $this->parent_id);

        $this->reset('name', 'alias', 'parent_id', 'parent_label');

        return $group->toCache();
    }

    public function saveMulti(): array
    {
        $this->validate(['multi_parent_id' => ['nullable', 'integer', Rule::exists('stock_groups', 'id')->where('company_id', \App\Support\ActiveCompany::check())]], [], ['multi_parent_id' => 'under']);

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
            if (StockGroup::whereRaw('LOWER(name) = ?', [$key])->exists()) {
                $v->errors()->add("rows.$i.name", "“{$rawName}” already exists.");
            }
            $seen[$key] = true;
        }
        if (empty($seen)) {
            $v->errors()->add('rows.0.name', 'Enter at least one stock group name.');
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
                $group = $this->persistStockGroup($rawName, ($row['alias'] ?? '') !== '' ? trim($row['alias']) : null, $this->multi_parent_id);
                $made[] = $group->toCache();
            }

            return $made;
        });

        $this->reset('multi_parent_id', 'multi_parent_label');
        $this->rows = array_fill(0, 10, ['name' => '', 'alias' => '']);

        return $created;
    }

    public function loadForAlter(int $id): void
    {
        $g = StockGroup::findOrFail($id);
        $this->alter_id = $g->id;
        $this->g_name = $g->name;
        $this->g_alias = $g->alias;
        $this->g_parent_id = $g->parent_id;
        $this->g_parent_label = $g->parent?->name ?? '';
        $this->resetErrorBag();
    }

    public function saveAlter(): ?array
    {
        $g = StockGroup::findOrFail($this->alter_id);

        $banned = $this->descendantIds($g->id);
        $banned[] = $g->id;

        $this->validate([
            'g_name' => ['required', 'string', 'max:191', Rule::unique('stock_groups', 'name')->where('company_id', \App\Support\ActiveCompany::check())->ignore($g->id)],
            'g_parent_id' => ['nullable', 'integer', Rule::exists('stock_groups', 'id')->where('company_id', \App\Support\ActiveCompany::check()), Rule::notIn($banned)],
        ], [
            'g_parent_id.not_in' => 'A stock group cannot be placed under itself or one of its own sub-groups.',
        ], ['g_parent_id' => 'under', 'g_name' => 'name']);

        $g->update([
            'name' => trim($this->g_name),
            'alias' => $this->g_alias ? trim($this->g_alias) : null,
            'parent_id' => $this->g_parent_id,
        ]);

        return $g->fresh()->toCache();
    }

    public function deleteMaster(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $g = StockGroup::lockForUpdate()->find($id);
            if (! $g) {
                return ['ok' => false, 'message' => 'Stock group not found.'];
            }
            if (StockGroup::where('parent_id', $g->id)->exists() || \App\Models\StockItem::where('stock_group_id', $g->id)->exists()) {
                return ['ok' => false, 'message' => 'Group has sub-groups or stock items — cannot delete.'];
            }
            $g->delete();

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
            $children = StockGroup::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
    }

    public function render()
    {
        return view('livewire.inventory.stock-group-workspace');
    }
}
