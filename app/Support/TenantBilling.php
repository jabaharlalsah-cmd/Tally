<?php

namespace App\Support;

use App\Models\CompanyFeature;
use App\Models\Payment;
use App\Models\Plan;

/**
 * Phase 14B — the tenant's billing currency + purchasable plans.
 *
 * A tenant bills in ONE currency, derived from its default company's tax regime chosen at
 * signup: Nepal (VAT) → NPR, India (GST) or anything else → INR. Runs inside the tenant
 * context (the active company is pinned by SetActiveCompany).
 */
class TenantBilling
{
    public static function currency(): string
    {
        try {
            return CompanyFeature::current()->vat ? 'NPR' : 'INR';
        } catch (\Throwable) {
            return 'INR';
        }
    }

    public static function currencySymbol(?string $currency = null): string
    {
        return ($currency ?? self::currency()) === 'NPR' ? 'रू' : '₹';
    }

    /** Public plans priced in the tenant's currency, for the renew dropdown. */
    public static function purchasablePlans(?string $currency = null): \Illuminate\Support\Collection
    {
        $currency = $currency ?? self::currency();

        return Plan::where('is_public', true)
            ->orderBy('price_'.strtolower($currency))
            ->get()
            ->filter(fn (Plan $p) => $p->isPurchasableIn($currency))
            ->values();
    }

    /** How many customer claims this tenant has already submitted today. */
    public static function claimsToday(string $tenantId): int
    {
        return Payment::where('tenant_id', $tenantId)
            ->whereNotNull('notified_by_user_id')
            ->whereDate('created_at', now()->toDateString())
            ->count();
    }
}
