<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16C — outbound webhooks. Tenant database.
 *
 * Two tables:
 *
 *  • webhook_subscriptions — where a customer registers an endpoint, which events it wants, and
 *    which companies it may hear about.
 *  • webhook_deliveries    — one row per (event × matching subscription). The event payload is
 *    SNAPSHOTTED here at emission, so a later voucher change never alters what a pending delivery
 *    sends. This table is also the retry queue: a scheduled worker claims due rows and POSTs them.
 *
 * THE SECRET IS ENCRYPTED, NOT HASHED — the deliberate inversion of 16A.
 * 16A hashes API keys because it only ever VERIFIES what a client sends: a one-way digest is
 * enough, and is strictly safer. A webhook secret is the mirror image — ZeroBook must SIGN
 * outgoing requests with it, so it has to be recoverable at delivery time. Hashing it would make
 * signing impossible. It is therefore stored encrypted (Laravel's `encrypted` cast, APP_KEY), and
 * shown to the customer exactly once at create/rotate so they can verify signatures on their end.
 * This difference is real and correct; getting it "consistent with 16A" would break the phase.
 *
 * Notes on columns that look like mistakes but are not:
 *  • No tenant_id (the connection IS the tenant — the 16A convention).
 *  • No company_id on the subscription: a subscription may span companies, so authorization is a
 *    JSON id list (empty = all), matching api_keys' authorized_company_ids_json.
 *  • `company_id` on the DELIVERY records which company's event it was — needed to prove company
 *    isolation after the fact, and to filter the delivery log.
 *  • `event_id` is stable ACROSS retries of the same event (that is the whole point: the receiver
 *    dedupes on it). `id` is per-delivery-row; the ULID in X-ZeroBook-Delivery is per ATTEMPT.
 *  • `claimed_at` + status='delivering' is the single-flight claim (see WebhookDispatcher).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->string('url', 2048);
            $table->string('description', 191)->nullable();
            $table->json('event_types_json');                 // ['voucher.created', …] or ['*']
            $table->json('authorized_company_ids_json');      // [1,2] — empty array = every company

            // ENCRYPTED at rest (Crypt/APP_KEY), NOT hashed — ZeroBook signs with it. See docblock.
            $table->text('secret');

            $table->boolean('is_active')->default(true);

            // Central tenant_users.id — no FK possible across databases (the 16A convention).
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('created_by_email')->nullable();

            $table->timestamp('last_delivery_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['is_active', 'disabled_at']);      // the emitter's match scan
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('webhook_subscription_id');

            // Stable across every retry of this event — the receiver's dedup key.
            $table->string('event_id', 26);
            $table->string('event_type', 60);
            $table->unsignedBigInteger('company_id')->nullable();   // which company's event

            // The payload as it was TRUE AT EMISSION. Never re-read from the voucher at send time.
            $table->json('payload_json');

            $table->unsignedTinyInteger('attempt_count')->default(0);
            // pending | delivering | succeeded | failed | exhausted
            $table->string('status', 12)->default('pending');

            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('claimed_at')->nullable();            // single-flight claim stamp
            $table->timestamp('last_attempted_at')->nullable();
            $table->unsignedSmallInteger('last_response_status')->nullable();
            // The CUSTOMER's endpoint response, truncated — for their debugging. Never our data.
            $table->string('last_response_body_excerpt', 500)->nullable();
            $table->timestamp('succeeded_at')->nullable();

            $table->timestamp('created_at')->nullable();

            // The worker's due scan: due rows, oldest first.
            $table->index(['status', 'next_attempt_at']);
            $table->index(['webhook_subscription_id', 'id']);       // the delivery log (cursor)
            $table->index('event_id');

            $table->foreign('webhook_subscription_id')->references('id')->on('webhook_subscriptions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');   // FK child first
        Schema::dropIfExists('webhook_subscriptions');
    }
};
