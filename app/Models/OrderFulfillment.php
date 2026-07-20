<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8B — the audit trail linking an order line to the Delivery/Receipt Note that
 * fulfilled part (or all) of it. `order_lines.delivered_qty` is always the sum of its
 * fulfillments, so reversal on alter/cancel is deterministic (delete the rows, re-sum).
 */
class OrderFulfillment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['order_line_id', 'fulfillment_voucher_id', 'qty', 'line_no'];

    protected $casts = [
        'qty' => 'decimal:4',
    ];

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    public function fulfillmentVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'fulfillment_voucher_id');
    }
}
