<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Phase 7B — a tenant login identity (CENTRAL database).
 *
 * Authenticated by the `tenant` guard on a tenant subdomain. The membership lives
 * centrally (keyed by tenant_id + email) so one email can belong to several tenants;
 * login is scoped to the CURRENT subdomain's tenant. Pinned to the central
 * connection so it is readable whether or not a tenant is initialized.
 *
 * Phase 14A — implements MustVerifyEmail against the `verified_at` column (not the
 * framework-default `email_verified_at`) so a self-signup admin cannot log in until
 * they confirm their email. The verification link routes through the CENTRAL app and,
 * on success, flips the tenant to 'active' and hands the user off to the tenant login.
 */
class TenantUser extends Authenticatable implements MustVerifyEmailContract
{
    use Notifiable;
    use UsesCentralConnection;

    protected $table = 'tenant_users';

    protected $fillable = ['tenant_id', 'name', 'email', 'mobile', 'password', 'role', 'verified_at'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password' => 'hashed',
        'verified_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Phase 16 — send the reset link to THIS tenant's subdomain. The reset is initiated
     * on the tenant subdomain, so route() resolves to the correct host automatically.
     */
    public function sendPasswordResetNotification($token): void
    {
        $url = route('tenant.password.reset', ['token' => $token])
            .'?email='.urlencode($this->getEmailForPasswordReset());

        $this->notify(new \App\Notifications\ResetPasswordLink(
            $url,
            $this->tenant?->name ?? 'ZeroBook',
            (int) config('auth.passwords.tenant_users.expire', 60),
        ));
    }

    // ── MustVerifyEmail (Phase 14A) — implemented against `verified_at` ──────────

    public function hasVerifiedEmail(): bool
    {
        return $this->verified_at !== null;
    }

    public function markEmailAsVerified(): bool
    {
        return $this->forceFill(['verified_at' => $this->freshTimestamp()])->save();
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new \App\Notifications\VerifyTenantEmail());
    }

    /** The address verification is sent to. */
    public function getEmailForVerification(): string
    {
        return $this->email;
    }
}
