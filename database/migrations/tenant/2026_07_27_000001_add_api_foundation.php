<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16A — the customer-integration API foundation, per tenant.
 *
 * Two tables, both deliberately in the TENANT database:
 *
 *  • api_keys        — the AUTHORITATIVE key row. Hash, scopes, authorized companies,
 *                      revocation. Living here is what makes "tenant A cannot see tenant B's
 *                      keys" a property of the schema rather than of a WHERE clause.
 *                      The central `api_key_directory` only maps prefix → tenant so the
 *                      middleware knows which database to open; every security decision is
 *                      made against THIS row.
 *
 *  • api_request_log — the first tenant-side audit log in the app (both existing audit logs,
 *                      platform_admin_actions and tenant_lifecycle_events, are central). It
 *                      follows their append-only convention: created_at only, no updated_at.
 *
 * Notes on columns that look like mistakes but are not:
 *
 *  - There is NO `tenant_id` column. The connection IS the tenant; a column would be a second
 *    source of truth that could disagree with the database the row was read from.
 *
 *  - `created_by_user_id` / `revoked_by_user_id` carry NO foreign key. Tenant users live in the
 *    CENTRAL `tenant_users` table (TenantUser uses UsesCentralConnection) while these rows live
 *    in the tenant DB — a cross-database FK cannot be enforced by MySQL. The email snapshot
 *    beside each id is what keeps the audit trail readable after a user is deleted or renamed.
 *
 *  - `api_keys` intentionally does NOT get a `company_id` + BelongsToCompany treatment. That
 *    trait's global scope filters by the ACTIVE company — but at key-lookup time no company is
 *    active yet; resolving it is precisely what the key row is being read to do. A scoped model
 *    would appear to work (the scope is inert while the id is null) and then silently return
 *    zero rows the moment anything set a company first. Authorized companies are stored as a
 *    JSON id list and validated explicitly instead.
 *
 *  - `request_body_hash` is a SHA-256 hex digest, never the body. Bodies are PII and unbounded;
 *    the hash is enough to answer "did the same payload arrive twice?" during support.
 *
 *  - The prefix index is tenant-wide, NOT composite with company: the lookup happens before the
 *    company is known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();

            $table->string('name', 120);                 // user's label — "HMS Production"
            $table->string('prefix', 32);                // 'zb_live_a1b2c3d4' — plaintext handle
            $table->string('key_hash');                  // bcrypt of the FULL raw key (Hash::make)

            $table->json('permissions_json');            // ['voucher:create', …] or ['*']
            $table->json('authorized_company_ids_json'); // [1,2] — empty array = every company

            // Null = fall back to config('zerobook.api.rate_limit_per_min').
            $table->unsignedSmallInteger('rate_limit_per_min')->nullable();

            // Central tenant_users.id — no FK possible across databases (see docblock).
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('created_by_email')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->string('revoked_by_email')->nullable();

            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->unique('prefix');   // the auth lookup — one row per prefix, tenant-wide
            $table->index('revoked_at');
        });

        Schema::create('api_request_log', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('api_key_id');
            $table->string('request_id', 26);            // ULID, echoed as X-Request-Id
            $table->string('method', 10);
            $table->string('path', 255);
            $table->string('query_string', 512)->nullable();
            $table->char('request_body_hash', 64)->nullable();  // SHA-256 hex — never the body
            $table->unsignedSmallInteger('response_status');
            $table->unsignedInteger('duration_ms');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            // Append-only: created_at only, mirroring platform_admin_actions / tenant_lifecycle_events.
            $table->timestamp('created_at')->nullable();

            $table->index(['api_key_id', 'created_at']);
            $table->index('request_id');

            $table->foreign('api_key_id')->references('id')->on('api_keys')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_log');   // FK child first
        Schema::dropIfExists('api_keys');
    }
};
