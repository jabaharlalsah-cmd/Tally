<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 10A — the running fiscal-year state per (deductee, section).
 *
 * This is what makes the threshold decision possible at all. Indian TDS law does not ask
 * "is this payment large?" — it asks "has the year's aggregate to this vendor under this
 * section crossed the line yet?" So the aggregate has to be carried, and it has to be
 * carried per SECTION: the same vendor may be paid professional fees (194J, ₹50,000
 * threshold) and separately under a contract (194C, ₹30,000 single / ₹1,00,000 annual),
 * and neither aggregate touches the other.
 *
 * Maintained transactionally inside the voucher's own post transaction — never lazily
 * recomputed — so a concurrent post can never observe a half-updated threshold.
 */
class TdsDeducteeYtd extends Model
{
    use BelongsToCompany;

    protected $table = 'tds_deductee_ytd';

    protected $fillable = [
        'deductee_ledger_id', 'tds_section_id', 'fy_start', 'paid_amount', 'deducted_amount',
    ];

    protected $casts = [
        'paid_amount' => 'decimal:2',
        'deducted_amount' => 'decimal:2',
        'fy_start' => 'integer',
    ];

    public function deductee(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'deductee_ledger_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(TdsSection::class, 'tds_section_id');
    }
}
