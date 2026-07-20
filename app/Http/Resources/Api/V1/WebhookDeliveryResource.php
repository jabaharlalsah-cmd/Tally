<?php

namespace App\Http\Resources\Api\V1;

use App\Models\WebhookDelivery;

/**
 * Phase 16C — the canonical JSON shape of a delivery attempt (the customer's own debugging log).
 *
 * `response_body_excerpt` is the CUSTOMER'S endpoint's reply, truncated — never ZeroBook data. It
 * is here so an integrator can see why their own server rejected an event without leaving the UI.
 */
class WebhookDeliveryResource
{
    public static function make(WebhookDelivery $d): array
    {
        return [
            'id' => $d->id,
            'event_id' => $d->event_id,          // stable across retries — the receiver's dedup key
            'event_type' => $d->event_type,
            'company_id' => $d->company_id,
            'status' => $d->status,
            'attempt_count' => (int) $d->attempt_count,
            'next_attempt_at' => $d->next_attempt_at?->toIso8601String(),
            'last_attempted_at' => $d->last_attempted_at?->toIso8601String(),
            'last_response_status' => $d->last_response_status,
            'response_body_excerpt' => $d->last_response_body_excerpt,
            'succeeded_at' => $d->succeeded_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }
}
