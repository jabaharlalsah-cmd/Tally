<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9B — the Nepal VAT return filing log.
 *
 * ZeroBook does not talk to the IRD taxpayer portal (there is no public API for return
 * submission, and the VAT return is a web form). After submitting on the portal the
 * taxpayer records the submission reference here — the Nepali counterpart of GSTN's ARN.
 *
 * One row per BS period (`YYYY-MM`, e.g. `2082-04` = Shrawan 2082).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_return_filings', function (Blueprint $table) {
            $table->id();
            $table->char('period', 7);                 // BS YYYY-MM
            $table->string('submission_ref')->nullable();
            $table->timestamp('filed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_return_filings');
    }
};
