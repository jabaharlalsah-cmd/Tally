<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Livewire\Concerns\TogglesMasterActive;
use App\Livewire\Concerns\CreatesGroups;
use App\Models\AccountGroup;
use App\Models\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Component;

class GroupWorkspace extends Component
{
    use GuardsActiveCompany;
    use TogglesMasterActive;
    use CreatesGroups;

    // Single create
    public string $name = '';
    public ?string $alias = null;
    public ?int $parent_id = null;
    public string $parent_label = '';
    public string $nature = 'Assets';

    // Multiple create
    public ?int $multi_parent_id = null;
    public string $multi_parent_label = '';
    public string $multi_nature = 'Assets';
    /** @var array<int,array{name:string,alias:?string}> */
    public array $rows = [];

    // Alter
    public ?int $alter_id = null;
    public string $a_name = '';
    public ?string $a_alias = null;
    public ?int $a_parent_id = null;
    public string $a_parent_label = '';
    public string $a_nature = 'Assets';
    public bool $a_is_reserved = false;
    public bool $a_is_primary = false;

    public function mount(): void
    {
        $this->rows = array_fill(0, 10, ['name' => '', 'alias' => '']);
    }

    /** Full masters snapshot for the client-side cache/pickers. */
    public function mastersCache(): array
    {
        return [
            'groups' => AccountGroup::orderBy('name')->get()->map->toCache()->all(),
            'ledgers' => Ledger::with('group')->orderBy('name')->get()->map->toCache()->all(),
        ];
    }

    public function saveSingle(): ?array
    {
        $this->validate([
            'name' => ['required', 'string', 'max:191', Rule::unique('account_groups', 'name')->where('company_id', \App\Support\ActiveCompany::check())],
            'parent_id' => ['nullable', 'integer', Rule::exists('account_groups', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
            'nature' => [Rule::requiredIf(fn () => $this->parent_id === null), Rule::in(['Assets', 'Liabilities', 'Income', 'Expenses'])],
        ], [], ['parent_id' => 'under']);

        $group = $this->persistGroup(
            trim($this->name),
            $this->alias ? trim($this->alias) : null,
            $this->parent_id,
            $this->nature,
        );

        $this->reset('name', 'alias', 'parent_id', 'parent_label');
        $this->nature = 'Assets';

        return $group->toCache();
    }

    public function saveMulti(): array
    {
        $data = [
            'multi_parent_id' => $this->multi_parent_id,
            'multi_nature' => $this->multi_nature,
        ];
        Validator::make($data, [
            'multi_parent_id' => ['nullable', 'integer', Rule::exists('account_groups', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
            'multi_nature' => [Rule::requiredIf(fn () => $this->multi_parent_id === null), Rule::in(['Assets', 'Liabilities', 'Income', 'Expenses'])],
        ], [], ['multi_parent_id' => 'under'])->validate();

        // Collect filled rows and validate names (unique in DB + within batch).
        $seen = [];
        $v = Validator::make([], []);
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
            if (AccountGroup::whereRaw('LOWER(name) = ?', [$key])->exists()) {
                $v->errors()->add("rows.$i.name", "“{$rawName}” already exists.");
            }
            $seen[$key] = true;
        }
        if (empty($seen)) {
            $v->errors()->add('rows.0.name', 'Enter at least one group name.');
        }
        if ($v->errors()->isNotEmpty()) {
            throw new \Illuminate\Validation\ValidationException($v);
        }

        $created = DB::transaction(function () {
            $made = [];
            foreach ($this->rows as $row) {
                $rawName = trim((string) ($row['name'] ?? ''));
                if ($rawName === '') {
                    continue;
                }
                $group = $this->persistGroup(
                    $rawName,
                    ($row['alias'] ?? '') !== '' ? trim($row['alias']) : null,
                    $this->multi_parent_id,
                    $this->multi_nature,
                );
                $made[] = $group->toCache();
            }

            return $made;
        });

        $this->reset('multi_parent_id', 'multi_parent_label');
        $this->multi_nature = 'Assets';
        $this->rows = array_fill(0, 10, ['name' => '', 'alias' => '']);

        return $created;
    }

    public function loadForAlter(int $id): void
    {
        $g = AccountGroup::findOrFail($id);
        $this->alter_id = $g->id;
        $this->a_name = $g->name;
        $this->a_alias = $g->alias;
        $this->a_parent_id = $g->parent_id;
        $this->a_parent_label = $g->parent?->name ?? '';
        $this->a_nature = $g->nature;
        $this->a_is_reserved = (bool) $g->is_reserved;
        $this->a_is_primary = $g->parent_id === null;
        $this->resetErrorBag();
    }

    public function saveAlter(): ?array
    {
        $g = AccountGroup::findOrFail($this->alter_id);

        // Reserved groups: display only — core fields are locked.
        if ($g->is_reserved) {
            return $g->toCache();
        }

        // Prevent cyclic re-parenting (parent cannot be self or a descendant).
        $banned = $this->descendantIds($g->id);
        $banned[] = $g->id;

        $this->validate([
            'a_name' => ['required', 'string', 'max:191', Rule::unique('account_groups', 'name')->where('company_id', \App\Support\ActiveCompany::check())->ignore($g->id)],
            'a_parent_id' => ['nullable', 'integer', Rule::exists('account_groups', 'id')->where('company_id', \App\Support\ActiveCompany::check()), Rule::notIn($banned)],
            'a_nature' => [Rule::requiredIf(fn () => $this->a_parent_id === null), Rule::in(['Assets', 'Liabilities', 'Income', 'Expenses'])],
        ], [
            'a_parent_id.not_in' => 'A group cannot be placed under itself or one of its own sub-groups.',
        ], ['a_parent_id' => 'under', 'a_name' => 'name']);

        $parent = $this->a_parent_id ? AccountGroup::find($this->a_parent_id) : null;
        $newNature = $parent ? $parent->nature : $this->a_nature;

        $g->update([
            'name' => trim($this->a_name),
            'alias' => $this->a_alias ? trim($this->a_alias) : null,
            'parent_id' => $parent?->id,
            'nature' => $newNature,
            'is_primary' => $parent === null,
        ]);

        // Cascade inherited nature to the whole subtree.
        $this->cascadeNature($g->id, $newNature);

        return $g->fresh()->toCache();
    }

    public function deleteMaster(int $id): array
    {
        // Atomic: re-check children/ledgers and delete inside one transaction so a
        // concurrent "create child" can't race the guard. The parent_id FK is also
        // restrictOnDelete at the DB level as a hard backstop against orphaning.
        return DB::transaction(function () use ($id) {
            $g = AccountGroup::lockForUpdate()->find($id);
            if (! $g) {
                return ['ok' => false, 'message' => 'Group not found.'];
            }
            if ($g->is_reserved) {
                return ['ok' => false, 'message' => 'Reserved group cannot be deleted.'];
            }
            if (AccountGroup::where('parent_id', $g->id)->exists() || Ledger::where('group_id', $g->id)->exists()) {
                return ['ok' => false, 'message' => 'Group has sub-groups or ledgers — cannot delete.'];
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
            $children = AccountGroup::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
    }

    private function cascadeNature(int $id, string $nature): void
    {
        $children = AccountGroup::where('parent_id', $id)->get();
        foreach ($children as $child) {
            $child->update(['nature' => $nature]);
            $this->cascadeNature($child->id, $nature);
        }
        // Ledgers derive their nature from the group at read-time — nothing to persist.
    }

    public function render()
    {
        return view('livewire.group-workspace');
    }

    /** The model TogglesMasterActive retires and restores. */
    protected function masterModelClass(): string
    {
        return \App\Models\AccountGroup::class;
    }
}
