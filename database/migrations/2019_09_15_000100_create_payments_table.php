<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14B (Manual) — CENTRAL database. One table of manual subscription payments for
 * every tenant.
 *
 * A payment is either customer-notified (status 'pending', notified_by_user_id set — the
 * customer claims they paid; awaits admin confirmation) or admin-recorded (status
 * 'confirmed' immediately — the admin saw the money in the bank statement). Confirming a
 * payment extends the tenant's plan_ends_at; rejecting or reversing does not (reversal
 * recomputes plan_ends_at from the remaining confirmed payments).
 *
 * Every state change is audit-trailable: recorded_by / reversed_by admin, the proof
 * snapshot, and the subscription period the payment covers. The proof file lives in
 * PRIVATE storage (storage/app/private/payment-proofs/…) and is only ever served through
 * an access-controlled controller — never publicly linked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('recorded_by_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->unsignedBigInteger('notified_by_user_id')->nullable(); // tenant_users.id, when customer-initiated
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3); // INR | NPR
            $table->string('payment_mode'); // bank_transfer | upi | cheque | cash | other
            $table->string('reference_number')->nullable();
            $table->date('received_at'); // when the money actually arrived

            $table->string('proof_file_path')->nullable(); // relative path in the private disk
            $table->text('notes')->nullable();

            $table->string('status')->default('pending'); // pending | confirmed | rejected | reversed
            $table->string('invoice_number')->nullable();
            $table->string('invoice_file_path')->nullable();

            // Reversal audit.
            $table->foreignId('reversed_by_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();

            // Rejection reason (customer-facing).
            $table->text('rejection_reason')->nullable();

            // The subscription window this payment covers (recomputed on confirm/reverse).
            $table->date('subscription_period_start')->nullable();
            $table->date('subscription_period_end')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
