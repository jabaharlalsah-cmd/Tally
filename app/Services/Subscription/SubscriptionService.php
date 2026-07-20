<?php

namespace App\Services\Subscription;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\PaymentClaimReceived;
use App\Notifications\PaymentConfirmed;
use App\Notifications\PaymentRejected;
use App\Services\Platform\PlatformActions;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 14B (Manual) — the subscription + manual-payment authority.
 *
 * Lifecycle: recordPayment → (pending) confirmPayment / rejectPayment → (confirmed)
 * reversePayment. The tenant's plan_ends_at is NEVER extended ad-hoc; it is always the
 * output of recomputePlanEnd(), which replays the tenant's CONFIRMED payments in
 * chronological order. That single authority is what makes a mid-list reversal correct:
 * remove B, replay A then C, and C's period chains from A's end.
 */
class SubscriptionService
{
    public function __construct(
        private PlatformActions $actions,
        private InvoiceGenerator $invoices,
    ) {
    }

    /**
     * Create a payment row. status 'pending' (customer claim, notified_by_user_id set) or
     * 'confirmed' (admin-recorded straight from the bank statement). A confirmed record
     * immediately runs the full confirmation side-effects.
     *
     * @param  array{tenant_id:string,plan_id:int,amount:mixed,currency:string,payment_mode:string,reference_number:?string,received_at:mixed,proof_file_path:?string,notes:?string,status?:string,notified_by_user_id?:?int,recorded_by_admin_id?:?int}  $data
     */
    public function recordPayment(array $data, ?PlatformAdmin $admin = null): Payment
    {
        $tenant = Tenant::findOrFail($data['tenant_id']);
        $plan = Plan::findOrFail($data['plan_id']);
        $status = $data['status'] ?? 'pending';

        $period = $this->computeProvisionalPeriod($tenant, $plan, $data['received_at']);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'recorded_by_admin_id' => $data['recorded_by_admin_id'] ?? $admin?->id,
            'notified_by_user_id' => $data['notified_by_user_id'] ?? null,
            'plan_id' => $plan->id,
            'amount' => $data['amount'],
            'currency' => strtoupper($data['currency']),
            'payment_mode' => $data['payment_mode'],
            'reference_number' => $data['reference_number'] ?? null,
            'received_at' => Carbon::parse($data['received_at'])->toDateString(),
            'proof_file_path' => $data['proof_file_path'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => $status,
            'subscription_period_start' => $period['start'],
            'subscription_period_end' => $period['end'],
        ]);

        $this->actions->log($admin, 'payment_recorded', $tenant, [
            'meta' => ['payment_id' => $payment->id, 'status' => $status, 'amount' => (string) $payment->amount, 'currency' => $payment->currency],
        ]);

        if ($status === 'confirmed') {
            $this->applyConfirmation($payment, $admin);
        } else {
            $this->notifyAdminsOfClaim($payment);
        }

        return $payment->refresh();
    }

    /** Admin confirms a pending payment → extends plan_ends_at, invoices, emails, logs. */
    public function confirmPayment(Payment $payment, PlatformAdmin $admin): void
    {
        if (! $payment->isPending()) {
            return; // only a pending claim can be confirmed
        }

        $payment->forceFill([
            'status' => 'confirmed',
            'recorded_by_admin_id' => $payment->recorded_by_admin_id ?? $admin->id,
        ])->save();

        $this->applyConfirmation($payment, $admin);
    }

    /** Admin declines a pending payment with a reason. No subscription change. */
    public function rejectPayment(Payment $payment, PlatformAdmin $admin, string $reason): void
    {
        if (! $payment->isPending()) {
            return;
        }

        $payment->forceFill(['status' => 'rejected', 'rejection_reason' => $reason])->save();

        $this->actions->log($admin, 'payment_rejected', $payment->tenant, [
            'reason' => $reason,
            'meta' => ['payment_id' => $payment->id],
        ]);

        if ($owner = $this->tenantOwner($payment->tenant_id)) {
            $owner->notify(new PaymentRejected($payment, $reason));
        }
    }

    /** Reverse a confirmed payment → recompute plan_ends_at from the rest. Requires a reason. */
    public function reversePayment(Payment $payment, PlatformAdmin $admin, string $reason): void
    {
        if (! $payment->isConfirmed()) {
            return; // only a confirmed payment can be reversed
        }

        $tenant = $payment->tenant;

        DB::transaction(function () use ($payment, $admin, $reason, $tenant) {
            $payment->forceFill([
                'status' => 'reversed',
                'reversed_by_admin_id' => $admin->id,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ])->save();

            $this->recomputePlanEnd($tenant);
        });

        $this->applyExpiryTransition($tenant->fresh());

        $this->actions->log($admin, 'payment_reversed', $tenant, [
            'reason' => $reason,
            'meta' => ['payment_id' => $payment->id, 'new_plan_ends_at' => optional($tenant->fresh()->plan_ends_at)->toDateString()],
        ]);
    }

    /**
     * The single source of truth for plan_ends_at: replay CONFIRMED payments in
     * chronological order (received_at, id), chaining each period from the previous end
     * (never backdating below the payment's own received date), and write the corrected
     * period back onto every payment.
     */
    public function recomputePlanEnd(Tenant $tenant): void
    {
        $confirmed = Payment::with('plan')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'confirmed')
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        $cursor = null;

        foreach ($confirmed as $payment) {
            $months = $payment->plan?->billing_period_months ?? 1;
            $received = Carbon::parse($payment->received_at)->startOfDay();
            // Chain from the previous end, but never backdate below this payment's own
            // received date (a renewal after a lapse starts fresh, not in the past).
            $start = ($cursor === null || $received->greaterThan($cursor)) ? $received->copy() : $cursor->copy();
            $end = $start->copy()->addMonthsNoOverflow($months);

            $payment->forceFill([
                'subscription_period_start' => $start->toDateString(),
                'subscription_period_end' => $end->toDateString(),
            ])->save();

            $cursor = $end;
        }

        $tenant->plan_ends_at = $cursor; // Carbon or null when no confirmed payments remain
        $tenant->save();
    }

    // ── internals ────────────────────────────────────────────────────────────────

    private function applyConfirmation(Payment $payment, ?PlatformAdmin $admin): void
    {
        $tenant = $payment->tenant;

        DB::transaction(function () use ($tenant) {
            $this->recomputePlanEnd($tenant);
        });

        $tenant->refresh();

        // A tenant that had lapsed (trial or subscription) is active again.
        if (in_array($tenant->status, ['expired_subscription', 'expired_trial'], true)) {
            $tenant->status = 'active';
            $tenant->save();
        }

        // Invoice (generated after recompute so the printed period is authoritative).
        $this->invoices->generate($payment->fresh());

        $this->actions->log($admin, 'payment_confirmed', $tenant, [
            'meta' => [
                'payment_id' => $payment->id,
                'plan_ends_at' => optional($tenant->fresh()->plan_ends_at)->toDateString(),
                'invoice' => $payment->fresh()->invoice_number,
            ],
        ]);

        if ($owner = $this->tenantOwner($tenant->id)) {
            $owner->notify(new PaymentConfirmed($payment->fresh()));
        }
    }

    /** After a reversal, flip an active tenant with no remaining access to expired_subscription. */
    private function applyExpiryTransition(Tenant $tenant): void
    {
        if ($tenant->status !== 'active') {
            return;
        }

        $trialActive = $tenant->trial_ends_at && $tenant->trial_ends_at->isFuture();
        $subActive = $tenant->plan_ends_at && $tenant->accessEndsAt() && $tenant->accessEndsAt()->isFuture();

        if (! $trialActive && ! $subActive) {
            $tenant->status = 'expired_subscription';
            $tenant->save();
        }
    }

    /**
     * The period a NEW payment would cover, computed at record time (authoritative values
     * are re-derived by recomputePlanEnd on confirm). Chains from the tenant's current
     * plan_ends_at when that is still in the future, else from the received date.
     *
     * @return array{start:string,end:string}
     */
    private function computeProvisionalPeriod(Tenant $tenant, Plan $plan, mixed $receivedAt): array
    {
        $months = $plan->billing_period_months ?: 1;
        $received = Carbon::parse($receivedAt)->startOfDay();

        $base = ($tenant->plan_ends_at && $tenant->plan_ends_at->isFuture())
            ? $tenant->plan_ends_at->copy()->startOfDay()
            : $received->copy();

        $start = $received->greaterThan($base) ? $received->copy() : $base->copy();

        return [
            'start' => $start->toDateString(),
            'end' => $start->copy()->addMonthsNoOverflow($months)->toDateString(),
        ];
    }

    private function tenantOwner(string $tenantId): ?TenantUser
    {
        return TenantUser::where('tenant_id', $tenantId)
            ->orderByRaw("role = 'owner' desc")
            ->orderBy('id')
            ->first();
    }

    private function notifyAdminsOfClaim(Payment $payment): void
    {
        $admins = PlatformAdmin::all();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new PaymentClaimReceived($payment));
        }
    }
}
