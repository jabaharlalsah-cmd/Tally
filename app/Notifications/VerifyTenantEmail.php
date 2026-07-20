<?php

namespace App\Notifications;

use App\Support\TenantUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Phase 14A — the signup email-confirmation link.
 *
 * Sent to the tenant admin user right after signup. The link points at the CENTRAL
 * verification route (a temporary signed URL keyed to the user's id + an email hash);
 * following it marks the email verified, flips the tenant from 'pending_verification'
 * to 'active', and hands the user off to their tenant subdomain already logged in.
 *
 * The URL is built on the CURRENT signup host so it stays on the same base domain the
 * customer just used (works for zerobook.in, zerobook.local, and localhost:port dev).
 */
class VerifyTenantEmail extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->verificationUrl($notifiable);
        $tenantName = $notifiable->tenant?->name ?? 'your company';
        $minutes = (int) config('auth.verification.expire', 60);

        return (new MailMessage())
            ->subject('Confirm your ZeroBook account')
            ->greeting('Welcome to ZeroBook')
            ->line("Thanks for signing up {$tenantName}. Confirm this email address to activate your account and sign in.")
            ->action('Confirm email & get started', $url)
            ->line("This link expires in {$minutes} minutes. If you didn't create a ZeroBook account, you can ignore this email.");
    }

    /**
     * Temporary signed CENTRAL route. Relative (host-independent) so it is valid on
     * whichever central host the customer signed up from.
     */
    protected function verificationUrl(object $notifiable): string
    {
        $minutes = (int) config('auth.verification.expire', 60);

        $relative = URL::temporarySignedRoute(
            'signup.verify',
            now()->addMinutes($minutes),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
            absolute: false,
        );

        // Keep the link on the base central domain (drop a leading admin./www.).
        return request()->getScheme().'://'.TenantUrl::baseDomain().($this->portSuffix()).$relative;
    }

    private function portSuffix(): string
    {
        $port = request()->getPort();

        return in_array($port, [80, 443, null], true) ? '' : ':'.$port;
    }
}
