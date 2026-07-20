<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14B (Manual) — CENTRAL database.
 *
 * `plan_ends_at` is the paid-subscription end date, SEPARATE from 14A's `trial_ends_at`.
 * A tenant may hold both (a paid subscription that begins after the trial). It is the
 * source of truth for paid access: recomputed deterministically from the tenant's
 * confirmed payments (SubscriptionService::recomputePlanEnd). NULL = no paid subscription.
 *
 * Like the 14A columns, this is a REAL column and MUST also be listed in
 * Tenant::getCustomColumns() (stancl VirtualColumn) or it overflows into `data`.
 *
 * `status` gains one new string value: 'expired_subscription' (a paid subscription that
 * lapsed past its grace period) — joins provisioning|pending_verification|active|
 * suspended|expired_trial|cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('plan_ends_at')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('plan_ends_at');
        });
    }
};
