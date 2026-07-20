<?php

namespace App\Support;

/**
 * Phase 14A — build absolute URLs that cross between the central surfaces and a tenant
 * subdomain on the SAME base domain, preserving scheme + port.
 *
 * The central surfaces run on `zerobook.local` / `www.zerobook.local` (signup) and
 * `admin.zerobook.local` (platform admin); a tenant runs on `<slug>.zerobook.local`.
 * Cross-domain hand-offs (email-verify auto-login, impersonation) are RELATIVE signed
 * URLs — the signature ignores the host — and this helper prepends the correct host so
 * the browser lands on the right subdomain, where the tenancy middleware then switches
 * to the tenant's database.
 *
 * Local dev keeps the port (e.g. `<slug>.localhost:8777`); production drops the default
 * 80/443.
 */
class TenantUrl
{
    /** The base central domain for the current request (strips a leading admin./www.). */
    public static function baseDomain(?string $host = null): string
    {
        $host = $host ?? request()->getHost();

        foreach (['admin.', 'www.'] as $prefix) {
            if (str_starts_with($host, $prefix)) {
                return substr($host, strlen($prefix));
            }
        }

        return $host;
    }

    /**
     * The base central domain when the current host is a TENANT subdomain (<slug>.<base>).
     * The leading label is the tenant slug, not a strippable admin./www. prefix, so it
     * must be removed explicitly — used to return to the admin surface from impersonation.
     */
    public static function baseDomainWithoutTenant(string $slug, ?string $host = null): string
    {
        $host = $host ?? request()->getHost();

        return str_starts_with($host, $slug.'.')
            ? substr($host, strlen($slug) + 1)
            : self::baseDomain($host);
    }

    private static function portSuffix(): string
    {
        $port = request()->getPort();

        return in_array($port, [80, 443, null], true) ? '' : ':'.$port;
    }

    /** An absolute URL on `<slug>.<base-domain>` for a relative path+query. */
    public static function forTenant(string $slug, string $pathAndQuery): string
    {
        return request()->getScheme().'://'.$slug.'.'.self::baseDomain().self::portSuffix().$pathAndQuery;
    }

    /**
     * An absolute URL on `admin.<base-domain>` for a path. Pass $baseDomain when the
     * current host is a tenant subdomain (its base isn't derivable by stripping a prefix).
     */
    public static function admin(string $path = '/admin', ?string $baseDomain = null): string
    {
        $base = $baseDomain ?? self::baseDomain();

        return request()->getScheme().'://admin.'.$base.self::portSuffix().$path;
    }

    /**
     * Phase 14B — request-INDEPENDENT URLs built from config('app.url'), for use in emails
     * (read later, possibly sent from a tenant host or a queue worker with no HTTP request).
     * Returns [scheme, base-host, portSuffix].
     */
    private static function configHost(): array
    {
        $appUrl = rtrim((string) config('app.url', 'https://zerobook.in'), '/');
        $parts = parse_url($appUrl) ?: [];
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'localhost';

        foreach (['admin.', 'www.'] as $prefix) {
            if (str_starts_with($host, $prefix)) {
                $host = substr($host, strlen($prefix));
            }
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return [$scheme, $host, $port];
    }

    /** Admin-surface URL from config (for emails). */
    public static function adminConfig(string $path = '/admin'): string
    {
        [$scheme, $host, $port] = self::configHost();

        return "{$scheme}://admin.{$host}{$port}{$path}";
    }

    /** Tenant-subdomain URL from config (for emails). */
    public static function tenantConfig(string $slug, string $path = '/'): string
    {
        [$scheme, $host, $port] = self::configHost();

        return "{$scheme}://{$slug}.{$host}{$port}{$path}";
    }
}
