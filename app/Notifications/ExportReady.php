<?php

namespace App\Notifications;

use App\Models\TenantExport;
use App\Services\Exports\ExportService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 14C — to the tenant owner when their data export is ready, with a signed link that
 * expires (default 7 days).
 */
class ExportReady extends Notification
{
    public function __construct(private TenantExport $export)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = app(ExportService::class)->signedDownloadUrl($this->export);
        $expires = $this->export->download_expires_at?->format('d-M-Y H:i');

        return (new MailMessage())
            ->subject('Your ZeroBook data export is ready')
            ->greeting('Your data is ready to download')
            ->line('We\'ve prepared a complete export of your books — CSVs of every ledger and voucher, inventory, statutory detail, snapshot reports, and a full database dump.')
            ->line("Size: {$this->export->humanSize()}.")
            ->action('Download my data', $url)
            ->line("This secure link expires on {$expires}. You can generate a fresh export any time from Settings → Data & Privacy.");
    }
}
