<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 12B — the mandatory inter-company tag: one row per voucher that touches a
 * party ledger linked to a GROUPMATE company. Derived server-side by
 * InterCompanyService (the payload's declared counterparty must match or the post
 * is rejected); 12C's consolidation eliminates exactly these rows.
 *
 * company_id = the POSTING company (BelongsToCompany, the 12A discipline);
 * counterparty_company_id = the other side; counterparty_ledger_id = the mirror
 * ledger in the counterparty company when one is unambiguously reciprocally
 * linked (null otherwise) — it lets 12C match eliminations precisely instead of
 * by amount.
 */
class VoucherIntercompanyTag extends Model
{
    use BelongsToCompany;

    public $timestamps = false; // created_at only, stamped by InterCompanyService

    protected $fillable = ['voucher_id', 'counterparty_company_id', 'counterparty_ledger_id', 'created_at'];

    protected $casts = [
        'voucher_id' => 'integer',
        'counterparty_company_id' => 'integer',
        'counterparty_ledger_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function counterpartyCompany()
    {
        return $this->belongsTo(Company::class, 'counterparty_company_id');
    }

    public function counterpartyLedger()
    {
        return $this->belongsTo(Ledger::class, 'counterparty_ledger_id');
    }
}
