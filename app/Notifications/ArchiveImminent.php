<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Support\TenantUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 14C — ~24 hours before archival: final download warning.
 */
class ArchiveImminent extends Notification
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
            ->subject('Final notice: your ZeroBook account is archived tomorrow')
            ->greeting('Last chance before archival')
            ->line("{$this->tenant->name} will be archived within 24 hours. This is your last automatic reminder.")
            ->line('Download your data now, or contact support to keep the account open.')
            ->action('Download my data', TenantUrl::tenantConfig($this->tenant->id, '/account/data'));
    }
}
