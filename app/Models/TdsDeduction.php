<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 10A — the TDS deducted on one Payment voucher.
 *
 * A row exists for EVERY TDS-engaged Payment, including the ones that deducted nothing
 * because the deductee was still below the threshold. Those zero rows carry their weight:
 *
 *   • they are the audit trail of a below-threshold payment;
 *   • they are how a monthly-threshold section (194I) reconstructs its month window;
 *   • Form 26Q (Phase 10B) reports amount-paid, not just tax-deducted;
 *   • they make reverseFor() uniform — alter and cancel never special-case.
 *
 * payment_amount vs base_amount is the subtle pair:
 *   payment_amount = the taxable base of THIS voucher (pre-GST).
 *   base_amount    = the base the deduction was COMPUTED on. Equal to payment_amount
 *                    in the steady state; equal to the whole year-to-date aggregate on
 *                    the voucher that first crosses the threshold, because Indian TDS
 *                    law makes you catch up on everything paid earlier that year.
 */
class TdsDeduction extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'voucher_id', 'voucher_entry_id', 'deductee_ledger_id', 'tds_section_id',
        'payment_amount', 'base_amount', 'rate', 'deducted_amount', 'fy_start', 'reason',
    ];

    protected $casts = [
        'payment_amount' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'rate' => 'decimal:2',
        'deducted_amount' => 'decimal:2',
        'fy_start' => 'integer',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function voucherEntry(): BelongsTo
    {
        return $this->belongsTo(VoucherEntry::class);
    }

    public function deductee(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'deductee_ledger_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(TdsSection::class, 'tds_section_id');
    }
}
