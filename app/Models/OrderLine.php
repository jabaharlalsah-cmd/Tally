<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 8B — one outstanding commitment line on a Sales/Purchase Order.
 *
 * `delivered_qty` is the running sum of this line's {@see OrderFulfillment} rows
 * (kept in sync by OrderService, never trusted from the client); `pending_qty` is
 * `ordered_qty - delivered_qty`. A line with pending 0 is fully fulfilled and drops
 * off the Order Outstanding report — the order voucher itself is never deleted
 * (audit trail).
 */
class OrderLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'voucher_id', 'stock_item_id', 'godown_id',
        'ordered_qty', 'delivered_qty', 'rate', 'amount', 'line_no',
    ];

    protected $casts = [
        'ordered_qty' => 'decimal:4',
        'delivered_qty' => 'decimal:4',
        'rate' => 'decimal:4',
        'amount' => 'decimal:2',
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

    public function fulfillments(): HasMany
    {
        return $this->hasMany(OrderFulfillment::class);
    }

    public function pendingQty(): float
    {
        return (float) $this->ordered_qty - (float) $this->delivered_qty;
    }
}
