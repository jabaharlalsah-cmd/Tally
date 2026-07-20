<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Phase 14A — the rules for what a public self-signup may claim as its subdomain.
 *
 * A tenant's subdomain is its identity: it becomes both the DNS label
 * (acme.zerobook.in) and, through stancl, the database name (tenantacme). Once handed
 * out it is effectively permanent, so the checks here are deliberately strict — a
 * valid DNS label, not reserved, not already taken.
 *
 * `admin` is the platform-admin subdomain; `www`/`api`/`mail`/… are infrastructure.
 * The full reserved list lives in config/zerobook.php so it is tunable without a code
 * change. Existing tenant slugs are rejected by the taken-check, not the reserved list,
 * so a real customer name is never mistaken for a reserved word.
 */
class Subdomain
{
    /** Minimum length — single/double-letter subdomains are disallowed for signups. */
    public const MIN_LENGTH = 3;

    /** A DNS label maxes at 63 chars; the effective cap is smaller (see maxLength()). */
    public const DNS_MAX_LENGTH = 63;

    /**
     * The longest subdomain we can actually provision. The tenant DATABASE is named
     * prefix + slug + suffix (stancl), and a MySQL identifier maxes at 64 chars — so a
     * 63-char slug that passes the DNS-label rule would still make CreateDatabase fail
     * with "Identifier name too long". Derived from the tenancy config so a prefix change
     * stays consistent (with the default 'tenant' prefix this is 58).
     */
    public static function maxLength(): int
    {
        $prefix = strlen((string) config('tenancy.database.prefix', 'tenant'));
        $suffix = strlen((string) config('tenancy.database.suffix', ''));

        return min(self::DNS_MAX_LENGTH, 64 - $prefix - $suffix);
    }

    public static function normalize(string $slug): string
    {
        return strtolower(trim($slug));
    }

    /** The reserved words, lowercased, from config. */
    public static function reserved(): array
    {
        return array_map('strtolower', (array) config('zerobook.reserved_subdomains', []));
    }

    /** A valid DNS label (what stancl's subdomain identification also requires). */
    public static function isValidLabel(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $slug);
    }

    public static function isReserved(string $slug): bool
    {
        return in_array(self::normalize($slug), self::reserved(), true);
    }

    /** Already provisioned (or provisioning)? Keyed on the tenant slug PK. */
    public static function isTaken(string $slug): bool
    {
        return Tenant::whereKey(self::normalize($slug))->exists();
    }

    /**
     * The single source of truth the signup form, the availability endpoint, and the
     * provisioner all consult. Returns ['available' => bool, 'reason' => string].
     */
    public static function availability(string $raw): array
    {
        $slug = self::normalize($raw);

        [$available, $reason] = match (true) {
            $slug === '' => [false, 'Enter a subdomain.'],
            mb_strlen($slug) < self::MIN_LENGTH => [false, 'Too short — use at least '.self::MIN_LENGTH.' characters.'],
            mb_strlen($slug) > self::maxLength() => [false, 'Too long — '.self::maxLength().' characters max.'],
            ! self::isValidLabel($slug) => [false, 'Use lowercase letters, digits and hyphens only (a valid web address).'],
            self::isReserved($slug) => [false, 'That subdomain is reserved.'],
            self::isTaken($slug) => [false, 'That subdomain is already taken.'],
            default => [true, 'Available'],
        };

        return ['available' => $available, 'reason' => $reason];
    }

    /** Convenience boolean. */
    public static function isAvailable(string $raw): bool
    {
        return self::availability($raw)['available'];
    }
}
