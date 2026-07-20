<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 — password-reset tokens for the TENANT guard.
 *
 * Separate from the platform + users token tables so guards can never collide. The
 * user lookup during reset is additionally scoped by tenant_id (the subdomain), so the
 * reset is bound to the tenant the request came in on. Central database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_password_reset_tokens');
    }
};
