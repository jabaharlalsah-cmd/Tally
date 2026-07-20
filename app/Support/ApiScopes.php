<?php

namespace App\Support;

/**
 * Phase 16A — the permission-scope catalog.
 *
 * One list, consulted by three places that must never drift apart: the key-issuing UI
 * (which checkboxes exist), ApiKeyService::generate() (what it will accept), and
 * EnforceApiPermissions (what a route may demand). A scope that is not in ALL() cannot be
 * granted, so a typo in a route's scope declaration fails closed — the key can never hold
 * the misspelled scope, so the route 403s rather than silently admitting everyone.
 *
 * 16A ships no endpoint that consumes a business scope: /api/v1/ping requires none. The
 * catalog is defined in full now so 16B–16D declare against a fixed vocabulary instead of
 * inventing scope strings per phase.
 */
class ApiScopes
{
    /** Grants every scope. For first-party integrations where the customer owns both sides. */
    public const WILDCARD = '*';

    /** The ping diagnostic's sentinel: authenticated, but no scope demanded. */
    public const NONE = 'none';

    /** scope => human description (the UI checkbox label). */
    public const CATALOG = [
        'voucher:create' => 'Create vouchers (16B)',
        'voucher:read' => 'Read vouchers (16B)',
        'voucher:alter' => 'Alter existing vouchers (16B)',
        'voucher:cancel' => 'Cancel vouchers (16B)',
        'master:read' => 'Read masters — ledgers, stock items, cost centres (16B)',
        'master:write' => 'Create and edit masters (16B)',
        'report:read' => 'Read reports — Trial Balance, day book, outstandings (16B)',
        'webhook:manage' => 'Manage outbound webhooks (16C)',
        'event:ingest' => 'Ingest inbound events (16D)',
        self::WILDCARD => 'All scopes — full access (use sparingly)',
    ];

    /** Every grantable scope, wildcard included. */
    public static function all(): array
    {
        return array_keys(self::CATALOG);
    }

    /** Grantable scopes minus the wildcard — the individually-checkable ones. */
    public static function granular(): array
    {
        return array_values(array_diff(self::all(), [self::WILDCARD]));
    }

    public static function isValid(string $scope): bool
    {
        return array_key_exists($scope, self::CATALOG);
    }

    /**
     * Keep only real scopes, de-duplicated. An unknown string is DROPPED, never passed
     * through — so a key can only ever hold scopes this catalog defines.
     */
    public static function sanitize(array $scopes): array
    {
        $clean = [];

        foreach ($scopes as $scope) {
            if (! is_string($scope)) {
                continue;
            }

            $scope = trim($scope);

            if (self::isValid($scope) && ! in_array($scope, $clean, true)) {
                $clean[] = $scope;
            }
        }

        return $clean;
    }

    /**
     * Does $granted satisfy $required?
     *
     * Fail-closed in both directions: an empty grant list satisfies nothing, and a required
     * scope that is not in the catalog is never satisfiable (a typo cannot open a route).
     */
    public static function satisfies(array $granted, string $required): bool
    {
        if ($required === self::NONE) {
            return true;   // the route demands nothing beyond being authenticated
        }

        if (! self::isValid($required)) {
            return false;  // unknown requirement — refuse rather than guess
        }

        if (in_array(self::WILDCARD, $granted, true)) {
            return true;
        }

        return in_array($required, $granted, true);
    }
}
