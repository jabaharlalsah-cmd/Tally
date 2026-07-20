<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14A — CENTRAL database. Extends the tenant registry for SaaS operations.
 *
 *   • trial_ends_at   — when the free trial lapses. zerobook:trial-check flips an
 *                       active trial tenant to 'expired_trial' once now() passes it.
 *                       NULL = no trial window (a converted/paid tenant).
 *   • verified_at     — when the admin user confirmed the signup email. Until set,
 *                       the tenant sits in status 'pending_verification' and nobody
 *                       can log in.
 *   • last_active_at  — touched by TrackTenantActivity on any tenant request, so the
 *                       platform admin can see which tenants are live.
 *
 * IMPORTANT: because App\Models\Tenant is a stancl VirtualColumn model, a new REAL
 * column is invisible to the model unless it is ALSO added to Tenant::getCustomColumns()
 * — otherwise the attribute round-trips through the JSON `data` column instead. This
 * migration and that array are edited together.
 *
 * `status` gains no enum change (it is a plain string): the new values
 * 'pending_verification' and 'expired_trial' join the existing
 * provisioning|active|suspended|cancelled set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable()->after('provisioned_at');
            $table->timestamp('verified_at')->nullable()->after('trial_ends_at');
            $table->timestamp('last_active_at')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['trial_ends_at', 'verified_at', 'last_active_at']);
        });
    }
};
