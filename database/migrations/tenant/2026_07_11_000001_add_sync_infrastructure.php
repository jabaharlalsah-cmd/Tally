<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7C — desktop sync infrastructure (per-tenant).
 *
 * Two additions, both tenant-local:
 *
 *  1. vouchers.client_uuid — an idempotency key the desktop assigns to each
 *     offline-entered voucher. The sync PUSH endpoint dedupes on it, so re-sending
 *     a batch after a dropped response never double-posts. It is set by the sync
 *     layer AFTER VoucherScreen::post() runs, so post() itself is unchanged.
 *
 *  2. sync_changes — the pull change-log. Every voucher create/update/delete (from
 *     ANY source: the web SaaS, another desktop, the Tally importer) appends a row
 *     via the RecordsSyncChanges trait. Its auto-increment id is the pull cursor, so
 *     /api/sync/pull?since=<id> returns exactly the changes a desktop hasn't seen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->uuid('client_uuid')->nullable()->unique()->after('number');
        });

        Schema::create('sync_changes', function (Blueprint $table) {
            $table->bigIncrements('id'); // the monotonic pull cursor
            $table->string('entity', 40);  // 'voucher'
            $table->unsignedBigInteger('record_id');
            $table->enum('op', ['created', 'updated', 'deleted']);
            $table->timestamp('occurred_at');

            $table->index(['entity', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_changes');
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
