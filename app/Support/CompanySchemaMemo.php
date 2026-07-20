<?php

namespace App\Support;

/**
 * Phase 12A / 14A — the shared "has company_id appeared yet?" memo for BelongsToCompany.
 *
 * BelongsToCompany must stay inert during the pre-12A migration window (when tds_sections
 * / currencies are seeded before their company_id column exists) and switch on the moment
 * the column appears. It remembers, POSITIVELY only and keyed by DATABASE + table, that a
 * column has been seen — a negative result is always re-checked, so "absent → present"
 * inside one provisioning process is observed.
 *
 * Phase 14A moves that store here (out of a per-model trait static) for one reason: it can
 * now be CLEARED for a database. A tenant DB that is dropped and a same-named one recreated
 * in the SAME process — a self-signup that rolls back, then the customer retries the same
 * subdomain, possibly served by the same reused PHP-FPM worker — would otherwise carry the
 * stale "column present" verdict into the recreated DB's early migrations and fail closed.
 * TenantProvisioner::teardown() forgets the database here so the retry starts clean. The key
 * includes the table name, so one shared store never collides across models.
 */
class CompanySchemaMemo
{
    /** "db.table" => true once company_id has been seen there. */
    private static array $seen = [];

    public static function has(string $key): bool
    {
        return ! empty(self::$seen[$key]);
    }

    public static function remember(string $key): void
    {
        self::$seen[$key] = true;
    }

    /** Forget every table verdict for a database (called when its schema is dropped). */
    public static function forgetDatabase(string $database): void
    {
        $prefix = $database.'.';
        foreach (array_keys(self::$seen) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$seen[$key]);
            }
        }
    }

    /** Test/utility: wipe the whole memo. */
    public static function flush(): void
    {
        self::$seen = [];
    }
}
