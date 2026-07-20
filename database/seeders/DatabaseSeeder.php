<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CompanySeeder::class,      // Phase 12A — MUST run first: resolves the default
                                       // company and sets it active so every seeder below
                                       // stamps its rows via BelongsToCompany.
            AccountGroupSeeder::class,
            LedgerSeeder::class,
            TaxLedgerSeeder::class,
            ReturnLedgerSeeder::class, // Phase 8A — Sales Return / Purchase Return
            TdsSectionSeeder::class,   // Phase 10A — the TDS rate table (idempotent)
            CurrencySeeder::class,     // Phase 11 — base currency (INR)
            ForexLedgerSeeder::class,  // Phase 11 — Foreign Exchange Gain / Loss
        ]);

        // Phase 12A — a re-run of tenants:seed is the established way to BACKFILL
        // new seed data onto existing tenants (ReturnLedgerSeeder, ForexLedgerSeeder
        // arrived that way). With N companies, the backfill must reach every ACTIVE
        // company, not just the default one CompanySeeder pinned above. All seeders
        // are idempotent (firstOrCreate/updateOrCreate under the company scope), so
        // for company #1 this second pass is a no-op.
        foreach (\App\Models\Company::where('is_active', true)->orderBy('id')->get()->slice(1) as $company) {
            \App\Support\ActiveCompany::runAs($company->id, function () {
                (new AccountGroupSeeder())->run();
                (new LedgerSeeder())->run();
                (new TaxLedgerSeeder())->run();
                (new ReturnLedgerSeeder())->run();
                (new TdsSectionSeeder())->run();
                (new CurrencySeeder())->run();
                (new ForexLedgerSeeder())->run();
            });
        }
    }
}
