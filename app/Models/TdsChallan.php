<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 10B — the bank/book identifiers for one TDS remittance to the government.
 *
 * In Phase 10A, remitting TDS is an ordinary Payment (Dr TDS Payable / Cr Bank), and the
 * deduction→remittance mapping is FIFO. This row attaches the real challan identity the
 * Form 26Q return requires: the BSR code of the receiving bank branch, the 5-digit challan
 * serial from the bank stamp, and the actual deposit date. The Form26qExporter emits one
 * Challan Detail (CD) record per row falling in the quarter.
 */
class TdsChallan extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'voucher_id', 'bsr_code', 'challan_number', 'deposit_date', 'total_amount', 'minor_head',
    ];

    protected $casts = [
        'deposit_date' => 'date',
        'total_amount' => 'decimal:2',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
