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

if (! function_exists('baseSymbol')) {
    /**
     * The active company's base-currency symbol, for the Dibi Tech rule that
     * every amount shown or entered carries its currency symbol.
     *
     * Reads the company's OWN base currency rather than assuming ₹: ZeroBook
     * runs Nepali (NPR) tenants too, and hardcoding ₹ would misreport their
     * books. Falls back to ₹ only when no base currency is set yet.
     *
     * Memoised per (database × company) for the same reason Shell's feature memo
     * is: one process can walk several tenants and every tenant's first company
     * is id 1.
     */
    function baseSymbol(): string
    {
        static $memo = [];

        $company = ActiveCompany::id();
        if ($company === null) {
            return '₹';
        }

        $key = \Illuminate\Support\Facades\DB::connection()->getDatabaseName().'#'.$company;
        if (! array_key_exists($key, $memo)) {
            $memo[$key] = \App\Models\Currency::base()?->symbol ?: '₹';
        }

        return $memo[$key];
    }
}
