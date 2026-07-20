<?php

namespace App\Support;

use App\Models\Company;

/**
 * Phase 12A — THE active-company authority.
 *
 * One tenant database holds N companies; every read and write must be pinned to
 * exactly one of them. This holder is the single source the BelongsToCompany
 * global scope reads. It is deliberately PROCESS state, not the HTTP session,
 * because the scope must also resolve where no session exists:
 *
 *   • web requests    — the SetActiveCompany middleware resolves the session's
 *                       active_company_id and calls set() before any controller,
 *                       Livewire action, or service runs;
 *   • artisan/imports — commands resolve a company explicitly (the
 *                       ResolvesActiveCompany concern / --company=) and call set();
 *   • provisioning    — CompanySeeder / CompanyProvisioner set it before seeding.
 *
 * check() is the fail-closed accessor: writes (BelongsToCompany@creating) refuse
 * to stamp a row when no company is active rather than guessing one.
 */
class ActiveCompany
{
    private static ?int $id = null;

    /**
     * Memo of the active Company row. Invalidated when the id changes AND when
     * the underlying DATABASE changes: one artisan process can initialize
     * several tenants in a row, and every tenant's default company is id 1 —
     * an id-only memo would serve tenant A's identity (GSTIN/state/FY month)
     * while tenant B is active. $companyDb records which database the memo
     * was loaded from; company() re-fetches whenever the current connection's
     * database differs.
     */
    private static ?Company $company = null;

    private static ?string $companyDb = null;

    public static function set(?int $id): void
    {
        if (self::$id !== $id) {
            self::$company = null;
            self::$companyDb = null;
        }

        self::$id = $id;
    }

    public static function id(): ?int
    {
        return self::$id;
    }

    /** The active company id, or a hard failure — never a guess. */
    public static function check(): int
    {
        if (self::$id === null) {
            throw new \RuntimeException(
                'No active company is set. Web requests get one from the SetActiveCompany '.
                'middleware; artisan commands must resolve one explicitly (--company= or '.
                'the ResolvesActiveCompany concern).'
            );
        }

        return self::$id;
    }

    /** The active Company row (memoised until the id OR the database changes). */
    public static function company(): ?Company
    {
        if (self::$id === null) {
            return null;
        }

        $db = \Illuminate\Support\Facades\DB::connection()->getDatabaseName();

        if (self::$company === null || self::$company->id !== self::$id || self::$companyDb !== $db) {
            self::$company = Company::find(self::$id);
            self::$companyDb = $db;
        }

        return self::$company;
    }

    /** Drop the memoised row after an update (e.g. F11 saved identity fields). */
    public static function refresh(): void
    {
        self::$company = null;
        self::$companyDb = null;
    }

    /** Run $work with $companyId active, restoring the previous company after. */
    public static function runAs(int $companyId, callable $work): mixed
    {
        $prev = self::$id;
        self::set($companyId);

        try {
            return $work();
        } finally {
            self::set($prev);
        }
    }
}
