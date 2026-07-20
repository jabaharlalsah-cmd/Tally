<?php

namespace App\Models;

use App\Support\ApiScopes;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 16A — the authoritative API key row. Lives in the TENANT database.
 *
 * Deliberately does NOT use BelongsToCompany: that trait's global scope filters by the ACTIVE
 * company, but this row is read BEFORE a company is active — resolving the company is what it
 * is being read for. A scoped model would look fine in dev (the scope is inert while the id is
 * null) and then return zero rows the instant anything pinned a company first. Authorized
 * companies are an explicit JSON id list, validated by the middleware.
 *
 * The raw key exists only in memory at creation. `key_hash` is a bcrypt digest via Hash::make;
 * nothing here can reconstruct the secret.
 */
class ApiKey extends Model
{
    protected $table = 'api_keys';

    protected $fillable = [
        'name', 'prefix', 'key_hash',
        'permissions_json', 'authorized_company_ids_json',
        'rate_limit_per_min',
        'created_by_user_id', 'created_by_email',
        'last_used_at', 'last_used_ip',
        'revoked_at', 'revoked_by_user_id', 'revoked_by_email',
        'expires_at',
    ];

    protected $casts = [
        'permissions_json' => 'array',
        'authorized_company_ids_json' => 'array',
        'rate_limit_per_min' => 'integer',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * The hash must never be serialized — not into a Livewire payload, a JSON response, a log
     * line, or a dd(). $hidden covers toArray()/toJson(); the UI never reads it either.
     */
    protected $hidden = ['key_hash'];

    // ── state ────────────────────────────────────────────────────────────────

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Revoked or past its expiry — either way it must not authenticate. */
    public function isInactive(): bool
    {
        return $this->isRevoked() || $this->isExpired();
    }

    // ── scopes / companies ───────────────────────────────────────────────────

    public function scopes(): array
    {
        return $this->permissions_json ?? [];
    }

    public function hasScope(string $required): bool
    {
        return ApiScopes::satisfies($this->scopes(), $required);
    }

    /** Authorized company ids. An EMPTY list means "every company in this tenant". */
    public function authorizedCompanyIds(): array
    {
        return array_values(array_map('intval', $this->authorized_company_ids_json ?? []));
    }

    public function authorizesAllCompanies(): bool
    {
        return $this->authorizedCompanyIds() === [];
    }

    public function authorizesCompany(int $companyId): bool
    {
        return $this->authorizesAllCompanies()
            || in_array($companyId, $this->authorizedCompanyIds(), true);
    }

    /**
     * May this key own a resource whose OWN company authorization is $companyIds ([] = all)?
     *
     * authorizesCompany() answers "may this key act in company X" — one company, checked per
     * request against X-Company-Id. This answers a different question that Phase 16C introduced: a
     * webhook subscription is a standing grant that keeps receiving data long after the request
     * that made it, so it must never be authorized for MORE companies than the key that created
     * it. A key restricted to company 5 creating an all-companies subscription would otherwise be
     * a durable read of company 7 — data the key can never fetch over REST.
     *
     * The rule is subset: the resource's company set must be contained in the key's. "All
     * companies" ([]) is the widest set there is, so only an all-companies key may own it.
     */
    public function authorizesCompanySet(array $companyIds): bool
    {
        if ($this->authorizesAllCompanies()) {
            return true;
        }

        if ($companyIds === []) {
            return false;   // strictly wider than this key — [] means every company
        }

        return array_diff(
            array_map('intval', $companyIds),
            $this->authorizedCompanyIds(),
        ) === [];
    }

    public function effectiveRateLimit(): int
    {
        return $this->rate_limit_per_min ?? (int) config('zerobook.api.rate_limit_per_min', 60);
    }

    // ── display ──────────────────────────────────────────────────────────────

    /** 'zb_live_a1b2c3d4•••••••••' — the only form of a key we ever show after creation. */
    public function maskedKey(): string
    {
        return $this->prefix.str_repeat('•', 9);
    }

    public function requestLogs()
    {
        return $this->hasMany(ApiRequestLog::class);
    }
}
