<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequiresIdempotencyKey;
use App\Http\Resources\Api\V1\WebhookDeliveryResource;
use App\Http\Resources\Api\V1\WebhookSubscriptionResource;
use App\Models\Company;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\Api\ApiVoucherException;
use App\Services\Api\IdempotencyService;
use App\Services\Api\IdempotentOperation;
use App\Services\Api\Webhooks\WebhookDispatcher;
use App\Services\Api\Webhooks\WebhookEvents;
use App\Services\Api\Webhooks\WebhookSigner;
use App\Support\ActiveCompany;
use App\Support\ApiError;
use App\Support\Api\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 16C — webhook subscription management over REST. Scope: webhook:manage.
 *
 * THE SECRET APPEARS EXACTLY TWICE in this class's output: the create response and the
 * rotate-secret response. Everywhere else it is structurally absent — WebhookSubscriptionResource
 * never carries it and the model marks it $hidden — so no list, detail, or update response can
 * leak it even by accident.
 */
class WebhooksController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            CursorPage::build(
                $request,
                WebhookSubscription::query()->visibleToApiKey($this->key($request)),
                fn (WebhookSubscription $s) => WebhookSubscriptionResource::make($s),
            )
        );
    }

    public function show(WebhookSubscription $webhook): JsonResponse
    {
        return response()->json(WebhookSubscriptionResource::make($webhook));
    }

    /** POST /v1/webhooks — the secret is returned ONCE, here. */
    public function store(Request $request, IdempotencyService $idem): JsonResponse
    {
        return $this->write($request, $idem, function () use ($request) {
            $data = $this->validated($request);

            $secret = WebhookSigner::newSecret();
            // `+` keeps a value attributes() already set; the defaults only fill an omitted field.
            // authorized_company_ids_json is NOT NULL. The default is the CALLING KEY's own company
            // set — which is [] ("all companies") only for a key that itself spans all companies.
            $sub = WebhookSubscription::create(
                $this->attributes($data, $request)
                + ['secret' => $secret, 'authorized_company_ids_json' => $this->key($request)->authorizedCompanyIds()]
            );

            return new IdempotentOperation(
                201,
                WebhookSubscriptionResource::make($sub) + [
                    // ONCE. ZeroBook stores this encrypted (it must sign with it) but never shows
                    // it again — the customer copies it now or rotates later.
                    'secret' => $secret,
                    'secret_notice' => 'Store this now — it is shown only once. Use it to verify the X-ZeroBook-Signature header.',
                ],
                'webhook',
                $sub->id,
                // The REPLAY body: no secret. Storing it would park the plaintext in
                // api_idempotency_keys for 48h (a nightly-backed-up table) and re-show it on every
                // replay — "shown once" in name only.
                storeBody: $this->redactedSecret(WebhookSubscriptionResource::make($sub)),
            );
        });
    }

    public function update(Request $request, WebhookSubscription $webhook, IdempotencyService $idem): JsonResponse
    {
        return $this->write($request, $idem, function () use ($request, $webhook) {
            $data = $this->validated($request);
            $attrs = $this->attributes($data, $request);

            // Re-enabling by hand clears an auto-disable — the customer is asserting the endpoint
            // is healthy again, so give it a fresh failure budget.
            if (($attrs['is_active'] ?? false) && $webhook->isDisabled()) {
                $attrs['disabled_at'] = null;
                $attrs['disabled_reason'] = null;
                $attrs['consecutive_failures'] = 0;
            }

            $webhook->update($attrs);

            return new IdempotentOperation(200, WebhookSubscriptionResource::make($webhook->fresh()), 'webhook', $webhook->id);
        });
    }

    public function destroy(WebhookSubscription $webhook): JsonResponse
    {
        $webhook->delete();   // deliveries cascade

        return response()->json(['id' => $webhook->id, 'status' => 'deleted']);
    }

    /** POST /v1/webhooks/{webhook}/rotate-secret — the new secret is returned ONCE. */
    public function rotateSecret(Request $request, WebhookSubscription $webhook, IdempotencyService $idem): JsonResponse
    {
        return $this->write($request, $idem, function () use ($webhook) {
            $secret = WebhookSigner::newSecret();
            $webhook->update(['secret' => $secret]);

            return new IdempotentOperation(
                200,
                WebhookSubscriptionResource::make($webhook->fresh()) + [
                    'secret' => $secret,
                    'secret_notice' => 'The previous secret stopped working immediately. Deliveries already queued will be signed with this new one.',
                ],
                'webhook',
                $webhook->id,
                storeBody: $this->redactedSecret(WebhookSubscriptionResource::make($webhook->fresh())),
            );
        });
    }

    /**
     * GET /v1/webhooks/{webhook}/deliveries — the delivery log, newest first.
     *
     * FILTERED BY COMPANY IN ITS OWN RIGHT, not merely by the route binding.
     *
     * The binding authorizes against the subscription's CURRENT company set, but this log is
     * HISTORICAL: every row carries the company_id it was emitted for. Those two disagree the
     * moment a subscription is narrowed. A subscription that spanned companies 1 and 2, later
     * tightened to [1] — an ordinary, encouraged action — would hand its whole back-catalogue of
     * company-2 rows to a key authorized only for company 1. Tightening a scope must never
     * retroactively widen a read.
     *
     * Fail closed on a null company_id: an event with no company context is not something a
     * company-restricted key can be shown to be entitled to.
     */
    public function deliveries(Request $request, WebhookSubscription $webhook): JsonResponse
    {
        $key = $this->key($request);

        $query = WebhookDelivery::query()->where('webhook_subscription_id', $webhook->id);

        if (! $key->authorizesAllCompanies()) {
            $query->whereIn('company_id', $key->authorizedCompanyIds());
        }

        return response()->json(
            CursorPage::build($request, $query, fn (WebhookDelivery $d) => WebhookDeliveryResource::make($d))
        );
    }

    /** POST /v1/webhooks/{webhook}/test — queue a signed `ping`. */
    public function test(Request $request, WebhookSubscription $webhook, IdempotencyService $idem, WebhookDispatcher $dispatcher): JsonResponse
    {
        return $this->write($request, $idem, function () use ($webhook, $dispatcher) {
            if (! $webhook->isLive()) {
                throw new ApiVoucherException('conflict', 409, ['reason' => 'This webhook is inactive or disabled; enable it before sending a test.']);
            }

            $dispatcher->sendTest($webhook);

            return new IdempotentOperation(202, [
                'id' => $webhook->id,
                'status' => 'queued',
                'message' => 'A signed ping event was queued. It is delivered by the next dispatch tick (within a minute).',
            ], 'webhook', $webhook->id);
        });
    }

    // ── plumbing ─────────────────────────────────────────────────────────────────────────

    /**
     * The body a REPLAY of a create/rotate returns: the resource, and an honest explanation instead
     * of the secret. A replay is still idempotent where it counts — no second webhook, same
     * resource — it just cannot hand back a value the customer was told to save once.
     */
    private function redactedSecret(array $resource): array
    {
        return $resource + [
            'secret' => null,
            'secret_notice' => 'The secret was shown only in the original response and is not stored in recoverable form here. If you no longer have it, rotate the secret.',
        ];
    }

    private function write(Request $request, IdempotencyService $idem, callable $operation): JsonResponse
    {
        $key = $request->attributes->get(ApiError::API_KEY_ATTR);
        $idemKey = $request->attributes->get(RequiresIdempotencyKey::ATTR);

        return $idem->run($key->id, $idemKey, IdempotencyService::fingerprint($request), $operation)->toResponse($request);
    }

    private function validated(Request $request): array
    {
        $data = $request->json()->all();

        $urlRules = ['required', 'url', 'max:2048'];
        if (config('webhooks.require_https')) {
            // In production a webhook carries the customer's own books over the wire — plaintext
            // http would expose it to any hop. Relaxed in local dev so a localhost receiver works.
            $urlRules[] = 'starts_with:https://';
        }

        return validator($data, [
            'url' => $urlRules,
            'description' => ['nullable', 'string', 'max:191'],
            'event_types' => ['required', 'array', 'min:1'],
            'event_types.*' => ['string', Rule::in(array_merge(WebhookEvents::all(), [WebhookEvents::WILDCARD]))],
            'authorized_company_ids' => ['array'],
            // `companies` IS the tenant's company registry (it has no company_id of its own), and
            // we are already on this tenant's connection — so existence here is inherently
            // tenant-scoped and cannot name another tenant's company.
            'authorized_company_ids.*' => ['integer', Rule::exists('companies', 'id')],
            'is_active' => ['boolean'],
        ], [
            'url.starts_with' => 'The webhook url must use https.',
            'event_types.*.in' => 'Unknown event type.',
        ])->validate();
    }

    private function key(Request $request): \App\Models\ApiKey
    {
        return $request->attributes->get(ApiError::API_KEY_ATTR);
    }

    private function attributes(array $data, Request $request): array
    {
        $key = $this->key($request);

        $attrs = array_filter([
            'url' => $data['url'] ?? null,
            'description' => $data['description'] ?? null,
            'event_types_json' => isset($data['event_types']) ? WebhookEvents::sanitize($data['event_types']) : null,
            'created_by_email' => $key?->name ? 'api:'.$key->name : null,
        ], fn ($v) => $v !== null);

        // is_active follows the SAME rule as company authorization below, and for the same reason.
        // It used to default to true on every write, which meant a routine PUT that never mentioned
        // it — the standard "reconcile my webhook config" call — silently un-paused a subscription
        // the owner had deliberately switched off AND cleared an auto-disable, resetting the
        // failure budget. That permanently defeats auto-disable: a dead endpoint would be revived
        // on every reconcile. An omitted field must mean "leave it alone"; only create needs a
        // default, and the model column already supplies one.
        if (array_key_exists('is_active', $data)) {
            $attrs['is_active'] = (bool) $data['is_active'];
        }

        // Company authorization is set ONLY when the caller actually sent the field. An empty list
        // means "every company", so defaulting an omitted field to [] would silently WIDEN an
        // update from one company to all of them — the opposite of what an omission implies.
        if (array_key_exists('authorized_company_ids', $data)) {
            $ids = array_values(array_map('intval', $data['authorized_company_ids']));

            // A subscription may never out-reach the key that created it. Rule::exists() proved
            // these companies are in this TENANT; it says nothing about whether this KEY may hear
            // about them. Without this a key restricted to company 5 could ask for [] or [7] and
            // hold a standing subscription to books it cannot read over REST — the escalation is
            // durable, because the dispatcher POSTs later with no key in sight.
            if (! $this->key($request)->authorizesCompanySet($ids)) {
                throw new ApiVoucherException('company_not_authorized', 403, [
                    'reason' => 'This API key is not authorized for every company requested. A webhook cannot receive events from a company its key cannot read.',
                ]);
            }

            $attrs['authorized_company_ids_json'] = $ids;
        }

        return $attrs;
    }
}
