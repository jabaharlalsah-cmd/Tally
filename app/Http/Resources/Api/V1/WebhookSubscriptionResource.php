<?php

namespace App\Http\Resources\Api\V1;

use App\Models\WebhookSubscription;

/**
 * Phase 16C — the canonical JSON shape of a webhook subscription.
 *
 * THE SECRET IS NEVER HERE. It is returned exactly once, by the create/rotate endpoints, as an
 * explicit top-level `secret` field alongside this resource — never as part of the resource
 * itself, so no list/detail/update response can ever leak it by construction.
 */
class WebhookSubscriptionResource
{
    public static function make(WebhookSubscription $sub): array
    {
        return [
            'id' => $sub->id,
            'url' => $sub->url,
            'description' => $sub->description,
            'event_types' => $sub->eventTypes(),
            'authorized_company_ids' => $sub->authorizedCompanyIds(),   // [] = all companies
            'is_active' => (bool) $sub->is_active,
            // Health — so an integrator can see a dying endpoint without opening the delivery log.
            'consecutive_failures' => (int) $sub->consecutive_failures,
            'last_delivery_at' => $sub->last_delivery_at?->toIso8601String(),
            'disabled_at' => $sub->disabled_at?->toIso8601String(),
            'disabled_reason' => $sub->disabled_reason,
            'created_at' => $sub->created_at?->toIso8601String(),
        ];
    }
}
