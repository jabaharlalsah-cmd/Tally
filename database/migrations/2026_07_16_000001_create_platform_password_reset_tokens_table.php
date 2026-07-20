<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 — password-reset tokens for the PLATFORM-ADMIN guard.
 *
 * Kept in its own table (not the framework-default `password_reset_tokens`, which is the
 * `users` broker) so a platform admin and a tenant user that happen to share an email
 * address can never overwrite each other's reset token. Central database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_password_reset_tokens');
    }
};
