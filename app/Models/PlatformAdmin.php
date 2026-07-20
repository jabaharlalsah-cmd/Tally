<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Phase 7B — a ZeroBook platform operator (CENTRAL database).
 *
 * Authenticated by the `platform` guard on the central domain. Manages tenants; never
 * belongs to any tenant database. Phase 14B — notifiable (new payment-claim alerts).
 */
class PlatformAdmin extends Authenticatable
{
    use Notifiable;
    use UsesCentralConnection;

    protected $table = 'platform_admins';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password' => 'hashed',
    ];

    /**
     * Phase 16 — send the reset link to the platform-admin surface (admin.<domain>).
     * Built here because the framework default targets the `web` guard's route.
     */
    public function sendPasswordResetNotification($token): void
    {
        $url = route('platform.password.reset', ['token' => $token])
            .'?email='.urlencode($this->getEmailForPasswordReset());

        $this->notify(new \App\Notifications\ResetPasswordLink(
            $url,
            'ZeroBook platform admin',
            (int) config('auth.passwords.platform_admins.expire', 60),
        ));
    }
}
