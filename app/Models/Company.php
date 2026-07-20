<?php

namespace App\Models;

use App\Support\ActiveCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Phase 12A — a company inside a tenant.
 *
 * One tenant (one MySQL database, Phase 7B) now holds N companies. Each company is
 * a fully isolated set of books: its own chart of accounts, ledgers, vouchers,
 * inventory, F11 profile, filings and currencies — every operational table carries
 * a company_id and the BelongsToCompany global scope pins every query to the
 * active company. This model itself is deliberately NOT scoped: it is the registry
 * the company picker and the Companies screen read.
 *
 * Identity fields that were on the single-row company_features (Phase 5B/5E/10B)
 * live here now: state (GST intra/inter), gstin, pan (Nepal VAT taxpayer PAN and
 * the 26Q deductor PAN — dual-purpose, as before), tan (26Q). The F11 screen still
 * edits them; it writes them to this row.
 *
 * financial_year_start_month (default 4 = April) drives the BOOKS fiscal year —
 * voucher fy_start bucketing, numbering resets, and default report periods. The
 * TDS engine deliberately does NOT follow it: Indian TDS thresholds and assessment
 * years are Apr–Mar by statute (Voucher::statutoryFyStartFor), whatever the books
 * use. The month is locked once the company has vouchers (changing it would
 * re-bucket historical fy_start values and collide numbering).
 */
class Company extends Model
{
    protected $fillable = [
        'name', 'slug', 'state', 'gstin', 'pan', 'tan',
        'base_currency_id', 'financial_year_start_month', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'financial_year_start_month' => 'integer',
        'base_currency_id' => 'integer',
    ];

    /** The active company for this request/process (null when none set). */
    public static function active(): ?self
    {
        return ActiveCompany::company();
    }

    /** The tenant's default company — the lowest-id active one. */
    public static function defaultCompany(): ?self
    {
        return static::where('is_active', true)->orderBy('id')->first()
            ?? static::orderBy('id')->first();
    }

    /** A slug for $name that is unique among this tenant's companies. */
    public static function uniqueSlugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;
        $n = 2;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    public function baseCurrency()
    {
        return $this->belongsTo(Currency::class, 'base_currency_id');
    }

    /** Phase 12B — the group this company belongs to (≤ 1 by the pivot's unique). */
    public function group(): ?CompanyGroup
    {
        return CompanyGroup::forCompany($this->id);
    }

    /** Phase 12B — ids of the OTHER companies in this company's group ([] if ungrouped). */
    public function groupmateIds(): array
    {
        $group = $this->group();

        if (! $group) {
            return [];
        }

        return $group->companies()->whereKeyNot($this->id)->pluck('companies.id')
            ->map(fn ($i) => (int) $i)->all();
    }

    /**
     * NOTE: relations onto company-scoped models (features(), ledgers(), …) carry
     * the BelongsToCompany global scope, so from a NON-active company they resolve
     * empty. Use ActiveCompany::runAs($id, …) when another company's data is
     * genuinely needed (CompanyProvisioner does).
     */
    public function features()
    {
        return $this->hasOne(CompanyFeature::class);
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class);
    }

    public function ledgers()
    {
        return $this->hasMany(Ledger::class);
    }

    /** Row shape for the client (company picker + Companies workspace). */
    public function toCache(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'state' => $this->state,
            'gstin' => $this->gstin,
            'financial_year_start_month' => (int) $this->financial_year_start_month,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
