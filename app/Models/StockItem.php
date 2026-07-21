<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock Item (Phase 6A) — under a Stock Group, measured in a Unit, with an
 * opening balance (qty × rate = value) held in a Godown. gst_rate/hsn_sac are the
 * item's OWN tax columns (independent of any ledger's), stored now and consumed
 * by the tax engine in 6B.
 *
 * Phase 13 — costing_method (weighted_average | fifo | lifo) drives OUT-cost valuation. It is set at
 * creation and LOCKED once any stock_entry exists: changing a live item's method silently revalues
 * history, so it is an operation for a migration, not a UI toggle. The updating observer is the
 * server-side authority (the Ledger/Stock-Item form also renders the field read-only).
 */
class StockItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'name', 'alias', 'stock_group_id', 'unit_id',
        'opening_qty', 'opening_rate', 'opening_value', 'opening_godown_id',
        'gst_rate', 'hsn_sac', 'costing_method', 'reorder_level',
    ];

    protected $casts = [
        'opening_qty' => 'decimal:4',
        'opening_rate' => 'decimal:4',
        'opening_value' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'reorder_level' => 'decimal:4',
    ];

    public const COSTING_LOCKED_MESSAGE = 'Costing method is locked once stock movements exist — recreate the item with a new method if a change is required.';

    public const FIFO_OPENING_MESSAGE = 'A FIFO/LIFO item must start with zero opening stock — record opening tranches as dated opening purchase vouchers so each layer keeps its own cost and date.';

    protected static function booted(): void
    {
        // Phase 13 — refuse a costing-method change once the item has any stock movement. This is the
        // server-side authority behind the read-only form field; it fires on every save path.
        static::updating(function (StockItem $item) {
            if ($item->isDirty('costing_method') && $item->hasStockMovements()) {
                throw new \App\Exceptions\CostingMethodLockedException(self::COSTING_LOCKED_MESSAGE);
            }
        });

        // Phase 13 — a FIFO/LIFO item's closing value is Σ remaining lots × rate; a master opening
        // balance is never a lot, so it would silently vanish from the Balance Sheet. Forbid it at the
        // source (create + update): FIFO/LIFO opening is entered as dated opening purchase vouchers,
        // each a proper cost layer. Weighted-average items keep their opening balance unchanged.
        static::saving(function (StockItem $item) {
            if (in_array($item->costing_method, ['fifo', 'lifo'], true) && (float) $item->opening_qty !== 0.0) {
                throw new \App\Exceptions\CostingMethodLockedException(self::FIFO_OPENING_MESSAGE);
            }
        });
    }

    /** True once any stock_entries row references this item — the point costing_method locks. */
    public function hasStockMovements(): bool
    {
        return $this->exists && StockEntry::where('stock_item_id', $this->id)->exists();
    }

    public function stockGroup(): BelongsTo
    {
        return $this->belongsTo(StockGroup::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function openingGodown(): BelongsTo
    {
        return $this->belongsTo(Godown::class, 'opening_godown_id');
    }

    /** Shape sent to the client-side masters cache (for the item pickers in 6B). */
    public function toCache(): array
    {
        return [
            'id' => $this->id,
            'is_active' => (bool) ($this->is_active ?? true),
            'name' => $this->name,
            'alias' => $this->alias,
            'stock_group_id' => $this->stock_group_id,
            'group' => $this->stockGroup?->name,
            'unit_id' => $this->unit_id,
            'unit' => $this->unit?->name,
            'unit_symbol' => $this->unit?->symbol,
            'decimal_places' => (int) ($this->unit?->decimal_places ?? 0),
            'opening_qty' => (float) $this->opening_qty,
            'opening_rate' => (float) $this->opening_rate,
            'opening_value' => (float) $this->opening_value,
            'gst_rate' => $this->gst_rate !== null ? (float) $this->gst_rate : null,
            'hsn_sac' => $this->hsn_sac,
            'costing_method' => $this->costing_method,
            'is_reserved' => false,
        ];
    }
}
