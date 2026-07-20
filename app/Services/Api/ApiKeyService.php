<?php

namespace App\Services\Api;

use App\Models\ApiKey;
use App\Models\ApiKeyDirectory;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\ApiScopes;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Phase 16A — issue, authenticate and revoke API keys.
 *
 * KEY FORMAT
 *   zb_<env>_<32 random chars>          e.g. zb_live_a1b2c3d4e5f6…   (env: live | test)
 *   prefix = 'zb_<env>_' . first 8 of the random part   → 'zb_live_a1b2c3d4'
 *
 * The prefix is stored plaintext and is NOT a secret — it is the lookup handle. The full key is
 * stored only as a bcrypt hash. There is no path back from the row to the key: lose it and you
 * revoke and re-issue.
 *
 * HASHING — Hash::make / Hash::check, not raw password_hash / password_verify.
 * The brief specifies password_hash(); this codebase hashes exclusively through the Hash facade
 * (grep: zero password_hash calls). Hash::make IS bcrypt and Hash::check calls password_verify
 * internally, so the brief's requirement — a bcrypt digest compared in constant time — is met
 * exactly, while config/hashing.php keeps the ability to re-tune cost or migrate algorithms.
 * Total key length is 40 bytes ('zb_live_' + 32), comfortably under bcrypt's 72-byte truncation
 * limit; lengthening the format past 72 would silently stop distinguishing keys.
 *
 * CONSTANT-TIME DISCIPLINE — the reason authenticate() looks wasteful.
 * Every failure path performs a REAL bcrypt verify against a fixed dummy hash before returning
 * null. A miss must cost what a hit costs. Without this, "prefix not found" would return in
 * microseconds while "prefix found, wrong secret" would pay ~60ms of bcrypt — turning the
 * response clock into an oracle that lets an attacker enumerate valid prefixes offline. The
 * dummy verify is not decoration; deleting it reintroduces the side channel.
 *
 * The remaining, documented asymmetry: a real prefix additionally opens a tenant DB connection,
 * which a miss does not. bcrypt dominates that difference by orders of magnitude, but it is not
 * zero — see PHASE16A_README.
 */
class ApiKeyService
{
    /** Random-suffix length. 32 chars from Str::random's alphabet ≈ 190 bits. */
    private const RANDOM_LENGTH = 32;

    /** How much of the random suffix becomes part of the plaintext prefix. */
    private const PREFIX_RANDOM_CHARS = 8;

    public const ENVIRONMENTS = ['live', 'test'];

    /**
     * A real bcrypt hash of a value no key can equal, used to burn the same CPU on a miss as a
     * hit. Memoised per process AND cached across processes — see dummyHash() for why the
     * cross-process half is load-bearing rather than an optimisation.
     */
    private static ?string $dummyHash = null;

    // ── issue ────────────────────────────────────────────────────────────────

    /**
     * Mint a key for $tenant. Returns ['key' => 'zb_live_…', 'key_row' => ApiKey].
     *
     * The raw key is in the return value and NOWHERE else — not the row, not a log, not an
     * event. The caller shows it once and drops it.
     *
     * Writes two rows: the authoritative api_keys row in the tenant DB, and the central
     * api_key_directory row that lets a bearer token find this tenant with no subdomain.
     *
     * @param  array  $permissions  scope strings; anything outside the catalog is dropped
     * @param  array  $companyIds   authorized company ids; [] = every company
     */
    public function generate(
        Tenant $tenant,
        ?TenantUser $user,
        string $name,
        array $permissions,
        array $companyIds = [],
        ?CarbonInterface $expiresAt = null,
        string $env = 'live',
        ?int $rateLimitPerMin = null,
    ): array {
        if (! in_array($env, self::ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException("Unknown API key environment [{$env}].");
        }

        $scopes = ApiScopes::sanitize($permissions);

        if ($scopes === []) {
            throw new \InvalidArgumentException('An API key must carry at least one permission scope.');
        }

        // Loop guards against the (astronomically unlikely) prefix collision. The prefix is
        // uniquely indexed in both tables, so a duplicate must be regenerated, not tolerated.
        do {
            $random = Str::random(self::RANDOM_LENGTH);
            $prefix = 'zb_'.$env.'_'.substr($random, 0, self::PREFIX_RANDOM_CHARS);
        } while (ApiKeyDirectory::where('prefix', $prefix)->exists());

        $rawKey = 'zb_'.$env.'_'.$random;

        $companyIds = array_values(array_unique(array_map('intval', $companyIds)));

        $row = $tenant->run(function () use ($name, $prefix, $rawKey, $scopes, $companyIds, $expiresAt, $user, $rateLimitPerMin) {
            return ApiKey::create([
                'name' => $name,
                'prefix' => $prefix,
                'key_hash' => Hash::make($rawKey),
                'permissions_json' => $scopes,
                'authorized_company_ids_json' => $companyIds,
                'rate_limit_per_min' => $rateLimitPerMin,
                'created_by_user_id' => $user?->id,
                'created_by_email' => $user?->email,
                'expires_at' => $expiresAt,
            ]);
        });

        // The central router. Written AFTER the tenant row so a directory entry never points at
        // a key that does not exist.
        ApiKeyDirectory::create([
            'prefix' => $prefix,
            'tenant_id' => $tenant->getTenantKey(),
            'created_at' => now(),
        ]);

        return ['key' => $rawKey, 'key_row' => $row];
    }

    // ── authenticate ─────────────────────────────────────────────────────────

    /**
     * Resolve a raw bearer key to its ApiKey row, initializing that key's tenant connection as
     * a side effect. Returns null for ANY failure — malformed, unknown prefix, wrong secret,
     * missing or closed tenant — with no way for the caller (or the clock) to tell which.
     *
     * A REVOKED or EXPIRED key still returns its row: identity succeeded, and the caller needs
     * to distinguish "not a key" (401 invalid_key) from "your key was turned off"
     * (401 key_revoked), which is actionable for the customer. Callers MUST check isInactive().
     *
     * SIDE EFFECT: on success, tenancy() is initialized to the key's tenant and stays that way
     * — that is exactly what the middleware needs, and why this is not a pure lookup.
     */
    public function authenticate(string $rawKey): ?ApiKey
    {
        $prefix = self::prefixOf($rawKey);

        if ($prefix === null) {
            return $this->authFailed($rawKey);
        }

        $directory = ApiKeyDirectory::where('prefix', $prefix)->first();

        if (! $directory) {
            return $this->authFailed($rawKey);
        }

        $tenant = Tenant::find($directory->tenant_id);

        if (! $tenant) {
            return $this->authFailed($rawKey);
        }

        // A closed account (archived / purge_scheduled / purged) may have NO database left —
        // purge drops it. Connecting would throw a raw DB error instead of a clean response, and
        // there is nothing to verify the key against. Fail as a generic invalid key: it reveals
        // nothing, and it is true (the key can no longer be verified).
        if ($tenant->isClosed()) {
            return $this->authFailed($rawKey);
        }

        // Open the key's tenant. From here the default connection IS that tenant's database, so
        // the api_keys read below is structurally incapable of seeing another tenant's rows.
        tenancy()->initialize($tenant);

        $key = ApiKey::where('prefix', $prefix)->first();

        if (! $key) {
            // Directory says this tenant, tenant DB disagrees (hand-edited row, restored backup).
            return $this->authFailed($rawKey);
        }

        // The real comparison. Hash::check → password_verify → constant-time.
        if (! Hash::check($rawKey, $key->key_hash)) {
            return null;   // the bcrypt cost was already paid by the check itself
        }

        return $key;
    }

    /**
     * Burn a genuine bcrypt verify, then fail. Every miss routes through here so that "no such
     * prefix" costs the same as "wrong secret".
     */
    private function authFailed(string $rawKey): ?ApiKey
    {
        Hash::check($rawKey, self::dummyHash());

        return null;
    }

    /**
     * The dummy bcrypt digest every failure path is compared against.
     *
     * THE CROSS-PROCESS CACHE IS THE WHOLE POINT — do not "simplify" it back to a per-process
     * static. This was a real, shipped-then-caught bug:
     *
     * A per-process `static ??= Hash::make(...)` looks equivalent, and under a long-lived console
     * process it is — the first call pays the Hash::make and every later call reuses it. But
     * PHP-FPM starts EVERY REQUEST with fresh statics. So in production the memo was always cold,
     * and every miss paid Hash::make (~225ms) PLUS Hash::check (~225ms) = 2 bcrypts, while a real
     * hit paid a single Hash::check = 1 bcrypt. Measured: 447ms vs 225ms — a 1.98x split.
     *
     * That is precisely the oracle authFailed() exists to close, merely inverted: an unknown
     * prefix answered ~2x SLOWER than a real one, so an attacker could enumerate valid prefixes
     * by the clock just as easily as if there had been no dummy verify at all.
     *
     * Caching the digest makes the steady-state per-request cost one cache read plus one
     * Hash::check — the same single bcrypt a found prefix pays. The key is derived from the
     * hashing config so that re-tuning bcrypt's cost regenerates the dummy at the NEW cost;
     * otherwise the two paths would drift apart again the moment rounds changed.
     *
     * The digest is not a secret (it hashes a random value nothing can equal), so a shared cache
     * entry is safe. A cache flush — `optimize:clear` on deploy — costs exactly ONE slow miss
     * while it is recomputed, not one per request.
     */
    private static function dummyHash(): string
    {
        return self::$dummyHash ??= \Illuminate\Support\Facades\Cache::rememberForever(
            'zb:api:dummy_hash:'.substr(md5(serialize(config('hashing'))), 0, 12),
            fn () => Hash::make('zb_invalid_'.Str::random(self::RANDOM_LENGTH)),
        );
    }

    /**
     * 'zb_live_a1b2c3d4e5…' → 'zb_live_a1b2c3d4'. Null when the shape is wrong.
     *
     * Strict on shape but NOT on secrecy: this only parses. It must never short-circuit the
     * caller's verify — a malformed key still pays the dummy bcrypt above.
     */
    public static function prefixOf(string $rawKey): ?string
    {
        $parts = explode('_', $rawKey);

        if (count($parts) !== 3) {
            return null;
        }

        [$vendor, $env, $random] = $parts;

        if ($vendor !== 'zb' || ! in_array($env, self::ENVIRONMENTS, true)) {
            return null;
        }

        if (strlen($random) !== self::RANDOM_LENGTH || ! ctype_alnum($random)) {
            return null;
        }

        return 'zb_'.$env.'_'.substr($random, 0, self::PREFIX_RANDOM_CHARS);
    }

    /** Pull 'zb_live_…' out of an Authorization header. Bearer only — any other scheme is null. */
    public static function bearerFrom(?string $header): ?string
    {
        if (! is_string($header) || $header === '') {
            return null;
        }

        if (! preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            return null;
        }

        return $m[1];
    }

    // ── revoke ───────────────────────────────────────────────────────────────

    /**
     * Soft-delete: stamp revoked_at. The row stays so its request log keeps a parent and the
     * audit trail survives. Idempotent — re-revoking never moves the original timestamp.
     *
     * Must run inside the key's tenant context (the UI and the proof both do).
     */
    public function revoke(ApiKey $key, ?TenantUser $revokedBy = null): void
    {
        if ($key->isRevoked()) {
            return;
        }

        $key->forceFill([
            'revoked_at' => now(),
            'revoked_by_user_id' => $revokedBy?->id,
            'revoked_by_email' => $revokedBy?->email,
        ])->save();

        // Mirror it centrally for housekeeping. Not consulted on the auth path — the tenant row
        // above is the authority, so the two can never disagree in a way that grants access.
        ApiKeyDirectory::where('prefix', $key->prefix)->update(['revoked_at' => now()]);
    }

    /**
     * Record that a key was just used.
     *
     * THROTTLED AND SYNCHRONOUS, deliberately. The brief asks for an async write "so auth does
     * not take a DB write on the hot path" — but QUEUE_CONNECTION=sync makes dispatch() run the
     * job INLINE (adding its latency to the response, the exact opposite), and no queue worker
     * exists in deploy.sh, so a `database` queue would never drain at all. Both async routes are
     * worse than the write they replace.
     *
     * Instead: skip the write entirely unless the stamp is over a minute stale. A key doing its
     * full 60 req/min costs ONE update per minute, not 60 — cheaper than a queue would have been.
     * The cost is that last_used_at can trail by up to a minute, which is invisible on a screen
     * that renders it as "2 minutes ago". Called from LogApiRequest::terminate(), i.e. after the
     * response is already on the wire.
     */
    public function touchLastUsed(ApiKey $key, ?string $ip): void
    {
        if ($key->last_used_at !== null && $key->last_used_at->gt(now()->subMinute())) {
            return;
        }

        ApiKey::where('id', $key->id)->update([
            'last_used_at' => now(),
            'last_used_ip' => $ip,
        ]);
    }
}
