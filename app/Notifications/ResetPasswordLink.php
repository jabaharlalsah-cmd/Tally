<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 16 — the "reset your password" email for the platform-admin and tenant guards.
 *
 * The default framework notification hard-codes the `password.reset` route (the `web`
 * guard). Our guards use their own route names + hosts (admin.<domain> and the tenant
 * subdomain), so each model builds the absolute reset URL itself and hands it here.
 */
class ResetPasswordLink extends Notification
{
    use Queueable;

    public function __construct(
        public string $url,
        public string $context = 'ZeroBook',
        public int $expireMinutes = 60,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your '.$this->context.' password')
            ->greeting('Password reset request')
            ->line('We received a request to reset the password for your '.$this->context.' account.')
            ->action('Reset password', $this->url)
            ->line('This link will expire in '.$this->expireMinutes.' minutes.')
            ->line('If you did not request a password reset, no action is needed — your password stays the same.');
    }
}
