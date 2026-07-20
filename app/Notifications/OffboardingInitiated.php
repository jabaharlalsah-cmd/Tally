<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Support\TenantUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 14C — confirmation to the tenant when account closure is initiated, with the
 * timeline and where to download their data.
 */
class OffboardingInitiated extends Notification
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
        $archive = $this->tenant->archive_scheduled_for?->format('d-M-Y');

        return (new MailMessage())
            ->subject('Your ZeroBook account closure has started')
            ->greeting('Account closure in progress')
            ->line("We've started closing {$this->tenant->name}. Your books are now read-only.")
            ->line("We've prepared a full backup and a downloadable data export for you.")
            ->line("You can still download your data and change your mind (reactivate) until {$archive}, after which the account is archived.")
            ->action('Download my data', TenantUrl::tenantConfig($this->tenant->id, '/account/data'))
            ->line('Contact support any time to cancel the closure and reactivate.');
    }
}
