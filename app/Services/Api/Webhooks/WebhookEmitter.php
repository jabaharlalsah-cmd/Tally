<?php

namespace App\Services\Api\Webhooks;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Support\ActiveCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 16C — turn a committed business fact into queued deliveries.
 *
 * THREE GUARANTEES, in order of importance:
 *
 * 1. NEVER BREAKS A POST. Every public entry point is wrapped in a catch-all that logs and
 *    swallows. A voucher that committed is correct and finished; a webhook problem is our problem,
 *    not the poster's. There is no failure mode here that can propagate into the accounting path.
 *
 * 2. POST-COMMIT ONLY. Callers register emission through DB::afterCommit (see afterCommit()), so:
 *    a rolled-back post emits nothing; a rolled-back retry ATTEMPT inside persistNew's duplicate-
 *    key loop emits nothing (its callback is discarded), while the committed attempt emits once;
 *    and the Tally importer's dry-run — which posts every voucher and then rolls the lot back —
 *    emits nothing. Crucially, the delivery-row INSERT then happens OUTSIDE the accounting
 *    transaction, so even an INSERT failure cannot roll the voucher back. That is what makes
 *    guarantee (1) structural rather than merely careful.
 *
 * 3. COMPANY-SCOPED. An event carries the company it happened in; only subscriptions authorized
 *    for that company (or for all companies) get a delivery row.
 *
 * The payload is SNAPSHOTTED into the delivery row at emission. A pending delivery must send what
 * was true when the event happened, even if the voucher is altered or cancelled before it lands.
 */
class WebhookEmitter
{
    /**
     * Live subscriptions per tenant database, and per-database table existence.
     *
     * WHY CACHE AT ALL: every voucher post consults the emitter, and the overwhelming majority of
     * tenants have zero webhooks. Uncached, that cost each post 4 extra queries — two of them
     * `information_schema` hits from Schema::hasTable, which are the expensive kind — to learn
     * "nobody is listening" over and over. Emission must be free for tenants who do not use it,
     * otherwise 16C taxes the hot accounting path for a feature they never enabled.
     *
     * KEYED BY DATABASE because that is exactly what tenant isolation is here: one tenant, one
     * database. Company is NOT part of the key — the query is tenant-wide (this model has no
     * company scope) and company authorization is applied per-subscription afterwards by
     * authorizesCompany(), so one cached set serves every company in the tenant.
     *
     * STALENESS is bounded on both sides. In-process, WebhookSubscription::booted() flushes on
     * every write, so a subscription created and used in one process (the prove commands, the
     * importer) is seen immediately. Cross-process, PHP-FPM gives each request fresh statics, so a
     * subscription created in one request is live for the next — nothing to invalidate.
     *
     * @var array<string, list<WebhookSubscription>>
     */
    private static array $liveCache = [];

    /** @var array<string, true> databases confirmed to have the table. Only TRUE is cached — see tableExists(). */
    private static array $tableCache = [];

    /**
     * Called by WebhookSubscription::booted() on any write, so an in-process change is never missed.
     *
     * Clears BOTH memos. It used to clear only $liveCache, which made it a half-reset: callers that
     * reasonably expect "flush the cache" to mean it — a teardown-and-reprovision of the same
     * database name inside one process, which every prove command does — kept a stale table-exists
     * verdict. Cheap to redo; a reset that silently leaves state behind is not a reset.
     */
    public static function flushCache(): void
    {
        self::$liveCache = [];
        self::$tableCache = [];
    }

    /**
     * Register an emission to run after the CURRENT transaction commits.
     *
     * With no open transaction this fires immediately; inside one it defers to the commit and is
     * DISCARDED on rollback. That single primitive gives us post-commit-only semantics for free —
     * see the class docblock.
     *
     * The callback body is itself wrapped by emit(), so nothing here can throw into the caller.
     */
    public function afterCommit(string $eventType, callable $payloadFactory, ?int $companyId = null): void
    {
        // Resolve the company NOW (inside the request/tenant context), not at callback time.
        $companyId ??= ActiveCompany::id();

        DB::afterCommit(function () use ($eventType, $payloadFactory, $companyId) {
            $this->emit($eventType, $payloadFactory, $companyId);
        });
    }

    /**
     * Fan an event out to every matching live subscription, creating one snapshotted delivery row
     * each. Safe to call directly when you are already provably post-commit (the dispatcher's test
     * event does).
     *
     * @param  callable():?array  $payloadFactory  deferred so we never build a payload nobody wants
     * @return int  deliveries created (0 when nothing matches — the overwhelmingly common case)
     */
    public function emit(string $eventType, callable $payloadFactory, ?int $companyId = null): int
    {
        try {
            if (! WebhookEvents::isValid($eventType)) {
                return 0;   // not in the catalog — refuse rather than invent an event
            }

            $subscriptions = $this->matching($eventType, $companyId);

            if ($subscriptions === []) {
                return 0;   // nobody is listening: do not pay to build the payload
            }

            $payload = $payloadFactory();

            if (! is_array($payload)) {
                return 0;   // the factory declined (e.g. the voucher vanished) — nothing to send
            }

            $eventId = (string) Str::ulid();   // stable across every retry of THIS event
            $created = 0;

            foreach ($subscriptions as $sub) {
                // ONE SUBSCRIPTION'S FAILURE MUST NOT COST THE OTHERS THEIR EVENTS.
                //
                // This guard is per-row on purpose. With a single try around the whole loop, one
                // bad subscription aborted it and every subscription after it silently lost the
                // event — swallowed by the outer catch, so nothing surfaced. That was reachable
                // from the ordinary tenant UI: a query-builder delete left a stale row in the
                // live-subscription cache, its INSERT failed the foreign key, and deleting ONE
                // webhook stopped every OTHER webhook in the tenant from being delivered to.
                //
                // The cache-staleness cause is fixed at its source, but fanning out to N
                // independent receivers must be independent per receiver regardless — a future
                // caller must not be able to reintroduce this by forgetting to flush.
                try {
                    WebhookDelivery::create([
                        'webhook_subscription_id' => $sub->id,
                        'event_id' => $eventId,
                        'event_type' => $eventType,
                        'company_id' => $companyId,
                        'payload_json' => $this->envelope($eventType, $eventId, $companyId, $payload),
                        'attempt_count' => 0,
                        'status' => WebhookDelivery::STATUS_PENDING,
                        'next_attempt_at' => now(),
                        'created_at' => now(),
                    ]);
                    $created++;
                } catch (Throwable $e) {
                    Log::warning('webhook.queue_failed_for_subscription', [
                        'subscription_id' => $sub->id,
                        'event_type' => $eventType,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            return $created;
        } catch (Throwable $e) {
            // GUARANTEE 1. The voucher is already committed and correct. Never rethrow.
            Log::warning('webhook.emit_failed', [
                'event_type' => $eventType,
                'company_id' => $companyId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Does ANY live subscription want this event for this company?
     *
     * Lets a caller skip expensive payload work (e.g. the outstanding delta queries) when nobody
     * is listening — which is every tenant that has not configured webhooks.
     */
    public function wants(string $eventType, ?int $companyId = null): bool
    {
        try {
            return $this->matching($eventType, $companyId ?? ActiveCompany::id()) !== [];
        } catch (Throwable) {
            return false;   // never let a lookup failure reach the caller
        }
    }

    /** Live subscriptions that want this event AND are authorized for this company. */
    private function matching(string $eventType, ?int $companyId): array
    {
        return array_values(array_filter(
            $this->live(),
            fn (WebhookSubscription $s) => $s->wantsEvent($eventType) && $s->authorizesCompany($companyId),
        ));
    }

    /** Every live subscription in the current tenant, read once per process. See $liveCache. */
    private function live(): array
    {
        $db = DB::connection()->getDatabaseName();

        if (array_key_exists($db, self::$liveCache)) {
            return self::$liveCache[$db];
        }

        if (! $this->tableExists($db)) {
            return [];
        }

        return self::$liveCache[$db] = WebhookSubscription::query()
            ->where('is_active', true)
            ->whereNull('disabled_at')
            ->get()
            ->all();
    }

    /**
     * Does this tenant have the 16C table? A tenant mid-migration does not — emit nothing, silently.
     *
     * ONLY A TRUE ANSWER IS CACHED, and flushCache() clears it. The original justification here
     * claimed "a table cannot un-exist under a running tenant" — which is false: a teardown drops
     * the database outright, and the prove commands tear down and re-provision the same name within
     * one process. The memo is safe because it is *flushed*, not because the underlying fact is
     * immutable. A cached FALSE would be worse still: provisioning creates the table and then posts
     * in the SAME process, so a false memoised before the migration would silence emission for the
     * rest of it. Re-querying on false costs an
     * information_schema hit only in the rare window where the table is genuinely absent.
     */
    private function tableExists(string $db): bool
    {
        if (isset(self::$tableCache[$db])) {
            return true;
        }

        if (! Schema::hasTable('webhook_subscriptions')) {
            return false;
        }

        self::$tableCache[$db] = true;

        return true;
    }

    /**
     * The event envelope the customer receives. Stable shape across every event type:
     *
     *   { event, event_id, company_id, occurred_at, data: <the resource> }
     *
     * `data` is the customer's own resource (their voucher, their party) — never another tenant's,
     * never a platform secret, never an internal identifier that leaks system structure.
     */
    private function envelope(string $eventType, string $eventId, ?int $companyId, array $data): array
    {
        return [
            'event' => $eventType,
            'event_id' => $eventId,
            'company_id' => $companyId,
            'occurred_at' => now()->toIso8601String(),
            'data' => $data,
        ];
    }
}
