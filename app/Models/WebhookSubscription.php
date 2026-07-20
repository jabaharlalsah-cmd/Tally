<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 16C — a customer's registered webhook endpoint. Tenant database.
 *
 * THE SECRET IS `encrypted`, NOT hashed — see the migration docblock. ZeroBook signs outgoing
 * deliveries with it, so it must be recoverable; a hash would make signing impossible. It is
 * shown to the customer exactly once (create/rotate) and is `$hidden` so it can never ride out
 * through toArray()/toJson() into an API response, a Livewire snapshot, or a log line.
 */
class WebhookSubscription extends Model
{
    protected $table = 'webhook_subscriptions';

    protected $fillable = [
        'url', 'description', 'event_types_json', 'authorized_company_ids_json',
        'secret', 'is_active', 'created_by_user_id', 'created_by_email',
        'last_delivery_at', 'consecutive_failures', 'disabled_at', 'disabled_reason',
    ];

    protected $casts = [
        'event_types_json' => 'array',
        'authorized_company_ids_json' => 'array',
        // Recoverable at delivery time — the deliberate inversion of 16A's Hash::make.
        'secret' => 'encrypted',
        'is_active' => 'boolean',
        'consecutive_failures' => 'integer',
        'last_delivery_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    /** The secret must never serialize. It is returned ONCE, explicitly, at create/rotate. */
    protected $hidden = ['secret'];

    /**
     * WebhookEmitter reads the live subscription set once per process (posts consult it constantly;
     * almost no tenant has webhooks). Any write here can change that set — created, disabled,
     * re-pointed, auto-disabled by the dispatcher — so drop the memo and let the next emission
     * re-read. Without this a subscription created and used inside one long-running process (the
     * importer, every prove command) would be invisible to it.
     *
     * LIMIT, stated because it is invisible otherwise: model events do not fire for MASS writes
     * (`WebhookSubscription::query()->update(…)`/`->delete()`), so those do NOT flush. Any write
     * that bypasses a model instance must call WebhookEmitter::flushCache() itself, or emission
     * will keep using the pre-write set for the rest of the process.
     *
     * THIS DOCBLOCK ONCE CLAIMED "every path today writes through a model instance — so this
     * holds." That was false when written: WebhooksList::deleteWebhook() deleted through the query
     * builder. The result was silent and severe — the memo kept serving the deleted row, its
     * delivery INSERT failed the foreign key, and because the emitter's fan-out was wrapped in a
     * single try, deleting ONE webhook stopped every OTHER webhook in the tenant from receiving
     * events. Both were fixed (the UI deletes through the model; the fan-out is guarded per
     * subscription), and prove-webhooks §13 pins the blast radius.
     *
     * The lesson is about the comment, not the code: an invariant asserted here is load-bearing,
     * and stating one without checking every caller is worse than stating nothing. If you add a
     * caller, verify it — do not extend this list on faith.
     */
    protected static function booted(): void
    {
        static::saved(function (self $sub) {
            \App\Services\Api\Webhooks\WebhookEmitter::flushCache();
            $sub->releaseBacklogOnResume();
        });
        static::deleted(fn () => \App\Services\Api\Webhooks\WebhookEmitter::flushCache());
    }

    /**
     * Coming back to life makes this subscription's deferred backlog due again, immediately.
     *
     * While a subscription is paused the dispatcher DEFERS its rows (pushing next_attempt_at out)
     * rather than delivering or destroying them — deferring keeps a paused subscription's backlog
     * from consuming the batch limit and starving healthy ones. Without this hook the customer
     * would resume and then wait out that deferral for events that were ready all along.
     *
     * Only fires on a real false→true transition, so ordinary saves cost nothing.
     */
    private function releaseBacklogOnResume(): void
    {
        if (! $this->wasChanged('is_active') || ! $this->is_active || $this->isDisabled()) {
            return;
        }

        WebhookDelivery::query()
            ->where('webhook_subscription_id', $this->id)
            ->where('status', WebhookDelivery::STATUS_PENDING)
            ->update(['next_attempt_at' => now()]);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    // ── authorization ────────────────────────────────────────────────────────

    /**
     * Restrict a query to the subscriptions an API key may see and act on.
     *
     * Tenant isolation is already structural (one tenant, one database), but COMPANY isolation is
     * not — this table has no company_id, only the set of companies each subscription may hear
     * about. Without this scope a key restricted to company 5 could show/update/rotate/delete any
     * subscription in the tenant and read another company's voucher payloads straight out of its
     * delivery log.
     *
     * The rule mirrors ApiKey::authorizesCompanySet(): a key may act on a subscription whose
     * company set is a SUBSET of its own. Expressed in SQL rather than PHP so it composes with
     * cursor pagination — filtering a page after the fact would silently return short pages.
     *
     *   JSON_CONTAINS(<key's ids>, authorized_company_ids_json)  ⇒ every id on the subscription
     *                                                              also appears on the key
     *
     * plus JSON_LENGTH > 0 to exclude all-companies subscriptions, which are wider than any
     * restricted key.
     */
    public function scopeVisibleToApiKey(Builder $query, ApiKey $key): Builder
    {
        if ($key->authorizesAllCompanies()) {
            return $query;
        }

        return $query
            ->whereRaw('JSON_LENGTH(authorized_company_ids_json) > 0')
            ->whereRaw('JSON_CONTAINS(CAST(? AS JSON), authorized_company_ids_json)',
                [json_encode($key->authorizedCompanyIds())]);
    }

    // ── state ────────────────────────────────────────────────────────────────

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /** Eligible to receive new events: active, not auto-disabled. */
    public function isLive(): bool
    {
        return $this->is_active && ! $this->isDisabled();
    }

    // ── matching ─────────────────────────────────────────────────────────────

    public function eventTypes(): array
    {
        return $this->event_types_json ?? [];
    }

    /** Does this subscription want $eventType? '*' matches everything. */
    public function wantsEvent(string $eventType): bool
    {
        $types = $this->eventTypes();

        return in_array('*', $types, true) || in_array($eventType, $types, true);
    }

    /** Authorized company ids. An EMPTY list means "every company in this tenant". */
    public function authorizedCompanyIds(): array
    {
        return array_values(array_map('intval', $this->authorized_company_ids_json ?? []));
    }

    public function authorizesAllCompanies(): bool
    {
        return $this->authorizedCompanyIds() === [];
    }

    /**
     * May this subscription hear about an event in $companyId?
     *
     * A null company (an event with no company context) only reaches all-companies subscriptions —
     * fail closed rather than guess.
     */
    public function authorizesCompany(?int $companyId): bool
    {
        if ($this->authorizesAllCompanies()) {
            return true;
        }

        return $companyId !== null && in_array($companyId, $this->authorizedCompanyIds(), true);
    }

    /** 'https://hooks.acme.test/zerobook' → masked for a list view (the URL is not secret, but tidy). */
    public function host(): string
    {
        return parse_url($this->url, PHP_URL_HOST) ?: $this->url;
    }
}
