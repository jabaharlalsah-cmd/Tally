<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 14C — final confirmation that a tenant's data has been permanently deleted.
 */
class TenantPurged extends Notification
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
            ->subject('Your ZeroBook account has been permanently closed')
            ->greeting('Account permanently closed')
            ->line("The data for {$this->tenant->name} has been permanently deleted, as scheduled. This cannot be undone.")
            ->line('Your payment history is retained for our legal and accounting records, but your books, backups, and exports have been deleted.')
            ->line('Thank you for having used ZeroBook. You are welcome to sign up again any time.');
    }
}
