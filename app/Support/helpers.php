<?php

use App\Models\Company;
use App\Support\ActiveCompany;

/*
 * Phase 12A — global helpers for the active company (autoloaded via composer).
 * Thin veneers over App\Support\ActiveCompany, which is the single authority.
 */

if (! function_exists('activeCompanyId')) {
    /** The active company id for this request/process, or null when none is set. */
    function activeCompanyId(): ?int
    {
        return ActiveCompany::id();
    }
}

if (! function_exists('activeCompany')) {
    /** The active Company row (memoised), or null when none is set. */
    function activeCompany(): ?Company
    {
        return ActiveCompany::company();
    }
}
