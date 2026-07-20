<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 14C — ~7 days before purge: final irreversible-deletion warning.
 */
class PurgeImminent extends Notification
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
        return (new MailMessage())
            ->error()
            ->subject('FINAL WARNING: ZeroBook data deletion in '.$this->daysLeft.' days')
            ->greeting('Permanent deletion is imminent')
            ->line("The data for {$this->tenant->name} will be PERMANENTLY DELETED in {$this->daysLeft} day(s) and cannot be recovered afterwards.")
            ->line('This is the final automatic warning. Contact support today if you need the account restored.');
    }
}
