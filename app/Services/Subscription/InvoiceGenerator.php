<?php

namespace App\Services\Subscription;

use App\Models\Payment;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 14B — generate the customer's subscription-payment invoice (a lightweight PDF
 * receipt) with dompdf, store it privately, and stamp the number + path on the payment.
 *
 * This is a receipt for the subscription payment, NOT a GST tax invoice from ZeroBook to
 * the customer (ZeroBook's own GST on its subscription revenue is a separate compliance
 * surface — deferred, see the README).
 */
class InvoiceGenerator
{
    /** Generate (or regenerate) the invoice for a confirmed payment. */
    public function generate(Payment $payment): void
    {
        $number = $payment->invoice_number ?: $this->number($payment);

        $html = view('invoices.subscription', [
            'payment' => $payment,
            'tenant' => $payment->tenant,
            'plan' => $payment->plan,
            'number' => $number,
            'seller' => (array) config('zerobook.invoice'),
            'issuedOn' => Carbon::now(),
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans'); // ships with dompdf; renders ₹ and रू
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $path = "payment-invoices/{$payment->tenant_id}/{$payment->id}.pdf";
        Storage::disk('local')->put($path, $dompdf->output());

        $payment->forceFill([
            'invoice_number' => $number,
            'invoice_file_path' => $path,
        ])->save();
    }

    /** Deterministic invoice number: <prefix>-<year>-<zero-padded id>. */
    private function number(Payment $payment): string
    {
        $prefix = (string) config('zerobook.invoice.number_prefix', 'ZB');
        $year = Carbon::parse($payment->received_at)->format('Y');

        return sprintf('%s-%s-%06d', $prefix, $year, $payment->id);
    }
}
