<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            // Phase 7B — the tenant registry (CENTRAL db). `id` is the subdomain slug
            // (string PK, not auto-increment); the tenant's own database is named
            // "tenant"+id (e.g. tenantalpha). These are REAL columns declared in
            // App\Models\Tenant::getCustomColumns(); anything else overflows to `data`.
            $table->string('id')->primary();

            $table->string('name');
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('status')->default('provisioning'); // provisioning|active|suspended|cancelled
            $table->timestamp('provisioned_at')->nullable();

            $table->timestamps();
            $table->json('data')->nullable(); // REQUIRED by VirtualColumn — do not omit
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
}
