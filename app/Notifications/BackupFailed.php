<?php

namespace App\Notifications;

use App\Support\TenantUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 14C — alert the platform admins when a tenant backup fails its integrity check.
 */
class BackupFailed extends Notification
{
    public function __construct(private string $tenantId, private string $reason)
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
            ->subject("⚠ Backup FAILED for tenant {$this->tenantId}")
            ->greeting('Backup failure')
            ->line("A backup for tenant [{$this->tenantId}] failed and was NOT recorded.")
            ->line("Reason: {$this->reason}")
            ->line('Investigate before the next scheduled run — this tenant currently has no fresh verified backup.')
            ->action('Open tenant', TenantUrl::adminConfig("/admin/tenants/{$this->tenantId}"));
    }
}
