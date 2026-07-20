<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14C — CENTRAL database. Backups, exports, and the offboarding lifecycle.
 *
 *   • tenant_backups         — one row per stored (verified) backup of a tenant DB.
 *   • tenant_exports         — one row per customer data-export archive.
 *   • tenant_lifecycle_events— the offboarding audit trail (every transition).
 *   • tenants +3 columns     — the offboarding schedule dates.
 *
 * `tenants.status` gains string values `restored`, `archived`, `purge_scheduled`, `purged`
 * (it is a plain VARCHAR — no enum change). NO tenant-DB change; backup/export/restore all
 * operate ON tenant databases, they do not alter their schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_backups', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('type')->default('scheduled'); // scheduled | on_demand | pre_offboarding
            $table->string('file_path');
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->json('meta')->nullable(); // voucher/entry counts for restore-verification
            $table->timestamp('taken_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'taken_at']);
            $table->index('expires_at');
        });

        Schema::create('tenant_exports', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->unsignedBigInteger('initiated_by_user_id')->nullable();
            $table->foreignId('initiated_by_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->string('status')->default('queued'); // queued | processing | completed | failed
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('download_expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('tenant_lifecycle_events', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            // offboarding_initiated | reactivated | archived | purge_scheduled | purged
            // | backup_taken | export_generated | restored
            $table->string('event');
            $table->unsignedBigInteger('triggered_by_user_id')->nullable();
            $table->foreignId('triggered_by_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('offboarding_initiated_at')->nullable()->after('plan_ends_at');
            $table->timestamp('archive_scheduled_for')->nullable()->after('offboarding_initiated_at');
            $table->timestamp('purge_scheduled_for')->nullable()->after('archive_scheduled_for');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['offboarding_initiated_at', 'archive_scheduled_for', 'purge_scheduled_for']);
        });
        Schema::dropIfExists('tenant_lifecycle_events');
        Schema::dropIfExists('tenant_exports');
        Schema::dropIfExists('tenant_backups');
    }
};
