<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Support\TenantUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 14B — to the platform admin(s) when a customer submits a payment claim.
 */
class PaymentClaimReceived extends Notification
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
        $tenant = $p->tenant?->name ?? $p->tenant_id;

        return (new MailMessage())
            ->subject("New payment claim from {$tenant}")
            ->greeting('New subscription payment claim')
            ->line("Tenant: {$tenant} ({$p->tenant_id})")
            ->line("Amount: {$p->formattedAmount()} via {$p->payment_mode}")
            ->line('Reference: '.($p->reference_number ?: '—'))
            ->line('Received on: '.$p->received_at?->format('d-M-Y'))
            ->line('Plan: '.($p->plan?->name ?? '—'))
            ->action('Review pending payments', TenantUrl::adminConfig('/admin/payments'))
            ->line('Confirm it once you have verified the money in the bank statement.');
    }
}
