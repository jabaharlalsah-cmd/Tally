<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 16 — the welcome email sent to a tenant owner when their account is created.
 *
 * Carries a one-time "set your password" link rather than a password: no plaintext
 * credential is ever emailed, stored, or seen by the platform admin. The link is a
 * standard tenant password-reset token (single-use, tenant-scoped, expiring), so an
 * expired link is self-served via "Forgot password?" on the tenant's sign-in page.
 */
class TenantWelcome extends Notification
{
    use Queueable;

    public function __construct(
        public string $companyName,
        public string $loginUrl,
        public string $email,
        public string $setPasswordUrl,
        public ?string $planName = null,
        public int $expireMinutes = 60,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Welcome to ZeroBook — set up your '.$this->companyName.' account')
            ->greeting('Welcome to ZeroBook!')
            ->line('Your account for **'.$this->companyName.'** has been created and is ready to use.')
            ->line('Your sign-in email is: **'.$this->email.'**');

        if ($this->planName) {
            $mail->line('Plan: '.$this->planName);
        }

        return $mail
            ->line('Choose your own password to finish setting up. For your security this link can be used once, and expires in '.$this->expireMinutes.' minutes.')
            ->action('Set your password', $this->setPasswordUrl)
            ->line('If the link has expired, open '.$this->loginUrl.' and use “Forgot password?” to get a fresh one.')
            ->line('Your password is yours alone — nobody at ZeroBook can see it.')
            ->salutation('— The ZeroBook team');
    }
}
