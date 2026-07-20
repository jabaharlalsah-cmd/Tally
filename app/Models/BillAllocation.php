<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single bill-reference allocation on a voucher line (Phase 5C).
 * ref_type ∈ new | against | advance | onaccount. The stored `amount` is a
 * magnitude; the signed contribution to a bill's pending is derived from the
 * linked voucher entry's Dr/Cr (Dr = +, Cr = −, i.e. Dr-terms).
 */
class BillAllocation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'voucher_id', 'ledger_id', 'voucher_entry_id',
        'ref_type', 'ref_name', 'amount', 'due_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_date' => 'date',
    ];

    public const TYPES = ['new', 'against', 'advance', 'onaccount'];

    public const TYPE_LABELS = [
        'new' => 'New Ref',
        'against' => 'Against Ref',
        'advance' => 'Advance',
        'onaccount' => 'On Account',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(VoucherEntry::class, 'voucher_entry_id');
    }
}
