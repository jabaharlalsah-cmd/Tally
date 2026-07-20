<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\Subscription\SubscriptionService;
use App\Support\TenantBilling;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Phase 14B — the tenant side of manual payments: submit a payment claim (a pending
 * payment awaiting admin confirmation) and view the private proof / invoice files.
 *
 * Reachable even when the subscription has expired (the claim route is whitelisted in
 * RequireActiveTenant) — otherwise an expired tenant could never renew.
 */
class TenantSubscriptionController extends Controller
{
    /** POST /subscription/claim — customer records a payment they made. */
    public function claim(Request $request, SubscriptionService $subscriptions)
    {
        $tenantId = tenant('id');
        $currency = TenantBilling::currency();

        // Anti-spam: cap customer claims per tenant per day.
        $max = (int) config('zerobook.max_claims_per_day', 5);
        if (TenantBilling::claimsToday($tenantId) >= $max) {
            return back()->withInput()->withErrors([
                'proof' => "You've already submitted {$max} payment claims today. Please wait for confirmation or contact support.",
            ]);
        }

        $central = config('tenancy.database.central_connection');
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:'.$central.'.plans,id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'payment_mode' => ['required', Rule::in(Payment::MODES)],
            'reference_number' => ['nullable', 'string', 'max:191'],
            'received_at' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'proof' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf'],
        ]);

        $plan = Plan::find($data['plan_id']);
        if (! $plan->isPurchasableIn($currency)) {
            return back()->withInput()->withErrors(['plan_id' => 'That plan is not available for your account.']);
        }

        // Create the pending payment first (so the proof filename can key on its id).
        $payment = $subscriptions->recordPayment([
            'tenant_id' => $tenantId,
            'plan_id' => $plan->id,
            'amount' => $data['amount'],
            'currency' => $currency,
            'payment_mode' => $data['payment_mode'],
            'reference_number' => $data['reference_number'] ?? null,
            'received_at' => $data['received_at'],
            'notes' => $data['notes'] ?? null,
            'status' => 'pending',
            'notified_by_user_id' => Auth::guard('tenant')->id(),
        ]);

        // Store the proof privately, then link it.
        $ext = strtolower($request->file('proof')->getClientOriginalExtension());
        $path = $request->file('proof')->storeAs("payment-proofs/{$tenantId}", "{$payment->id}.{$ext}", 'local');
        $payment->update(['proof_file_path' => $path]);

        return redirect()->route('subscription')
            ->with('flash', 'Payment recorded — pending admin confirmation. We\'ll email you once it\'s confirmed.');
    }

    /** GET /subscription/proof/{payment} — stream the private proof to this tenant only. */
    public function proof(Payment $payment)
    {
        abort_unless($payment->tenant_id === tenant('id'), 403);
        abort_if(! $payment->proof_file_path || ! Storage::disk('local')->exists($payment->proof_file_path), 404);

        return Storage::disk('local')->response($payment->proof_file_path);
    }

    /** GET /subscription/invoice/{payment} — stream the confirmed payment's invoice PDF. */
    public function invoice(Payment $payment)
    {
        abort_unless($payment->tenant_id === tenant('id'), 403);
        abort_unless($payment->status === 'confirmed' && $payment->invoice_file_path, 404);
        abort_if(! Storage::disk('local')->exists($payment->invoice_file_path), 404);

        return Storage::disk('local')->download($payment->invoice_file_path, ($payment->invoice_number ?: 'invoice').'.pdf');
    }
}
