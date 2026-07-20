<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6A — Godowns / Locations (Tally "Godowns" master).
 *
 * Hierarchical location master (sub-godowns under a location). Tally auto-creates
 * a default "Main Location"; ZeroBook seeds it here so every stock item has
 * somewhere to hold its opening balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('godowns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()
                ->constrained('godowns')->restrictOnDelete();
            $table->boolean('is_reserved')->default(false); // Main Location — non-deletable
            $table->timestamps();

            $table->unique('name');
            $table->index('parent_id');
        });

        DB::table('godowns')->insert([
            'name' => 'Main Location',
            'parent_id' => null,
            'is_reserved' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('godowns');
    }
};
