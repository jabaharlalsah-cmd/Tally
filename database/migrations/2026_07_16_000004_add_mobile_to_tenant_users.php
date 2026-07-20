<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 — capture the tenant owner's mobile number alongside their login.
 * Collected on the manual-provision form and editable from the tenant profile.
 * Central database (tenant_users lives centrally).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->string('mobile', 32)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn('mobile');
        });
    }
};
