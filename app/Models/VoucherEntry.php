<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoucherEntry extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'voucher_id', 'ledger_id', 'dr_cr', 'amount', 'line_no',
        // Phase 11 — dual-currency shape (null on a base-currency line).
        'currency_id', 'foreign_amount', 'exchange_rate',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'line_no' => 'integer',
        'currency_id' => 'integer',
        'foreign_amount' => 'decimal:4',
        'exchange_rate' => 'decimal:6',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    /** Phase 11 — the foreign currency of this line (null = base currency). */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
