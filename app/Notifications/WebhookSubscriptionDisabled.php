<?php

namespace App\Notifications;

use App\Models\WebhookSubscription;
use App\Support\TenantUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 16C — to the account owner when a webhook endpoint is auto-disabled after repeated
 * failures.
 *
 * Auto-disable exists so a dead endpoint stops accumulating deliveries forever. But a silent stop
 * is worse than the failures: the customer's dashboard would simply drift out of sync with no
 * explanation. This email is what makes the disable a visible, actionable event.
 */
class WebhookSubscriptionDisabled extends Notification
{
    public function __construct(private WebhookSubscription $subscription, private string $tenantId)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('A ZeroBook webhook was turned off after repeated failures')
            ->greeting('Your webhook stopped responding')
            ->line("We could not deliver events to {$this->subscription->url} after {$this->subscription->consecutive_failures} consecutive attempts, so it has been turned off.")
            ->line('No further events will be queued for it until you re-enable it. Nothing in your books changed — only the notifications stopped.')
            ->action('Review your webhooks', TenantUrl::tenantConfig($this->tenantId, '/settings/webhooks'))
            ->line('Once your endpoint is healthy again, re-enable the webhook and send a test event to confirm.');
    }
}
