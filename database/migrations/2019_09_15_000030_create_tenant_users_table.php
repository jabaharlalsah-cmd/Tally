<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7B — CENTRAL database.
 *
 * The people who can log into a TENANT's accounting app. Membership + credentials
 * live centrally so one email can belong to MANY tenants (normal for a CA managing
 * several client books) — the unique key is (tenant_id, email), not email alone.
 * The tenant login authenticates against the row for the CURRENT subdomain's tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('role')->default('member'); // owner | accountant | member
            $table->rememberToken();
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
            $table->index('email');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
    }
};
