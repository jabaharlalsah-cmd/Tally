<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Livewire\Concerns\CreatesStockGroups;
use App\Livewire\Concerns\CreatesUnits;
use App\Models\Godown;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Stock Items master (Phase 6A) — the rich inventory master. Under a Stock Group,
 * measured in a Unit, with an opening balance held in a Godown. The item's own
 * gst_rate/hsn_sac are stored (consumed by 6B). costing_method is fixed to
 * weighted_average this phase. The CreatesStockGroups/CreatesUnits concerns power
 * the inline Alt+C create of a group and a unit from this form.
 */
class StockItemWorkspace extends Component
{
    use GuardsActiveCompany;
    use CreatesStockGroups;
    use CreatesUnits;

    // Single create
    public string $name = '';
    public ?string $alias = null;
    public ?int $stock_group_id = null;
    public string $stock_group_label = '';
    public ?int $unit_id = null;
    public string $unit_label = '';
    public string $opening_qty = '';
    public string $opening_rate = '';
    public string $opening_value = '';
    public ?int $opening_godown_id = null;
    public string $opening_godown_label = '';
    public ?string $gst_rate = null;
    public ?string $hsn_sac = null;
    public ?string $reorder_level = null;
    public string $costing_method = 'weighted_average'; // Phase 13

    // Multiple create — common group/unit/godown + rows of {name, qty, rate}
    public ?int $multi_group_id = null;
    public string $multi_group_label = '';
    public ?int $multi_unit_id = null;
    public string $multi_unit_label = '';
    public array $rows = [];

    // Alter
    public ?int $alter_id = null;
    public string $i_name = '';
    public ?string $i_alias = null;
    public ?int $i_stock_group_id = null;
    public string $i_stock_group_label = '';
    public ?int $i_unit_id = null;
    public string $i_unit_label = '';
    public string $i_opening_qty = '';
    public string $i_opening_rate = '';
    public string $i_opening_value = '';
    public ?int $i_opening_godown_id = null;
    public string $i_opening_godown_label = '';
    public ?string $i_gst_rate = null;
    public ?string $i_hsn_sac = null;
    public ?string $i_reorder_level = null;
    public string $i_costing_method = 'weighted_average'; // Phase 13
    public bool $i_costing_locked = false;                // locked once the item has stock movements

    public function mount(): void
    {
        $main = Godown::where('is_reserved', true)->orderBy('id')->first() ?? Godown::orderBy('id')->first();
        $this->opening_godown_id = $main?->id;
        $this->opening_godown_label = $main?->name ?? '';
        $this->rows = array_fill(0, 10, ['name' => '', 'qty' => '', 'rate' => '']);
    }

    /** All the caches the Stock Item form picks from. */
    public function caches(): array
    {
        return [
            'stockItems' => StockItem::with(['stockGroup', 'unit'])->orderBy('name')->get()->map->toCache()->all(),
            'stockGroups' => StockGroup::orderBy('name')->get()->map->toCache()->all(),
            'units' => Unit::orderBy('name')->get()->map->toCache()->all(),
            'godowns' => Godown::orderBy('name')->get()->map->toCache()->all(),
        ];
    }

    private function rules(string $p): array
    {
        return [
            $p.'name' => ['required', 'string', 'max:191', Rule::unique('stock_items', 'name')->where('company_id', \App\Support\ActiveCompany::check())->ignore($this->alter_id)],
            $p.'stock_group_id' => ['nullable', 'integer', Rule::exists('stock_groups', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
            $p.'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
            $p.'opening_qty' => ['nullable', 'numeric', function ($attr, $value, $fail) use ($p) {
                // Phase 13 — a FIFO/LIFO item's opening must be entered as dated opening purchase
                // vouchers (each its own cost layer), never a master opening balance that has no lot.
                if (in_array($this->{$p.'costing_method'}, ['fifo', 'lifo'], true) && (float) ($value ?: 0) !== 0.0) {
                    $fail(StockItem::FIFO_OPENING_MESSAGE);
                }
            }],
            $p.'opening_rate' => ['nullable', 'numeric', 'min:0'],
            $p.'opening_value' => ['nullable', 'numeric'],
            $p.'opening_godown_id' => ['nullable', 'integer', Rule::exists('godowns', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
            $p.'gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            $p.'hsn_sac' => ['nullable', 'string', 'max:30'],
            $p.'reorder_level' => ['nullable', 'numeric', 'min:0'],
            $p.'costing_method' => ['required', Rule::in(['weighted_average', 'fifo', 'lifo'])],
        ];
    }

    private function payload(string $p): array
    {
        $num = fn ($v) => ($v === null || $v === '') ? 0 : (float) $v;

        return [
            'name' => trim($this->{$p.'name'}),
            'alias' => $this->{$p.'alias'} ? trim($this->{$p.'alias'}) : null,
            'stock_group_id' => $this->{$p.'stock_group_id'},
            'unit_id' => $this->{$p.'unit_id'},
            'opening_qty' => $num($this->{$p.'opening_qty'}),
            'opening_rate' => $num($this->{$p.'opening_rate'}),
            'opening_value' => $num($this->{$p.'opening_value'}),
            'opening_godown_id' => $this->{$p.'opening_godown_id'},
            'gst_rate' => ($this->{$p.'gst_rate'} === null || $this->{$p.'gst_rate'} === '') ? null : (float) $this->{$p.'gst_rate'},
            'hsn_sac' => $this->{$p.'hsn_sac'} ?: null,
            'reorder_level' => ($this->{$p.'reorder_level'} === null || $this->{$p.'reorder_level'} === '') ? null : (float) $this->{$p.'reorder_level'},
            'costing_method' => in_array($this->{$p.'costing_method'}, ['weighted_average', 'fifo', 'lifo'], true) ? $this->{$p.'costing_method'} : 'weighted_average',
        ];
    }

    public function saveSingle(): ?array
    {
        $this->validate($this->rules(''), [], ['stock_group_id' => 'under', 'unit_id' => 'unit']);

        $item = StockItem::create($this->payload(''));

        $godownId = $this->opening_godown_id;
        $godownLabel = $this->opening_godown_label;
        $this->reset('name', 'alias', 'stock_group_id', 'stock_group_label', 'unit_id', 'unit_label', 'opening_qty', 'opening_rate', 'opening_value', 'gst_rate', 'hsn_sac', 'reorder_level', 'costing_method');
        // keep the default godown selected for the next item
        $this->opening_godown_id = $godownId;
        $this->opening_godown_label = $godownLabel;

        return $item->load(['stockGroup', 'unit'])->toCache();
    }

    public function saveMulti(): array
    {
        $this->validate([
            'multi_group_id' => ['nullable', 'integer', Rule::exists('stock_groups', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
            'multi_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
        ], [], ['multi_group_id' => 'under', 'multi_unit_id' => 'unit']);

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
            if (StockItem::whereRaw('LOWER(name) = ?', [$key])->exists()) {
                $v->errors()->add("rows.$i.name", "“{$rawName}” already exists.");
            }
            foreach (['qty', 'rate'] as $c) {
                $val = (string) ($row[$c] ?? '');
                if ($val !== '' && ! is_numeric($val)) {
                    $v->errors()->add("rows.$i.$c", 'Must be a number.');
                }
            }
            $seen[$key] = true;
        }
        if (empty($seen)) {
            $v->errors()->add('rows.0.name', 'Enter at least one stock item name.');
        }
        if ($v->errors()->isNotEmpty()) {
            throw new ValidationException($v);
        }

        $main = $this->opening_godown_id;
        $created = DB::transaction(function () use ($main) {
            $made = [];
            foreach ($this->rows as $row) {
                $rawName = trim((string) ($row['name'] ?? ''));
                if ($rawName === '') {
                    continue;
                }
                $qty = (float) (($row['qty'] ?? '') === '' ? 0 : $row['qty']);
                $rate = (float) (($row['rate'] ?? '') === '' ? 0 : $row['rate']);
                $item = StockItem::create([
                    'name' => $rawName,
                    'stock_group_id' => $this->multi_group_id,
                    'unit_id' => $this->multi_unit_id,
                    'opening_qty' => $qty,
                    'opening_rate' => $rate,
                    'opening_value' => round($qty * $rate, 2),
                    'opening_godown_id' => $main,
                    'costing_method' => 'weighted_average',
                ]);
                $made[] = $item->load(['stockGroup', 'unit'])->toCache();
            }

            return $made;
        });

        $this->reset('multi_group_id', 'multi_group_label', 'multi_unit_id', 'multi_unit_label');
        $this->rows = array_fill(0, 10, ['name' => '', 'qty' => '', 'rate' => '']);

        return $created;
    }

    public function loadForAlter(int $id): void
    {
        $it = StockItem::with(['stockGroup', 'unit', 'openingGodown'])->findOrFail($id);
        $this->alter_id = $it->id;
        $this->i_name = $it->name;
        $this->i_alias = $it->alias;
        $this->i_stock_group_id = $it->stock_group_id;
        $this->i_stock_group_label = $it->stockGroup?->name ?? '';
        $this->i_unit_id = $it->unit_id;
        $this->i_unit_label = $it->unit?->name ?? '';
        $this->i_opening_qty = (string) (float) $it->opening_qty;
        $this->i_opening_rate = (string) (float) $it->opening_rate;
        $this->i_opening_value = (string) (float) $it->opening_value;
        $this->i_opening_godown_id = $it->opening_godown_id;
        $this->i_opening_godown_label = $it->openingGodown?->name ?? '';
        $this->i_gst_rate = $it->gst_rate !== null ? (string) (float) $it->gst_rate : null;
        $this->i_hsn_sac = $it->hsn_sac;
        $this->i_reorder_level = $it->reorder_level !== null ? (string) (float) $it->reorder_level : null;
        $this->i_costing_method = $it->costing_method ?: 'weighted_average';
        // Phase 13 — the method locks once the item has any stock movement (revaluing history is a
        // migration, not a UI toggle). The form renders the field read-only when this is true.
        $this->i_costing_locked = $it->hasStockMovements();
        $this->resetErrorBag();
    }

    public function saveAlter(): ?array
    {
        $it = StockItem::findOrFail($this->alter_id);
        $this->validate($this->rules('i_'), [], ['i_stock_group_id' => 'under', 'i_unit_id' => 'unit', 'i_name' => 'name']);
        $data = $this->payload('i_');
        // Server authority: a locked item's method never changes, whatever the client submitted (the
        // StockItem::updating observer is the final backstop and would throw on a dirty change).
        if ($it->hasStockMovements()) {
            $data['costing_method'] = $it->costing_method;
        }
        $it->update($data);

        return $it->fresh()->load(['stockGroup', 'unit'])->toCache();
    }

    public function deleteMaster(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $it = StockItem::lockForUpdate()->find($id);
            if (! $it) {
                return ['ok' => false, 'message' => 'Stock item not found.'];
            }
            if (\App\Models\StockEntry::where('stock_item_id', $it->id)->exists()) {
                return ['ok' => false, 'message' => 'Stock item has movement — cannot delete.'];
            }
            $it->delete();

            return ['ok' => true, 'message' => 'Deleted.'];
        });
    }

    public function render()
    {
        return view('livewire.inventory.stock-item-workspace');
    }
}
