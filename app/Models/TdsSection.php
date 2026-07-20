<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 10A — one row of the TDS rate table.
 *
 * Rates and thresholds live HERE, never in code, because the Finance Act changes them
 * every year. The user maintains this table from the TDS Sections master screen; the
 * engine only ever reads it.
 *
 * Sections are effective-dated by FISCAL-YEAR START (the same integer vouchers.fy_start
 * carries: 2026 => FY 2026-27). This is what lets one book hold both the old 194-series
 * (in force through FY 2025-26) and the Section 393 sub-provisions that replaced them on
 * 1 April 2026 — each voucher deducts under the section that was legal in ITS year.
 */
class TdsSection extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'code', 'label', 'rate', 'rate_company', 'no_pan_rate',
        'threshold_single', 'threshold_annual', 'threshold_period', 'deduct_basis',
        'effective_from', 'effective_to', 'notes',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'rate_company' => 'decimal:2',
        'no_pan_rate' => 'decimal:2',
        'threshold_single' => 'decimal:2',
        'threshold_annual' => 'decimal:2',
        'effective_from' => 'integer',
        'effective_to' => 'integer',
    ];

    /**
     * Section 206AA: with no PAN, deduct at 20% — or the section rate, whichever is higher.
     * A section may override the floor (the proviso to 206AA(1) caps it at 5% for 194Q and
     * 194O), which is why no_pan_rate is a column and this is only the default.
     */
    public const NO_PAN_RATE = 20.00;

    /** The Section 206AA floor for this section: its own override, else the statutory 20%. */
    public function noPanFloor(): float
    {
        return $this->no_pan_rate !== null ? (float) $this->no_pan_rate : self::NO_PAN_RATE;
    }

    /** True when this section applies a single-transaction threshold as well as an aggregate one. */
    public function hasSingleThreshold(): bool
    {
        return $this->threshold_single !== null;
    }

    /**
     * The section code stripped of its Income Tax Act 2025 prefix: '393-194J' => '194J'.
     * The 2025 Act renamed the sections but not their behaviour, so the engine dispatches
     * its section-specific quirks on the BASE code and both eras get the same treatment.
     */
    public static function baseCode(string $code): string
    {
        return preg_replace('/^393-/', '', trim($code)) ?: $code;
    }

    public function baseCodeValue(): string
    {
        return self::baseCode($this->code);
    }

    /** Sections in force for a given fiscal-year start (null effective_to = still current). */
    public function scopeEffectiveFor(Builder $q, int $fyStart): Builder
    {
        return $q->where('effective_from', '<=', $fyStart)
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $fyStart));
    }

    /** Whether this section may be used on a voucher falling in the given fiscal year. */
    public function isEffectiveFor(int $fyStart): bool
    {
        return $this->effective_from <= $fyStart
            && ($this->effective_to === null || $this->effective_to >= $fyStart);
    }

    /** The threshold_annual figure aggregates per month, not per fiscal year (194I). */
    public function isMonthly(): bool
    {
        return $this->threshold_period === 'monthly';
    }

    /** Once crossed, deduct only on the value above the threshold (194Q), not the whole aggregate. */
    public function deductsOnExcess(): bool
    {
        return $this->deduct_basis === 'excess';
    }

    /**
     * The statutory rate for a deductee type, BEFORE Section 206AA is applied.
     * A section with no rate_company charges everyone the same.
     */
    public function rateFor(?string $deducteeType): float
    {
        if ($deducteeType === 'company_firm_llp' && $this->rate_company !== null) {
            return (float) $this->rate_company;
        }

        return (float) $this->rate;
    }

    /** "393-194J · Fees for professional or technical services" */
    public function displayLabel(): string
    {
        return $this->code.' · '.$this->label;
    }

    /** How this section's threshold reads on screen. */
    public function thresholdLabel(): string
    {
        $parts = [];
        if ($this->threshold_single !== null) {
            $parts[] = 'single '.number_format((float) $this->threshold_single, 0);
        }
        if ($this->threshold_annual !== null) {
            $parts[] = ($this->isMonthly() ? 'monthly ' : 'annual ').number_format((float) $this->threshold_annual, 0);
        }

        return $parts ? implode(' · ', $parts) : 'no threshold';
    }

    /** FY label range, e.g. "2026-27 →" or "2000-01 to 2025-26". */
    public function effectiveLabel(): string
    {
        $from = Voucher::statutoryFyLabel($this->effective_from);

        return $this->effective_to === null
            ? $from.' →'
            : $from.' to '.Voucher::statutoryFyLabel($this->effective_to);
    }

    /** Shape sent to the client-side masters cache (the zbSelect picker + the panel). */
    public function toCache(): array
    {
        return [
            'id' => $this->id,
            // `name` is what the shared zbSelect picker searches and displays.
            'name' => $this->displayLabel(),
            'code' => $this->code,
            'label' => $this->label,
            // `path` is the sub-label the shared picker falls back to.
            'path' => $this->thresholdLabel().' · '.$this->effectiveLabel(),
            'rate' => (float) $this->rate,
            'rate_company' => $this->rate_company !== null ? (float) $this->rate_company : null,
            'no_pan_rate' => $this->noPanFloor(),
            'threshold_single' => $this->threshold_single !== null ? (float) $this->threshold_single : null,
            'threshold_annual' => $this->threshold_annual !== null ? (float) $this->threshold_annual : null,
            'threshold_period' => $this->threshold_period,
            'deduct_basis' => $this->deduct_basis,
            'effective_from' => $this->effective_from,
            'effective_to' => $this->effective_to,
            'is_reserved' => false,
        ];
    }
}
