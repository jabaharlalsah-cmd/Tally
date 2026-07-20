<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\ActiveCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 12A — one F11 row PER COMPANY (was a single row per tenant).
 *
 * current() keeps its public API — every service (Bill/Gst/Vat/Tds/Forex/
 * CostCentre) and screen still calls CompanyFeature::current() — but under the
 * hood it now resolves the ACTIVE company's row, creating an all-off one on first
 * access (a fresh company starts with every feature disabled). unique(company_id)
 * enforces one row per company at the schema level.
 *
 * The identity fields (company_gstin/state/pan/tan) moved to the companies table
 * in 12A; the gstProfile()/vatProfile()/deductorProfile() accessors keep their
 * wire-format keys and read them from the company row, so the client payloads and
 * the 26Q exporter are unchanged.
 */
class CompanyFeature extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'bill_by_bill' => 'boolean',
        'cost_centres' => 'boolean',
        'gst' => 'boolean',
        'vat' => 'boolean',
        'tds' => 'boolean',
        'multi_currency' => 'boolean',
        // NAS parity Phase 2 — gates the whole inventory side of the Gateway
        // (Inventory Info, the stock/order vouchers, Stock Summary, Lot
        // Provenance), so an accounts-only company gets an accounts-only menu.
        'inventory' => 'boolean',
        // Phase 15A — Budgets is orthogonal to the tax regime (like TDS): any company can
        // maintain budgets whether or not it runs GST/VAT.
        'budgets' => 'boolean',
        // Phase 15B — Ratio Analysis, likewise orthogonal.
        'ratio_analysis' => 'boolean',
        // Phase 15C — Scenarios (provisional-voucher what-if layer).
        'scenarios' => 'boolean',
    ];

    /**
     * Shell memoises these flags to keep the Gateway down to one query. Any
     * write here must drop that memo, or anything that toggles a flag and then
     * re-renders the menu in the same process — the F11 screen saving, an
     * import, a proof command — would render from stale flags.
     */
    protected static function booted(): void
    {
        static::saved(fn () => \App\Support\Shell::forgetFeatures());
        static::deleted(fn () => \App\Support\Shell::forgetFeatures());
    }

    /** The ACTIVE company's features row (created all-off on first access). */
    public static function current(): self
    {
        return static::firstOrCreate(['company_id' => ActiveCompany::check()]);
    }

    /** The keys exposed to the client for field-gating. */
    public function toFlags(): array
    {
        return [
            'bill_by_bill' => (bool) $this->bill_by_bill,
            'cost_centres' => (bool) $this->cost_centres,
            'gst' => (bool) $this->gst,
            'vat' => (bool) $this->vat,
            // Phase 10A — TDS is orthogonal to the GST/VAT regime choice: an Indian
            // company deducts TDS whether or not it is GST-registered.
            'tds' => (bool) $this->tds,
            'multi_currency' => (bool) $this->multi_currency,
            // NAS parity Phase 2 — gates the inventory menu + stock voucher types.
            'inventory' => (bool) $this->inventory,
            // Phase 15A — gates the Budgets menu + screens.
            'budgets' => (bool) $this->budgets,
            // Phase 15B — gates the Ratio Analysis menu + screens.
            'ratio_analysis' => (bool) $this->ratio_analysis,
            // Phase 15C — gates the scenario selector on vouchers + reports.
            'scenarios' => (bool) $this->scenarios,
        ];
    }

    /** This row's company (NOT the scoped relation — read by plain FK). */
    private function identity(): ?Company
    {
        // Company is unscoped, so this is a straight PK lookup; memoised by
        // ActiveCompany when it is the active row (the overwhelmingly common case).
        $active = ActiveCompany::company();

        return ($active && $active->id === (int) $this->company_id)
            ? $active
            : Company::find($this->company_id);
    }

    /**
     * The company GST profile surfaced to the client for intra/inter + display.
     * Wire keys unchanged from 5B (company_gstin/company_state); values come from
     * the companies row since 12A.
     */
    public function gstProfile(): array
    {
        $c = $this->identity();

        return [
            'enabled' => (bool) $this->gst,
            'company_gstin' => $c?->gstin,
            'company_state' => $c?->state,
        ];
    }

    /** The company VAT (Nepal) profile — a single flat rate, no state concept. */
    public function vatProfile(): array
    {
        return [
            'enabled' => (bool) $this->vat,
            'company_pan' => $this->identity()?->pan,
        ];
    }

    /**
     * Phase 10B — the deductor's Form 26Q filing identity. The Form26qExporter reads
     * this and refuses to run if a required field is blank (rather than emit a
     * placeholder the FVU would reject). TAN and PAN come from the companies row
     * since 12A; the address/responsible-person block stays here.
     */
    public function deductorProfile(): array
    {
        $c = $this->identity();

        return [
            'tan' => $c?->tan,
            'pan' => $c?->pan,
            'name' => $this->deductor_name,
            'address1' => $this->deductor_address1,
            'address2' => $this->deductor_address2,
            'state_code' => $this->deductor_state_code,
            'pincode' => $this->deductor_pincode,
            'email' => $this->deductor_email,
            'phone' => $this->deductor_phone,
            'type' => $this->deductor_type,
            'resp_name' => $this->resp_name,
            'resp_designation' => $this->resp_designation,
            'resp_pan' => $this->resp_pan,
            'resp_address1' => $this->resp_address1,
            'resp_state_code' => $this->resp_state_code,
            'resp_pincode' => $this->resp_pincode,
            'resp_email' => $this->resp_email,
            'resp_phone' => $this->resp_phone,
        ];
    }
}
