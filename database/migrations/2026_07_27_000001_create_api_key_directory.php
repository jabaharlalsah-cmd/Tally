<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16A — the CENTRAL prefix → tenant router for API keys.
 *
 * Why this table exists (it is not in the 16A brief, and 16A does not work without it):
 *
 * The authoritative `api_keys` row lives in the PER-TENANT database — that is what keeps
 * "each tenant sees only its own keys" structurally true. But an API request arrives
 * carrying ONLY `Authorization: Bearer zb_live_…`. There is no subdomain, no session, no
 * cookie — nothing that says which tenant to open. So the very first thing the middleware
 * must do (resolve the tenant) cannot be answered by a table that can only be read AFTER
 * the tenant is resolved. Without a central index the only alternatives are to scan every
 * tenant database on every request (O(tenants), and it would touch every customer's DB to
 * serve one request) or to encode the tenant into the key (which the fixed
 * `zb_<env>_<32 random>` format forbids).
 *
 * So this table stores the ONE fact that must be known before a tenant connection exists:
 * which tenant a key prefix belongs to. It is a ROUTER, not an authority:
 *
 *   • It holds NO secret. The prefix is plaintext by design (it is the lookup handle); the
 *     hashed secret never leaves the tenant DB. Reading this whole table grants nothing.
 *   • It never decides auth. The hash verify AND the revoked/expiry check both happen against
 *     the tenant-DB row. A stale directory row therefore cannot authenticate anything — it can
 *     only point at a tenant whose own row then rejects the key.
 *
 * `revoked_at` here is a convenience MIRROR for platform-side housekeeping only. It is
 * deliberately NOT consulted on the auth path, so the tenant row stays the single source of
 * truth for revocation and the two can never disagree in a way that grants access.
 *
 * The FK cascades on tenant delete so a torn-down/purged tenant leaves no routing residue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_key_directory', function (Blueprint $table) {
            $table->id();

            // 'zb_live_a1b2c3d4' — plaintext, globally unique. The lookup handle, not a secret.
            $table->string('prefix', 32)->unique();

            // tenants.id IS the subdomain slug (string PK) — see create_tenants_table.
            $table->string('tenant_id');

            // Mirror only — housekeeping/reporting. NEVER read on the auth path.
            $table->timestamp('revoked_at')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_key_directory');
    }
};
