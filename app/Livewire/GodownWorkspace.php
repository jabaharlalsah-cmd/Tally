<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Livewire\Concerns\TogglesMasterActive;
use App\Models\Godown;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Godowns / Locations master (Phase 6A) — hierarchical. "Main Location" is
 * reserved (non-deletable). Create (single + multiple), Display, Alter (with
 * acyclic re-parent check), Delete.
 */
class GodownWorkspace extends Component
{
    use GuardsActiveCompany;
    use TogglesMasterActive;

    // Single create
    public string $name = '';
    public ?int $parent_id = null;
    public string $parent_label = '';

    // Multiple create
    public ?int $multi_parent_id = null;
    public string $multi_parent_label = '';
    public array $rows = [];

    // Alter
    public ?int $alter_id = null;
    public string $d_name = '';
    public ?int $d_parent_id = null;
    public string $d_parent_label = '';
    public bool $d_is_reserved = false;

    public function mount(): void
    {
        $this->rows = array_fill(0, 10, ['name' => '']);
    }

    public function cache(): array
    {
        return Godown::orderBy('name')->get()->map->toCache()->all();
    }

    public function saveSingle(): ?array
    {
        $this->validate([
            'name' => ['required', 'string', 'max:191', Rule::unique('godowns', 'name')->where('company_id', \App\Support\ActiveCompany::check())],
            'parent_id' => ['nullable', 'integer', Rule::exists('godowns', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
        ], [], ['parent_id' => 'under']);

        $godown = Godown::create(['name' => trim($this->name), 'parent_id' => $this->parent_id]);

        $this->reset('name', 'parent_id', 'parent_label');

        return $godown->toCache();
    }

    public function saveMulti(): array
    {
        $this->validate(['multi_parent_id' => ['nullable', 'integer', Rule::exists('godowns', 'id')->where('company_id', \App\Support\ActiveCompany::check())]], [], ['multi_parent_id' => 'under']);

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
            if (Godown::whereRaw('LOWER(name) = ?', [$key])->exists()) {
                $v->errors()->add("rows.$i.name", "“{$rawName}” already exists.");
            }
            $seen[$key] = true;
        }
        if (empty($seen)) {
            $v->errors()->add('rows.0.name', 'Enter at least one godown name.');
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
                $made[] = Godown::create(['name' => $rawName, 'parent_id' => $this->multi_parent_id])->toCache();
            }

            return $made;
        });

        $this->reset('multi_parent_id', 'multi_parent_label');
        $this->rows = array_fill(0, 10, ['name' => '']);

        return $created;
    }

    public function loadForAlter(int $id): void
    {
        $d = Godown::findOrFail($id);
        $this->alter_id = $d->id;
        $this->d_name = $d->name;
        $this->d_parent_id = $d->parent_id;
        $this->d_parent_label = $d->parent?->name ?? '';
        $this->d_is_reserved = (bool) $d->is_reserved;
        $this->resetErrorBag();
    }

    public function saveAlter(): ?array
    {
        $d = Godown::findOrFail($this->alter_id);

        $banned = $this->descendantIds($d->id);
        $banned[] = $d->id;

        $this->validate([
            'd_name' => ['required', 'string', 'max:191', Rule::unique('godowns', 'name')->where('company_id', \App\Support\ActiveCompany::check())->ignore($d->id)],
            'd_parent_id' => ['nullable', 'integer', Rule::exists('godowns', 'id')->where('company_id', \App\Support\ActiveCompany::check()), Rule::notIn($banned)],
        ], [
            'd_parent_id.not_in' => 'A godown cannot be placed under itself or one of its own sub-godowns.',
        ], ['d_parent_id' => 'under', 'd_name' => 'name']);

        $d->update([
            'name' => trim($this->d_name),
            // A reserved godown keeps its top-level position.
            'parent_id' => $d->is_reserved ? null : $this->d_parent_id,
        ]);

        return $d->fresh()->toCache();
    }

    public function deleteMaster(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $d = Godown::lockForUpdate()->find($id);
            if (! $d) {
                return ['ok' => false, 'message' => 'Godown not found.'];
            }
            if ($d->is_reserved) {
                return ['ok' => false, 'message' => 'Main Location is reserved and cannot be deleted.'];
            }
            if (Godown::where('parent_id', $d->id)->exists() || \App\Models\StockItem::where('opening_godown_id', $d->id)->exists() || \App\Models\StockEntry::where('godown_id', $d->id)->exists()) {
                return ['ok' => false, 'message' => 'Godown has sub-godowns or stock — cannot delete.'];
            }
            $d->delete();

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
            $children = Godown::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
    }

    public function render()
    {
        return view('livewire.inventory.godown-workspace');
    }

    /** The model TogglesMasterActive retires and restores. */
    protected function masterModelClass(): string
    {
        return \App\Models\Godown::class;
    }
}
