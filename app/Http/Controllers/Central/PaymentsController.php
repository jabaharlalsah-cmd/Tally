<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Phase 14B — the platform-admin payment surface: confirm/reject customer claims,
 * record payments seen straight in the bank statement, reverse a confirmed payment, and
 * serve the private proof / invoice files (an admin may access any tenant's).
 */
class PaymentsController extends Controller
{
    private function admin()
    {
        return Auth::guard('platform')->user();
    }

    /** GET /admin/payments — every pending customer claim across all tenants. */
    public function pending()
    {
        return view('central.admin.payments-pending', [
            'payments' => Payment::with(['tenant', 'plan', 'notifiedByUser'])
                ->where('status', 'pending')
                ->orderBy('received_at')->orderBy('id')
                ->get(),
        ]);
    }

    public function confirm(Payment $payment, SubscriptionService $subscriptions)
    {
        $subscriptions->confirmPayment($payment, $this->admin());

        return back()->with('flash', "Payment confirmed — {$payment->tenant_id}'s subscription extended to ".optional($payment->tenant->fresh()->plan_ends_at)->format('d-M-Y').'.');
    }

    public function reject(Request $request, Payment $payment, SubscriptionService $subscriptions)
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $subscriptions->rejectPayment($payment, $this->admin(), $reason);

        return back()->with('flash', "Payment rejected — {$payment->tenant_id} notified.");
    }

    public function reverse(Request $request, Payment $payment, SubscriptionService $subscriptions)
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $subscriptions->reversePayment($payment, $this->admin(), $reason);

        return back()->with('flash', "Payment reversed — {$payment->tenant_id}'s plan end date recomputed.");
    }

    /** GET /admin/payments/record — admin-initiated recording form. */
    public function recordForm(Request $request)
    {
        return view('central.admin.payment-record', [
            'tenants' => Tenant::orderBy('name')->get(),
            'plans' => Plan::orderBy('id')->get(),
            'modes' => Payment::MODES,
            'selectedTenant' => $request->query('tenant'),
        ]);
    }

    /** POST /admin/payments/record — records a CONFIRMED payment immediately. */
    public function recordStore(Request $request, SubscriptionService $subscriptions)
    {
        $central = config('tenancy.database.central_connection');
        $data = $request->validate([
            'tenant_id' => ['required', 'string', 'exists:'.$central.'.tenants,id'],
            'plan_id' => ['required', 'integer', 'exists:'.$central.'.plans,id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'currency' => ['required', Rule::in(['INR', 'NPR'])],
            'payment_mode' => ['required', Rule::in(Payment::MODES)],
            'reference_number' => ['nullable', 'string', 'max:191'],
            'received_at' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'proof' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf'],
        ]);

        $payment = $subscriptions->recordPayment([
            'tenant_id' => $data['tenant_id'],
            'plan_id' => $data['plan_id'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'payment_mode' => $data['payment_mode'],
            'reference_number' => $data['reference_number'] ?? null,
            'received_at' => $data['received_at'],
            'notes' => $data['notes'] ?? null,
            'status' => 'confirmed',
            'recorded_by_admin_id' => $this->admin()->id,
        ], $this->admin());

        if ($request->hasFile('proof')) {
            $ext = strtolower($request->file('proof')->getClientOriginalExtension());
            $path = $request->file('proof')->storeAs("payment-proofs/{$data['tenant_id']}", "{$payment->id}.{$ext}", 'local');
            $payment->update(['proof_file_path' => $path]);
        }

        return redirect()->route('platform.tenant', $data['tenant_id'])
            ->with('flash', 'Payment recorded & confirmed — subscription extended.');
    }

    /** GET /admin/payments/{payment}/proof — any tenant's proof (platform admin). */
    public function proof(Payment $payment)
    {
        abort_if(! $payment->proof_file_path || ! Storage::disk('local')->exists($payment->proof_file_path), 404);

        return Storage::disk('local')->response($payment->proof_file_path);
    }

    /** GET /admin/payments/{payment}/invoice — the generated invoice PDF. */
    public function invoice(Payment $payment)
    {
        abort_unless($payment->invoice_file_path && Storage::disk('local')->exists($payment->invoice_file_path), 404);

        return Storage::disk('local')->download($payment->invoice_file_path, ($payment->invoice_number ?: 'invoice').'.pdf');
    }
}
