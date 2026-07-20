<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Support\TenantUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 14C — ~7 days before an offboarding account is archived: download reminder.
 */
class ArchiveReminder extends Notification
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
            ->subject('Reminder: download your ZeroBook data before archival')
            ->greeting('Please download your data')
            ->line("{$this->tenant->name} will be archived in {$this->daysLeft} day(s) ("
                .$this->tenant->archive_scheduled_for?->format('d-M-Y').').')
            ->line('After archival you must contact support to restore access. Download your export while it is one click away.')
            ->action('Download my data', TenantUrl::tenantConfig($this->tenant->id, '/account/data'));
    }
}
