<?php

namespace App\Livewire\Concerns;

use App\Models\StockGroup;
use Illuminate\Validation\Rule;

/**
 * Shared stock-group creation, used by StockGroupWorkspace (its own create) and
 * StockItemWorkspace (inline Alt+C "create group" from the Under picker). Mirrors
 * CreatesGroups so the persistence path is identical everywhere.
 */
trait CreatesStockGroups
{
    // Inline quick-create-stock-group form (Alt+C from the Stock Item Under picker)
    public string $qsg_name = '';
    public ?string $qsg_alias = null;
    public ?int $qsg_parent_id = null;
    public string $qsg_parent_label = '';

    public function saveQuickStockGroup(): ?array
    {
        $this->validate([
            'qsg_name' => ['required', 'string', 'max:191', Rule::unique('stock_groups', 'name')->where('company_id', \App\Support\ActiveCompany::check())],
            'qsg_parent_id' => ['nullable', 'integer', Rule::exists('stock_groups', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
        ], [], ['qsg_name' => 'name', 'qsg_parent_id' => 'under']);

        $group = $this->persistStockGroup(
            trim($this->qsg_name),
            $this->qsg_alias ? trim($this->qsg_alias) : null,
            $this->qsg_parent_id,
        );

        $this->reset('qsg_name', 'qsg_alias', 'qsg_parent_id', 'qsg_parent_label');

        return $group->toCache();
    }

    protected function persistStockGroup(string $name, ?string $alias, ?int $parentId): StockGroup
    {
        return StockGroup::create([
            'name' => $name,
            'alias' => $alias,
            'parent_id' => $parentId,
        ]);
    }
}
