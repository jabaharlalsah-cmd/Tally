<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Support\ActiveCompany;
use Illuminate\Database\Seeder;

/**
 * Phase 12A — the FIRST seeder DatabaseSeeder runs.
 *
 * Two duties, both load-bearing for everything after it:
 *
 *  1. Guarantee the default company exists. On a normally provisioned tenant the
 *     12A migration already created it (named after the tenant) — this is then a
 *     no-op lookup. On any exotic path where seeding runs without it, create it.
 *
 *  2. Set it as the ACTIVE company for the rest of the seeder run, so every
 *     downstream seeder (groups, ledgers, duty ledgers, TDS catalog, currencies,
 *     forex ledgers) stamps its rows with this company via BelongsToCompany.
 */
class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::defaultCompany(); // lowest-id ACTIVE company — same target every CLI command resolves

        if (! $company) {
            $tenantName = function_exists('tenant') && tenant() ? (tenant()->name ?? null) : null;
            $name = trim((string) $tenantName) !== '' ? trim((string) $tenantName) : 'Default Company';

            $company = Company::create([
                'name' => $name,
                'slug' => Company::uniqueSlugFor($name),
            ]);
        }

        ActiveCompany::set($company->id);
    }
}
