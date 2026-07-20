<?php

namespace App\Livewire\Concerns;

use App\Models\AccountGroup;
use Illuminate\Validation\Rule;

/**
 * Shared group-creation logic used by both master workspaces:
 *  - GroupWorkspace (its own single/multiple create),
 *  - LedgerWorkspace (inline Alt+C "create group" from the Under picker).
 * Provides the inline quick-create form (qg_*) and the canonical persistence
 * helper so nature/is_primary are computed identically everywhere.
 */
trait CreatesGroups
{
    // Inline quick-create-group form (Alt+C from a picker)
    public string $qg_name = '';
    public ?string $qg_alias = null;
    public ?int $qg_parent_id = null;
    public string $qg_parent_label = '';
    public string $qg_nature = 'Assets';

    public function saveQuickGroup(): ?array
    {
        $this->validate([
            'qg_name' => ['required', 'string', 'max:191', Rule::unique('account_groups', 'name')->where('company_id', \App\Support\ActiveCompany::check())],
            'qg_parent_id' => ['nullable', 'integer', Rule::exists('account_groups', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
            'qg_nature' => ['required', Rule::in(['Assets', 'Liabilities', 'Income', 'Expenses'])],
        ], [], [
            'qg_name' => 'name',
            'qg_parent_id' => 'under',
        ]);

        $group = $this->persistGroup(
            trim($this->qg_name),
            $this->qg_alias ? trim($this->qg_alias) : null,
            $this->qg_parent_id,
            $this->qg_nature,
        );

        $this->reset('qg_name', 'qg_alias', 'qg_parent_id', 'qg_parent_label');
        $this->qg_nature = 'Assets';

        return $group->toCache();
    }

    /**
     * Canonical group persistence. nature/is_primary are derived from the
     * parent so sub-groups always inherit their root primary's nature.
     */
    protected function persistGroup(string $name, ?string $alias, ?int $parentId, string $natureIfPrimary): AccountGroup
    {
        $parent = $parentId ? AccountGroup::find($parentId) : null;

        return AccountGroup::create([
            'name' => $name,
            'alias' => $alias,
            'parent_id' => $parent?->id,
            'nature' => $parent ? $parent->nature : $natureIfPrimary,
            'is_primary' => $parent === null,
            'is_reserved' => false,
        ]);
    }
}
