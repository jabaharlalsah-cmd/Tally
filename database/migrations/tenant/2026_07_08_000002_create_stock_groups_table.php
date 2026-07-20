<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6A — Stock Groups (Tally "Stock Groups" master).
 *
 * Self-referencing hierarchy exactly like account_groups: parent_id null = a
 * top-level group ("Primary"). restrictOnDelete so a parent with children is
 * never orphaned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('alias')->nullable();
            $table->foreignId('parent_id')->nullable()
                ->constrained('stock_groups')->restrictOnDelete();
            $table->timestamps();

            $table->unique('name');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_groups');
    }
};
