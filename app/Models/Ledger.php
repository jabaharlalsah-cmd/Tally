<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ledger extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'name', 'alias', 'group_id',
        'opening_balance', 'opening_balance_type',
        'mailing_name', 'address', 'state', 'country', 'pincode', 'pan', 'gstin',
        'tax_type', 'tax_role', 'gst_rate', 'hsn_sac', 'gst_registration_type',
        // Phase 10A — TDS deductee tagging (on party ledgers, typically Sundry Creditors).
        'deductee_pan', 'deductee_type', 'default_tds_section_id',
        // Phase 11 — foreign-currency ledger tag (null = base currency).
        'currency_id',
        'linked_company_id', // Phase 12B — this party IS that company (inter-company tagging trigger)
        'is_reserved', 'is_pl_account',
        'maintain_bill_by_bill', 'cost_centres_applicable',
        'bank_account_no', 'bank_ifsc', 'bank_name',
    ];

    protected $casts = [
        'linked_company_id' => 'integer',
        'opening_balance' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'is_reserved' => 'boolean',
        'is_pl_account' => 'boolean',
        'maintain_bill_by_bill' => 'boolean',
        'cost_centres_applicable' => 'boolean',
        'default_tds_section_id' => 'integer',
        'currency_id' => 'integer',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class, 'group_id');
    }

    /** Phase 11 — the foreign currency this ledger transacts in (null = base currency). */
    /** Phase 12B — the company this party ledger IS (inter-company link). */
    public function linkedCompany()
    {
        return $this->belongsTo(Company::class, 'linked_company_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /** Phase 10A — the section this deductee is usually paid under (e.g. a lawyer → 393-194J). */
    public function defaultTdsSection(): BelongsTo
    {
        return $this->belongsTo(TdsSection::class, 'default_tds_section_id');
    }

    /** Shape sent to the client-side masters cache (for ledger pickers in Phase 3). */
    public function toCache(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'alias' => $this->alias,
            'group_id' => $this->group_id,
            'group' => $this->group?->name,
            'opening_balance' => (float) $this->opening_balance,
            'opening_balance_type' => $this->opening_balance_type,
            'is_reserved' => (bool) $this->is_reserved,
            // Bill-wise flag — the client opens the allocation sub-screen when a
            // bill-wise ledger is used on a voucher line (Phase 5C).
            'maintain_bill_by_bill' => (bool) $this->maintain_bill_by_bill,
            // Cost-centre flag — opens the cost-allocation sub-screen (Phase 5D).
            'cost_centres_applicable' => (bool) $this->cost_centres_applicable,
            // GST fields — let the client compute tax + intra/inter live (Phase 5B).
            'state' => $this->state,
            'gstin' => $this->gstin,
            'gst_rate' => $this->gst_rate !== null ? (float) $this->gst_rate : null,
            'tax_type' => $this->tax_type,
            'tax_role' => $this->tax_role,
            // TDS deductee tagging — the voucher screen offers the deduction (and defaults
            // the section) from these, and derives the Section 206AA rate when PAN is blank.
            'deductee_pan' => $this->deductee_pan,
            'deductee_type' => $this->deductee_type,
            'default_tds_section_id' => $this->default_tds_section_id,
            // Phase 11 — the foreign currency this ledger transacts in. The voucher screen
            // shows the foreign amount + rate fields on any line touching a foreign ledger.
            'currency_id' => $this->currency_id,
            'currency_code' => $this->currency?->code,
            // Phase 12B — lets the voucher screen derive the inter-company badge with
            // zero network; the server re-derives and enforces on accept.
            'linked_company_id' => $this->linked_company_id ? (int) $this->linked_company_id : null,
        ];
    }
}
