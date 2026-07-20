<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 7B — a subscription plan (CENTRAL database).
 *
 * `features` is the authoritative tier → F11-feature matrix: which company feature
 * families a tenant on this plan may switch on. The F11 screen reads it to gate the
 * toggles, and the server enforces it on save — so a plan-locked feature can never
 * be turned on, whatever the UI or a crafted request tries.
 *
 * Phase 14B adds billing: per-region prices (price_inr / price_npr), the billing
 * period in months, and whether the plan is publicly purchasable (shows on the
 * customer's renew dropdown). Editing a price never changes past payments — each
 * payment stores the amount actually received.
 */
class Plan extends Model
{
    use UsesCentralConnection;

    protected $fillable = [
        'name', 'tier', 'features', 'price_inr', 'price_npr', 'billing_period_months', 'is_public',
    ];

    protected $casts = [
        'features' => 'array',
        'price_inr' => 'decimal:2',
        'price_npr' => 'decimal:2',
        'billing_period_months' => 'integer',
        'is_public' => 'boolean',
    ];

    /** Whether this plan unlocks a given company feature key (default: locked). */
    public function allows(string $feature): bool
    {
        return (bool) ($this->features[$feature] ?? false);
    }

    /** Feature keys this plan unlocks. */
    public function unlockedFeatures(): array
    {
        return array_keys(array_filter($this->features ?? []));
    }

    // ── Phase 14B — billing ────────────────────────────────────────────────────

    /** The list price for a currency ('INR' | 'NPR'), or null if the plan is not priced there. */
    public function priceFor(string $currency): ?string
    {
        return match (strtoupper($currency)) {
            'INR' => $this->price_inr,
            'NPR' => $this->price_npr,
            default => null,
        };
    }

    public function isMonthly(): bool
    {
        return $this->billing_period_months === 1;
    }

    /** Human label for the billing period ("month", "year", "3 months"). */
    public function periodLabel(): string
    {
        return match ($this->billing_period_months) {
            1 => 'month',
            12 => 'year',
            default => $this->billing_period_months.' months',
        };
    }

    /** Publicly purchasable in a given currency (shown on the renew dropdown). */
    public function isPurchasableIn(string $currency): bool
    {
        return $this->is_public && $this->priceFor($currency) !== null;
    }
}
