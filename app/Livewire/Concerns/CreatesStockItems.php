<?php

namespace App\Livewire\Concerns;

use App\Models\StockItem;
use Illuminate\Validation\Rule;

/**
 * Inline quick-create Stock Item, used by the item-invoice screen (Alt+C on the
 * Stock Item picker). Mirrors CreatesLedgers: a small qsi_* form that persists an
 * item and returns its cache shape so the client can select it without a reload.
 *
 * A stock item created mid-invoice has NO opening stock (opening qty/value = 0) —
 * its cost is built purely from movement. Group and Unit are optional (both are
 * nullable on the item); a new *group* or *unit* must be created from the full
 * Stock Item master, not nested here (bounded inline scope, documented in 6B).
 */
trait CreatesStockItems
{
    public string $qsi_name = '';
    public ?int $qsi_group_id = null;
    public string $qsi_group_label = '';
    public ?int $qsi_unit_id = null;
    public string $qsi_unit_label = '';
    public ?string $qsi_gst_rate = null;
    public ?string $qsi_hsn = null;

    public function saveQuickStockItem(): ?array
    {
        $this->validate([
            'qsi_name' => ['required', 'string', 'max:191', Rule::unique('stock_items', 'name')->where('company_id', \App\Support\ActiveCompany::check())],
            'qsi_group_id' => ['nullable', 'integer', Rule::exists('stock_groups', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
            'qsi_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
            'qsi_gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'qsi_hsn' => ['nullable', 'string', 'max:191'],
        ], [], ['qsi_name' => 'name']);

        $item = StockItem::create([
            'name' => trim($this->qsi_name),
            'stock_group_id' => $this->qsi_group_id,
            'unit_id' => $this->qsi_unit_id,
            'opening_qty' => 0,
            'opening_rate' => 0,
            'opening_value' => 0,
            'gst_rate' => ($this->qsi_gst_rate === null || $this->qsi_gst_rate === '') ? null : (float) $this->qsi_gst_rate,
            'hsn_sac' => $this->qsi_hsn ?: null,
            'costing_method' => 'weighted_average',
        ]);

        $this->reset('qsi_name', 'qsi_group_id', 'qsi_group_label', 'qsi_unit_id', 'qsi_unit_label', 'qsi_gst_rate', 'qsi_hsn');

        return $item->load(['stockGroup', 'unit'])->toCache();
    }
}
