<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Support\TenantUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 14B — to the tenant admin when a claimed payment is rejected, with the reason.
 */
class PaymentRejected extends Notification
{
    public function __construct(private Payment $payment, private string $reason)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $p = $this->payment;

        return (new MailMessage())
            ->subject('We could not confirm your ZeroBook payment')
            ->greeting('Payment not confirmed')
            ->line("We were unable to confirm your payment of {$p->formattedAmount()} ({$p->payment_mode}, ref "
                .($p->reference_number ?: '—').').')
            ->line('Reason: '.$this->reason)
            ->line('No subscription change has been made. Please check the details and record the payment again, or contact support.')
            ->action('Review & retry', TenantUrl::tenantConfig($p->tenant_id, '/subscription'));
    }
}
