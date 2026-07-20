<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Support\TenantUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 14B — to the tenant admin when the paid subscription has lapsed (past grace).
 */
class SubscriptionExpired extends Notification
{
    public function __construct(private Tenant $tenant)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Your ZeroBook subscription has expired')
            ->greeting('Subscription expired')
            ->line("Your subscription for {$this->tenant->name} has expired and posting is now blocked.")
            ->line('Your data is safe and remains fully readable. Record a renewal payment to resume posting.')
            ->action('Renew now', TenantUrl::tenantConfig($this->tenant->id, '/subscription'));
    }
}
