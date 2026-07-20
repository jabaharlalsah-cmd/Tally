<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Support\TenantUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 14B — to the tenant admin ~7 days before the paid subscription lapses.
 */
class SubscriptionExpiring extends Notification
{
    public function __construct(private Tenant $tenant, private int $daysLeft)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ends = $this->tenant->plan_ends_at?->format('d-M-Y');

        return (new MailMessage())
            ->subject('Your ZeroBook subscription expires soon')
            ->greeting('Renewal reminder')
            ->line("Your subscription for {$this->tenant->name} expires on {$ends} ({$this->daysLeft} day(s) left).")
            ->line('Record your renewal payment to keep posting without interruption.')
            ->action('Renew now', TenantUrl::tenantConfig($this->tenant->id, '/subscription'))
            ->line('You can keep reading your books even after expiry, but posting is blocked once the grace period ends.');
    }
}
