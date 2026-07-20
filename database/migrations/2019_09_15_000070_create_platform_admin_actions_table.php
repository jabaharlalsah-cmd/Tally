<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14A — CENTRAL database. The platform-admin audit log.
 *
 * Every consequential action a platform operator takes against a tenant is recorded
 * here, immutably: impersonation (start / write-toggle / end), suspend, reactivate,
 * extend-trial, and plan changes. This is the accountability spine of the support
 * surface — who did what, to which tenant, when, why, and from where.
 *
 *   admin_id        — the acting platform_admins row (kept even if the admin is later
 *                     removed → nullOnDelete, so the history survives).
 *   action          — impersonate_start | impersonate_write_on | impersonate_write_off
 *                     | impersonate_end | suspend | reactivate | extend_trial
 *                     | plan_change | provision
 *   tenant_id       — the target tenant slug.
 *   target_user_id  — the tenant_users row touched (the impersonated user), if any.
 *   reason          — free text the admin supplied (required for impersonation).
 *   meta            — small JSON payload for action specifics (days added, old/new plan…).
 *   ip_address / user_agent — request provenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admin_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->string('action');
            $table->string('tenant_id')->nullable();
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->text('reason')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['admin_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_actions');
    }
};
