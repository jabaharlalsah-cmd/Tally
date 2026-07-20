<?php

namespace App\Services\Hostinger;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Phase 16 — thin client over the Hostinger API for the two shared-hosting resources the
 * app cannot create itself: a per-tenant MySQL database and a subdomain vhost.
 *
 *   • Database:  POST /api/hosting/v1/accounts/{username}/databases
 *   • Subdomain: POST /api/hosting/v1/accounts/{username}/websites/{domain}/subdomains
 *
 * Both creates are ASYNC ("Request accepted") — callers poll the list endpoints (see
 * databaseExists / subdomainExists) until the resource appears.
 */
class HostingerService
{
    public function __construct(
        private ?string $token = null,
        private ?string $baseUrl = null,
        private ?string $username = null,
        private ?string $websiteDomain = null,
    ) {
        $this->token ??= config('hostinger.api_token');
        $this->baseUrl ??= config('hostinger.base_url');
        $this->username ??= config('hostinger.account_username');
        $this->websiteDomain ??= config('hostinger.website_domain');

        if (! $this->token || ! $this->username) {
            throw new RuntimeException('Hostinger API is not configured (HOSTINGER_API_TOKEN / HOSTINGER_ACCOUNT_USERNAME).');
        }
    }

    /** The mandatory account prefix on every DB name + user (e.g. "u958726172"). */
    public function accountPrefix(): string
    {
        return $this->username;
    }

    /** Full DB name/user for a tenant slug: "<username>_<slug>". */
    public function databaseNameFor(string $slug): string
    {
        return $this->username.'_'.$slug;
    }

    private function http()
    {
        return Http::withToken($this->token)
            ->acceptJson()
            ->timeout(30)
            ->baseUrl($this->baseUrl);
    }

    // ── Databases ────────────────────────────────────────────────────────────────

    public function databaseExists(string $fullName): bool
    {
        $res = $this->http()->get("/hosting/v1/accounts/{$this->username}/databases");
        $res->throw();

        foreach ($res->json('data', []) as $db) {
            if (($db['name'] ?? null) === $fullName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a database + user (async). $slug is the un-prefixed suffix; Hostinger adds
     * the account prefix, yielding "<username>_<slug>". Returns the full DB name.
     */
    public function createDatabase(string $slug, string $password): string
    {
        $res = $this->http()->post("/hosting/v1/accounts/{$this->username}/databases", [
            'name' => $slug,
            'user' => $slug,
            'password' => $password,
            'website_domain' => $this->websiteDomain,
        ]);
        $res->throw();

        return $this->databaseNameFor($slug);
    }

    /**
     * Change the REAL MySQL password of a tenant database's user (Hostinger side).
     * PATCH /api/hosting/v1/accounts/{username}/databases/{name}/change-password
     * NB: the caller must also update the stored copy the app connects with.
     */
    public function changeDatabasePassword(string $fullName, string $password): void
    {
        $res = $this->http()->patch(
            "/hosting/v1/accounts/{$this->username}/databases/{$fullName}/change-password",
            ['password' => $password],
        );
        $res->throw();
    }

    /** Poll until the database is listed (async create), up to the configured timeout. */
    public function waitForDatabase(string $fullName): void
    {
        $deadline = time() + (int) config('hostinger.provision_timeout', 60);
        do {
            if ($this->databaseExists($fullName)) {
                return;
            }
            usleep(3_000_000); // 3s
        } while (time() < $deadline);

        throw new RuntimeException("Hostinger did not report database “{$fullName}” as ready within the timeout.");
    }

    // ── Subdomains ───────────────────────────────────────────────────────────────

    public function subdomainExists(string $fqdn): bool
    {
        $res = $this->http()->get("/hosting/v1/accounts/{$this->username}/websites/{$this->websiteDomain}/subdomains");
        $res->throw();

        foreach ((array) $res->json() as $sub) {
            if (($sub['domain'] ?? null) === $fqdn) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a subdomain rooted at the website's public directory (so it serves the same
     * Laravel app). Idempotent: no-op if the subdomain already exists. Async.
     */
    public function ensureSubdomain(string $slug): void
    {
        $fqdn = $slug.'.'.$this->websiteDomain;
        if ($this->subdomainExists($fqdn)) {
            return;
        }

        $res = $this->http()->post(
            "/hosting/v1/accounts/{$this->username}/websites/{$this->websiteDomain}/subdomains",
            ['subdomain' => $slug, 'is_using_public_directory' => true],
        );
        $res->throw();
    }
}
