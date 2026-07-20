<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock ledger entry (Phase 6A schema; written by Phase 6B). One row per item
 * movement per voucher line. `value` is stored explicitly (not recomputed) so it
 * never drifts on re-read.
 */
class StockEntry extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'voucher_id', 'stock_item_id', 'godown_id',
        'direction', 'movement_type', 'quantity', 'rate', 'value',
        'sale_rate', 'sale_value', 'line_no',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'rate' => 'decimal:4',
        'value' => 'decimal:2',
        'sale_rate' => 'decimal:4',
        'sale_value' => 'decimal:2',
        'line_no' => 'integer',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function godown(): BelongsTo
    {
        return $this->belongsTo(Godown::class);
    }
}
