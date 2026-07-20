<?php

namespace App\Support;

use App\Models\Plan;
use App\Models\Tenant;

/**
 * Phase 7B — the subscription-plan gate over F11 company features.
 *
 * The Phase 4 F11 framework decides whether a feature IS on (per tenant, in the
 * tenant DB). This gate decides whether a feature MAY be turned on at all — from the
 * current tenant's plan tier (central DB). It is the SECURITY BOUNDARY: the F11
 * screen disables plan-locked toggles for UX, but {@see violation()} is what the
 * server calls to reject an attempt to enable a locked feature, whatever the UI or a
 * crafted request tries.
 *
 * Outside a tenant context (e.g. an unscoped CLI run) there is no plan, so nothing is
 * gated — the gate only ever restricts, never fabricates access.
 */
class PlanGate
{
    /** F11 feature keys that a plan can lock (mirror CompanyFeature's flags). */
    public const GATED = ['gst', 'vat', 'tds', 'bill_by_bill', 'cost_centres', 'multi_currency'];

    private const LABELS = [
        'gst' => 'GST',
        'vat' => 'VAT',
        'tds' => 'TDS',
        'bill_by_bill' => 'Bill-wise details',
        'cost_centres' => 'Cost Centres',
        'multi_currency' => 'Multi-Currency',
    ];

    public static function currentPlan(): ?Plan
    {
        $tenant = function_exists('tenant') ? tenant() : null;

        return $tenant instanceof Tenant ? $tenant->plan : null;
    }

    public static function planName(): ?string
    {
        return self::currentPlan()?->name;
    }

    /** Whether the current tenant's plan unlocks a feature (ungated when no tenant). */
    public static function allows(string $feature): bool
    {
        $plan = self::currentPlan();

        return $plan ? $plan->allows($feature) : true;
    }

    /** The gated features the current plan does NOT unlock. */
    public static function lockedFeatures(): array
    {
        $plan = self::currentPlan();
        if (! $plan) {
            return [];
        }

        return array_values(array_filter(self::GATED, fn ($f) => ! $plan->allows($f)));
    }

    /**
     * Given the feature keys someone is trying to have ENABLED, return an error
     * message if any is plan-locked, or null if all are permitted. This is the
     * server-side enforcement the F11 save path delegates to.
     */
    public static function violation(array $enabledFeatures): ?string
    {
        $offending = array_values(array_intersect($enabledFeatures, self::lockedFeatures()));
        if (! $offending) {
            return null;
        }

        $names = implode(', ', array_map(fn ($f) => self::LABELS[$f] ?? $f, $offending));
        $plan = self::planName() ?? 'your plan';
        $verb = count($offending) > 1 ? 'are' : 'is';

        return "{$names} {$verb} not included in the {$plan} plan — upgrade to enable.";
    }

    public static function label(string $feature): string
    {
        return self::LABELS[$feature] ?? $feature;
    }
}
