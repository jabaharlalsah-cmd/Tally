<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 14C — when an archived account is scheduled for purge (~30-day warning).
 */
class PurgeScheduled extends Notification
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
            ->error()
            ->subject('Your ZeroBook data will be permanently deleted')
            ->greeting('Scheduled for permanent deletion')
            ->line("The data for {$this->tenant->name} is scheduled to be PERMANENTLY DELETED on "
                .$this->tenant->purge_scheduled_for?->format('d-M-Y').'.')
            ->line('After that date the data cannot be recovered. If you need it, contact support immediately to restore the account or obtain a final export.');
    }
}
