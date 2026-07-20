<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9A — the filing log every CA firm keeps somewhere.
 *
 * ZeroBook does not talk to the GSTN portal (that needs a commercial GSP), so nothing
 * here is auto-populated: after uploading the generated JSON the user records the
 * Acknowledgement Reference Number (ARN) the portal returns. One row per
 * (period, return_type) — filing the same return twice for a period overwrites the ARN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gst_return_filings', function (Blueprint $table) {
            $table->id();
            $table->char('period', 6);                                  // MMYYYY, e.g. 042026
            $table->enum('return_type', ['gstr1', 'gstr3b']);
            $table->timestamp('filed_at')->nullable();
            $table->string('arn')->nullable();                          // e.g. AA1234567890ABC
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['period', 'return_type']);
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gst_return_filings');
    }
};
