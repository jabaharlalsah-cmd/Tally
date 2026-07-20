<?php

namespace App\Services\Api\Webhooks;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Notifications\WebhookSubscriptionDisabled;
use App\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 16C — the delivery worker. Claims due rows, POSTs them signed, records the outcome.
 *
 * Runs entirely out-of-band (a per-minute scheduled command), never in a voucher post's path.
 *
 * SINGLE-FLIGHT CLAIM — why an atomic UPDATE and not a SELECT-then-UPDATE.
 * A slow delivery can run past the next minute's tick, so two dispatcher passes can overlap. A
 * read-then-write claim has a race window in which both passes see the same 'pending' row and both
 * POST it — the customer is billed twice, or their endpoint sees a duplicate that no retry
 * explains. Instead each row is claimed with a CONDITIONAL update:
 *
 *     UPDATE webhook_deliveries SET status='delivering', claimed_at=now()
 *      WHERE id=? AND status IN ('pending','failed')
 *
 * MySQL reports how many rows that actually changed. Exactly one pass gets 1; the loser gets 0 and
 * skips. The database's row lock is the arbiter — the same discipline 16B uses for idempotency,
 * where a unique index (not a check) decides the winner.
 *
 * A worker that dies mid-POST would strand its row in 'delivering' forever, so a claim older than
 * config('webhooks.claim_ttl') is reclaimable — the row is retried rather than lost.
 *
 * ERROR POLICY — the inverse of HostingerService (the only other outbound-HTTP caller here). That
 * one calls bare ->throw(); a webhook must never throw: a dead customer endpoint is an expected
 * daily event, not an exception.
 */
class WebhookDispatcher
{
    public function __construct(private WebhookEmitter $emitter) {}

    /**
     * Deliver every due row for the CURRENT tenant. Returns [attempted, succeeded, failed].
     *
     * Assumes it is already inside a tenant context (the command handles the tenancy loop).
     */
    public function dispatchDue(?int $limit = null): array
    {
        if (! Schema::hasTable('webhook_deliveries')) {
            return [0, 0, 0];   // tenant not yet migrated to 16C
        }

        $limit ??= (int) config('webhooks.batch', 50);
        $attempted = $succeeded = $failed = 0;

        // A TIME BUDGET, because the row count alone does not bound how long this takes. 50 rows
        // pointed at a hanging endpoint is 50 × the 10s timeout = 500s — well past the scheduler's
        // 5-minute overlap lock, so the lock would expire mid-tick precisely when endpoints are
        // slow, and ticks would pile up. Bounding the WORK is the honest fix; bounding the lock
        // alone just moves the guess. Unreached rows stay pending and the next tick takes them —
        // nothing is dropped, delivery just spreads across ticks under load.
        $deadline = microtime(true) + (float) config('webhooks.max_seconds', 240);

        foreach ($this->dueRows($limit) as $row) {
            if (microtime(true) >= $deadline) {
                Log::info('webhook.dispatch_budget_reached', ['attempted' => $attempted]);
                break;
            }

            if (! $this->claim($row)) {
                continue;   // another pass won this row — never double-POST
            }

            $attempted++;

            // attempt() is written not to throw, but this loop must survive being wrong about that.
            // One unexpected error on row 3 must not abandon rows 4..50 — and must not abort the
            // tenant loop in WebhookDispatchCommand and starve every tenant after this one. The row
            // stays claimed; its claim_ttl makes it due again on a later pass.
            try {
                $this->attempt($row->fresh()) ? $succeeded++ : $failed++;
            } catch (Throwable $e) {
                $failed++;
                Log::error('webhook.attempt_crashed', [
                    'delivery_id' => $row->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [$attempted, $succeeded, $failed];
    }

    /**
     * Candidate rows: due, not finished, and not currently claimed by a live worker.
     *
     * The stale-claim window lets an abandoned 'delivering' row (its worker died) become due again
     * instead of stranding forever.
     */
    private function dueRows(int $limit)
    {
        $staleBefore = now()->subSeconds((int) config('webhooks.claim_ttl', 120));

        return WebhookDelivery::query()
            ->where('next_attempt_at', '<=', now())
            ->where(function ($q) use ($staleBefore) {
                $q->whereIn('status', [WebhookDelivery::STATUS_PENDING, WebhookDelivery::STATUS_FAILED])
                    ->orWhere(fn ($qq) => $qq->where('status', WebhookDelivery::STATUS_DELIVERING)
                        ->where('claimed_at', '<', $staleBefore));
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Atomically take ownership. Returns true only for the pass that actually flipped the row.
     *
     * The WHERE re-states the status the row must still be in, so a concurrent pass that already
     * claimed it changes 0 rows and loses. This is the whole concurrency guarantee.
     */
    private function claim(WebhookDelivery $row): bool
    {
        $staleBefore = now()->subSeconds((int) config('webhooks.claim_ttl', 120));

        $changed = WebhookDelivery::query()
            ->whereKey($row->id)
            // THE DUE-TIME PREDICATE MUST BE HERE TOO, not only in dueRows().
            //
            // claim() is the arbiter under overlap, so it has to re-state every condition that
            // made the row eligible — a WHERE that dueRows() enforces and claim() omits is not a
            // guard, because the row can change between the read and the claim. Concretely: two
            // ticks both read row X as due; tick A delivers, gets a 5xx, and schedules it 5s out;
            // tick B then claimed the same row — now 'failed', which claim accepted — and POSTed
            // again IMMEDIATELY, inside the backoff window it was supposed to wait out.
            ->where('next_attempt_at', '<=', now())
            ->where(function ($q) use ($staleBefore) {
                $q->whereIn('status', [WebhookDelivery::STATUS_PENDING, WebhookDelivery::STATUS_FAILED])
                    ->orWhere(fn ($qq) => $qq->where('status', WebhookDelivery::STATUS_DELIVERING)
                        ->where('claimed_at', '<', $staleBefore));
            })
            ->update([
                'status' => WebhookDelivery::STATUS_DELIVERING,
                'claimed_at' => now(),
            ]);

        return $changed === 1;
    }

    /** POST one claimed delivery and record the outcome. Never throws. */
    public function attempt(WebhookDelivery $row): bool
    {
        $sub = $row->subscription;

        if (! $sub) {
            $row->forceFill(['status' => WebhookDelivery::STATUS_EXHAUSTED])->save();

            return false;
        }

        // A SUBSCRIPTION THAT IS NOT LIVE RECEIVES NOTHING RIGHT NOW — but "not now" and "never"
        // are different answers, and conflating them destroys the customer's data.
        //
        // AUTO-DISABLED (disabled_at set) → terminal. The endpoint is dead; auto-disable exists
        // precisely to stop hammering it, and delivering the backlog would hammer it 6 more times
        // per queued row. Parked terminal rather than pending because nothing may ever re-enable
        // it, and the pruner only reclaims finished rows.
        //
        // PAUSED BY HAND (is_active = false) → DEFERRED, not killed. This is a temporary,
        // reversible action a customer takes during maintenance, fully expecting to resume. An
        // earlier version of this method treated both cases as terminal, so pausing a subscription
        // for even a moment permanently destroyed everything already queued — the customer resumed
        // and the events were simply gone. Deferral keeps them pending and re-checks later.
        //
        // The deferral is bounded: past the retention window the row is exhausted anyway, so a
        // subscription paused forever cannot accumulate pending rows forever.
        if (! $sub->isLive()) {
            $retentionDays = (int) config('webhooks.retention_days', 30);
            $tooOld = $row->created_at !== null && $row->created_at->lt(now()->subDays($retentionDays));

            if ($sub->isDisabled() || $tooOld) {
                $row->forceFill([
                    'status' => WebhookDelivery::STATUS_EXHAUSTED,
                    'claimed_at' => null,
                    'last_attempted_at' => now(),
                    'last_response_body_excerpt' => $sub->isDisabled()
                        ? 'Not sent: the subscription was disabled before this event became due.'
                        : 'Not sent: the subscription stayed paused past the retention window.',
                ])->save();

                return false;
            }

            // Paused: hand the row back, due again shortly. Not an attempt — attempt_count is
            // untouched, so a pause never eats into the delivery budget.
            $row->forceFill([
                'status' => WebhookDelivery::STATUS_PENDING,
                'claimed_at' => null,
                'next_attempt_at' => now()->addMinutes(5),
            ])->save();

            return false;
        }

        // COMPANY AUTHORIZATION IS RE-VERIFIED AT SEND TIME, not trusted from queue time.
        //
        // The emitter only queues rows a subscription is authorized for, but that check ages: a
        // subscription can be NARROWED between queueing and delivery, and narrowing is an ordinary,
        // encouraged action. Without this, tightening a scope still shipped every already-queued
        // payload for the companies just removed — the full voucher body, to an endpoint the
        // customer had just said must no longer receive it. Restricting a webhook must take effect
        // on the backlog too, or "restricted" only describes the future.
        //
        // This also keeps the invariant the delivery log is meant to satisfy — every row we SEND
        // satisfies authorizesCompany(company_id) — true after a narrowing, not just before one.
        if (! $sub->authorizesCompany($row->company_id)) {
            $row->forceFill([
                'status' => WebhookDelivery::STATUS_EXHAUSTED,
                'claimed_at' => null,
                'last_attempted_at' => now(),
                'last_response_body_excerpt' => 'Not sent: the subscription is no longer authorized for this event\'s company.',
            ])->save();

            return false;
        }

        $attempt = $row->attempt_count + 1;
        $body = json_encode($row->payload_json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = time();
        $deliveryId = (string) Str::ulid();   // per ATTEMPT; event_id stays stable across retries

        $headers = WebhookSigner::headers(
            $sub->secret, $row->event_type, $row->event_id, $deliveryId, $timestamp, $body
        );

        // THE TRY WRAPS THE HTTP CALL AND NOTHING ELSE. It used to span the succeed()/fail() writes
        // too, which meant any internal error AFTER a 2xx — a deadlock, a rejected excerpt — landed
        // in this catch and was recorded as a DELIVERY failure, re-POSTing an event the receiver had
        // already accepted. "The receiver said yes" and "our bookkeeping threw" are different
        // events and must never share a handler.
        try {
            $response = Http::withHeaders($headers + ['Content-Type' => 'application/json'])
                ->timeout((int) config('webhooks.timeout', 10))
                ->connectTimeout((int) config('webhooks.connect_timeout', 5))
                ->withBody($body, 'application/json')
                ->post($sub->url);
        } catch (Throwable $e) {
            // A timeout, DNS failure, refused connection — all expected for a customer endpoint.
            $this->fail($row, $sub, $attempt, null, $this->excerpt($e::class.': '.$e->getMessage()));

            return false;
        }

        $status = $response->status();
        $excerpt = $this->excerpt((string) $response->body());

        if (! $response->successful()) {
            $this->fail($row, $sub, $attempt, $status, $excerpt);

            return false;
        }

        try {
            $this->succeed($row, $sub, $attempt, $status, $excerpt);
        } catch (Throwable $e) {
            // The receiver ACCEPTED this event. A failure to write that down is our problem, and
            // re-sending would be the one outcome the customer must not get. The only untrusted
            // thing in the row is the excerpt, so drop it and record the success without it.
            Log::warning('webhook.succeed_write_failed', [
                'delivery_id' => $row->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $this->succeed($row, $sub, $attempt, $status, '');
        }

        return true;
    }

    /**
     * A response-body excerpt that is always safe to store.
     *
     * The body is whatever a customer's endpoint chose to return — it is NOT necessarily UTF-8. A
     * latin-1 error page ("Ungültige Anfrage") is invalid utf8mb4, and MySQL under
     * STRICT_TRANS_TABLES rejects the INSERT rather than coercing it. Str::limit does no encoding
     * work, so those bytes used to reach the driver intact and blow up the write. Scrub to valid
     * UTF-8 first, then truncate — the column is varchar(500) and MySQL counts characters.
     */
    private function excerpt(string $raw): string
    {
        $clean = mb_check_encoding($raw, 'UTF-8')
            ? $raw
            : mb_convert_encoding($raw, 'UTF-8', 'UTF-8');   // drops invalid byte sequences

        return Str::limit($clean, 500, '');
    }

    private function succeed(WebhookDelivery $row, WebhookSubscription $sub, int $attempt, int $status, string $excerpt): void
    {
        $row->forceFill([
            'status' => WebhookDelivery::STATUS_SUCCEEDED,
            'attempt_count' => $attempt,
            'last_attempted_at' => now(),
            'last_response_status' => $status,
            'last_response_body_excerpt' => $excerpt,
            'succeeded_at' => now(),
            'claimed_at' => null,
            // Nothing more is scheduled. dueRows() ignores a succeeded row regardless, but leaving
            // a due date on a finished delivery makes the log lie to anyone reading it.
            'next_attempt_at' => null,
        ])->save();

        // The endpoint is alive again — clear the auto-disable counter.
        $sub->forceFill(['consecutive_failures' => 0, 'last_delivery_at' => now()])->save();
    }

    private function fail(WebhookDelivery $row, WebhookSubscription $sub, int $attempt, ?int $status, string $excerpt): void
    {
        $backoff = WebhookDelivery::backoffFor($attempt);
        $exhausted = $backoff === null || $attempt >= WebhookDelivery::MAX_ATTEMPTS;

        $row->forceFill([
            'status' => $exhausted ? WebhookDelivery::STATUS_EXHAUSTED : WebhookDelivery::STATUS_FAILED,
            'attempt_count' => $attempt,
            'last_attempted_at' => now(),
            'last_response_status' => $status,
            'last_response_body_excerpt' => $excerpt,
            'next_attempt_at' => $exhausted ? null : now()->addSeconds($backoff),
            'claimed_at' => null,
        ])->save();

        $sub->forceFill(['last_delivery_at' => now()])->save();

        if ($exhausted) {
            // Only a fully-exhausted delivery counts toward auto-disable — a single 500 that later
            // succeeds on retry must not creep the counter toward disabling a healthy endpoint.
            $sub->increment('consecutive_failures');
            $this->autoDisableIfDead($sub->fresh());
        }
    }

    /**
     * A dead endpoint must not accumulate deliveries forever. Past the threshold the subscription
     * is disabled (the emitter then stops creating rows for it) and the account owner is emailed
     * so it is a visible event, not a silent stop.
     */
    private function autoDisableIfDead(WebhookSubscription $sub): void
    {
        $threshold = (int) config('webhooks.auto_disable_after', 20);

        if ($sub->isDisabled() || $sub->consecutive_failures < $threshold) {
            return;
        }

        $sub->forceFill([
            'disabled_at' => now(),
            'disabled_reason' => "Automatically disabled after {$sub->consecutive_failures} consecutive failed deliveries.",
        ])->save();

        try {
            $owners = TenantUser::where('tenant_id', tenant('id'))->where('role', 'owner')->get();
            if ($owners->isNotEmpty()) {
                Notification::send($owners, new WebhookSubscriptionDisabled($sub, (string) tenant('id')));
            }
        } catch (Throwable $e) {
            // Notifying is best-effort; the disable itself already happened and is what matters.
            Log::warning('webhook.disable_notify_failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Queue a signed `ping` so a customer can verify their integration before real events flow.
     * Returns the delivery count (0 when the subscription is not live).
     */
    public function sendTest(WebhookSubscription $sub): int
    {
        if (! $sub->isLive()) {
            return 0;
        }

        // ONE id, used for both the row and the body. They were two separate Str::ulid() calls, so
        // the X-ZeroBook-Event-Id header (built from the row) disagreed with the event_id in the
        // body — on the one event whose entire purpose is to let a customer check their signature
        // and dedupe handling against something real. We document "dedupe on event_id"; the test
        // event has to be the thing that proves it.
        $eventId = (string) Str::ulid();

        // Stamp the company only if this subscription is actually authorized to hear about it.
        // Every row the emitter writes satisfies authorizesCompany(company_id); a test event
        // stamped from ambient state would be the only row in the table that does not, and the
        // delivery log is what a customer reads to audit what we sent them.
        $active = \App\Support\ActiveCompany::id();
        $companyId = ($active !== null && $sub->authorizesCompany($active)) ? $active : null;

        $delivery = WebhookDelivery::create([
            'webhook_subscription_id' => $sub->id,
            'event_id' => $eventId,
            'event_type' => WebhookEvents::PING,
            'company_id' => $companyId,
            'payload_json' => [
                'event' => WebhookEvents::PING,
                'event_id' => $eventId,
                'company_id' => $companyId,
                'occurred_at' => now()->toIso8601String(),
                'data' => ['message' => 'This is a test event from ZeroBook. Your endpoint is reachable and your signature check can be verified against it.'],
            ],
            'attempt_count' => 0,
            'status' => WebhookDelivery::STATUS_PENDING,
            'next_attempt_at' => now(),
            'created_at' => now(),
        ]);

        return $delivery ? 1 : 0;
    }

    /**
     * Re-queue a specific past delivery. The payload is the ORIGINAL snapshot — a redelivery
     * re-sends what was true at emission, exactly like a retry, so it can never invent a new fact.
     */
    public function redeliver(WebhookDelivery $row): WebhookDelivery
    {
        // A ROW THAT HAS NOT FINISHED IS ALREADY GOING TO BE SENT — there is nothing to re-send.
        //
        // Without this, "redeliver" on a still-queued row minted a SECOND live copy of the same
        // event_id, and the next tick POSTed both. We tell integrators to dedupe on event_id, so
        // that is survivable, but it is us generating the duplicate rather than the network — and
        // the button is right there in the delivery log next to rows that are merely slow. Hand
        // back the row already in flight instead.
        if (in_array($row->status, [WebhookDelivery::STATUS_PENDING, WebhookDelivery::STATUS_DELIVERING], true)) {
            return $row;
        }

        return WebhookDelivery::create([
            'webhook_subscription_id' => $row->webhook_subscription_id,
            'event_id' => $row->event_id,          // SAME event id — the receiver still dedupes
            'event_type' => $row->event_type,
            'company_id' => $row->company_id,
            'payload_json' => $row->payload_json,  // the original snapshot, untouched
            'attempt_count' => 0,
            'status' => WebhookDelivery::STATUS_PENDING,
            'next_attempt_at' => now(),
            'created_at' => now(),
        ]);
    }
}
