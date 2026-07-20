<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyFeature;
use App\Models\Currency;
use App\Models\Godown;
use App\Support\ActiveCompany;
use Database\Seeders\AccountGroupSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ForexLedgerSeeder;
use Database\Seeders\LedgerSeeder;
use Database\Seeders\ReturnLedgerSeeder;
use Database\Seeders\TaxLedgerSeeder;
use Database\Seeders\TdsSectionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 12A — create a NEW company inside the current tenant, fully seeded.
 *
 * A fresh company gets exactly what a fresh tenant's default company gets: the 28
 * reserved account groups, Cash + Profit & Loss A/c, the 9 duty ledgers, the
 * Sales/Purchase Return ledgers, the 15-section TDS catalog, the INR base
 * currency, the forex Gain/Loss ledgers, the reserved Main Location godown, and a
 * fresh all-off F11 row — so the CA configures each client book from zero.
 *
 * The seeding runs under ActiveCompany::runAs(new company), which is what makes
 * the existing seeders company-correct without any change to them: their
 * updateOrCreate(['name' => …]) lookups are scoped to the new company (match
 * nothing) and BelongsToCompany stamps the inserts.
 *
 * Used by: the Companies screen (create), zerobook:company-create, and every
 * prove-* command that provisions a throwaway company.
 */
class CompanyProvisioner
{
    public function create(string $name, ?string $slug = null, array $attributes = []): Company
    {
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'A company needs a name.']);
        }

        if (Company::where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => "A company named \"{$name}\" already exists in this tenant."]);
        }

        return DB::transaction(function () use ($name, $slug, $attributes) {
            $company = Company::create(array_merge([
                'name' => $name,
                'slug' => $slug ? Company::uniqueSlugFor($slug) : Company::uniqueSlugFor($name),
                'is_active' => true,
                'financial_year_start_month' => 4,
            ], $attributes));

            ActiveCompany::runAs($company->id, function () use ($company) {
                (new AccountGroupSeeder())->run();
                (new LedgerSeeder())->run();
                (new TaxLedgerSeeder())->run();
                (new ReturnLedgerSeeder())->run();
                (new TdsSectionSeeder())->run();
                (new CurrencySeeder())->run();
                (new ForexLedgerSeeder())->run();

                // Main Location is seeded by the godowns MIGRATION for the default
                // company; every further company seeds its own reserved row here.
                Godown::updateOrCreate(['name' => 'Main Location'], ['is_reserved' => true]);

                // The fresh all-off F11 row.
                CompanyFeature::current();

                $company->update([
                    'base_currency_id' => Currency::where('is_base', true)->value('id'),
                ]);
            });

            return $company;
        });
    }
}
