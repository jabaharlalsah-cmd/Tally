<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14A — CENTRAL database.
 *
 * A tenant admin user created by self-signup cannot log in until they confirm their
 * email. `verified_at` records that confirmation (Laravel's MustVerifyEmail contract,
 * implemented on TenantUser against this column rather than the default
 * `email_verified_at` name). Users created by the CLI (zerobook:tenant-user) are
 * back-office/support accounts and are considered verified immediately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn('verified_at');
        });
    }
};
