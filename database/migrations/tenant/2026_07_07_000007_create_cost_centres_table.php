<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5D — Cost Centres master.
 *
 * A cost centre can sit under a parent cost centre (like account groups). A single
 * implicit "Primary" cost category is assumed this phase; a category layer can be
 * added later by adding a nullable cost_category_id here without a rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centres', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()
                ->constrained('cost_centres')->restrictOnDelete();
            $table->timestamps();

            $table->unique('name');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centres');
    }
};
