<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-level F11 feature switches. A single row (single-company for now).
 * These gate the already-scaffolded ledger/group fields; the actual bill-wise /
 * cost-centre / GST / multi-currency functionality arrives in later phases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_features', function (Blueprint $table) {
            $table->id();
            $table->boolean('bill_by_bill')->default(false);
            $table->boolean('cost_centres')->default(false);
            $table->boolean('gst')->default(false);
            $table->boolean('multi_currency')->default(false);
            $table->timestamps();
        });

        DB::table('company_features')->insert([
            'bill_by_bill' => false, 'cost_centres' => false, 'gst' => false, 'multi_currency' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('company_features');
    }
};
