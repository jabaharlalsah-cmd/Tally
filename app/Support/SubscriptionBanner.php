<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Phase 14B — the app-wide subscription banner shown on every tenant screen when the
 * paid subscription is expiring soon, in the grace window, or expired. Read fresh from
 * the central tenants row so it reflects an admin's just-recorded renewal.
 */
class SubscriptionBanner
{
    public static function forCurrentTenant(): ?array
    {
        if (! function_exists('tenant') || ! tenant()) {
            return null;
        }

        $tenant = Tenant::find(tenant('id'));
        $banner = $tenant?->subscriptionBanner();

        if (! $banner) {
            return null;
        }

        return match ($banner) {
            'expired' => [
                'bg' => '#fdeceb', 'fg' => '#b23b32',
                'text' => 'Your subscription has expired — renew to resume posting. Your books remain fully readable.',
            ],
            'grace' => [
                'bg' => '#fff4e0', 'fg' => '#97590a',
                'text' => 'Your subscription has expired — please renew. Grace period until '.$tenant->accessEndsAt()?->format('d-M-Y').'.',
            ],
            'expiring_soon' => [
                'bg' => '#fff4e0', 'fg' => '#97590a',
                'text' => 'Your subscription expires in '.$tenant->subscriptionDaysLeft().' day(s) ('.$tenant->plan_ends_at?->format('d-M-Y').').',
            ],
            default => null,
        };
    }
}
