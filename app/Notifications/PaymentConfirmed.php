<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Support\TenantUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 14B — to the tenant admin when a payment is confirmed. The generated invoice PDF
 * is attached.
 */
class PaymentConfirmed extends Notification
{
    public function __construct(private Payment $payment)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $p = $this->payment;
        $until = $p->tenant?->plan_ends_at?->format('d-M-Y') ?? $p->subscription_period_end?->format('d-M-Y');

        $mail = (new MailMessage())
            ->subject('Payment received — your ZeroBook subscription is extended')
            ->greeting('Thank you — payment received')
            ->line("We've recorded your payment of {$p->formattedAmount()} ({$p->payment_mode}).")
            ->line("Your subscription is now active until {$until}.")
            ->line('Plan: '.($p->plan?->name ?? '—').' · Period: '
                .$p->subscription_period_start?->format('d-M-Y').' → '.$p->subscription_period_end?->format('d-M-Y'))
            ->action('Open ZeroBook', TenantUrl::tenantConfig($p->tenant_id, '/subscription'))
            ->line('Your invoice is attached.');

        if ($p->invoice_file_path && Storage::disk('local')->exists($p->invoice_file_path)) {
            $mail->attachData(
                Storage::disk('local')->get($p->invoice_file_path),
                ($p->invoice_number ?: 'invoice').'.pdf',
                ['mime' => 'application/pdf'],
            );
        }

        return $mail;
    }
}
