<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16B — idempotency keys for the Core REST API. Tenant database.
 *
 * When an HMS's HTTP client retries a POST that already succeeded (a network hiccup mid-flight),
 * the patient must not be billed twice. Every write endpoint requires an `Idempotency-Key`
 * header; this table is the arbiter.
 *
 * THE FLOW IS INSERT-FIRST, NOT CHECK-THEN-INSERT — the unique index (api_key_id,
 * idempotency_key) is the single-flight lock. A write INSERTs a `pending` row before doing any
 * work: the first request wins the row and proceeds; a concurrent identical request hits the
 * unique violation and replays (or waits) instead of posting a second voucher. This is why the
 * insert must be its OWN statement, distinct from VoucherScreen::post()'s internal numbering
 * transaction — post() also throws a 1062 for voucher-number collisions, and conflating the two
 * would be a correctness bug.
 *
 * Columns:
 *  - No tenant_id (the connection IS the tenant, per the 16A convention).
 *  - `api_key_id` gets a REAL same-DB FK to api_keys (both live here, unlike 16A's cross-DB user
 *    id). Scoping the key per api_key means two integrations can reuse the same client-chosen
 *    string without colliding.
 *  - `request_body_hash` (SHA-256) detects key reuse with a DIFFERENT body → 409.
 *  - `response_status` NULL marks a row still in-flight; a concurrent hit gets 409 + Retry-After.
 *  - `response_body` stores ONLY the success response to replay — never the raw request body.
 *  - `resource_type`/`resource_id` let a later audit trace which voucher/ledger a key created.
 *  - `expires_at` = 48h; a daily prune sweeps past it so retries stay meaningful but the table
 *    does not grow without bound.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('api_key_id');
            $table->string('idempotency_key', 255);       // opaque, client-chosen
            $table->char('request_body_hash', 64);        // SHA-256 hex of the request body

            // NULL until the operation finishes — a NULL row is "in flight" (concurrent hit → 409).
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();     // the exact success body to replay

            $table->string('resource_type', 40)->nullable();   // 'voucher' | 'ledger' | 'stock_item'
            $table->unsignedBigInteger('resource_id')->nullable();

            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // The single-flight arbiter: one (api_key_id, idempotency_key) row, tenant-wide.
            $table->unique(['api_key_id', 'idempotency_key'], 'api_idem_key_unique');
            $table->index('expires_at');   // prune scan

            $table->foreign('api_key_id')->references('id')->on('api_keys')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
