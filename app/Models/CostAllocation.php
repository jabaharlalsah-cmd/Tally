<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single cost-centre allocation on a voucher line (Phase 5D). The stored
 * `amount` is a magnitude; the accounting sense (Dr/Cr) comes from the linked
 * voucher entry. Analytical only — never affects the ledger balances.
 */
class CostAllocation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'voucher_id', 'ledger_id', 'voucher_entry_id', 'cost_centre_id', 'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(VoucherEntry::class, 'voucher_entry_id');
    }
}
