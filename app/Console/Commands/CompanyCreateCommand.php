<?php

namespace App\Console\Commands;

use App\Console\Concerns\RunsInTenantContext;
use App\Models\Company;
use App\Services\CompanyProvisioner;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Phase 12A — add another company to an EXISTING tenant.
 *
 *   php artisan zerobook:company-create --tenant=acmefirm --name="Beta Trading Co"
 *
 * The new company gets exactly what a fresh tenant's default company gets: the
 * 28 reserved groups, Cash + P&L, the 9 duty ledgers, Sales/Purchase Return, the
 * TDS rate table, the INR base currency, the forex Gain/Loss ledgers, Main
 * Location, and a fresh all-off F11 profile — the CA configures each client book
 * from zero. (The Companies screen does the same thing interactively.)
 */
class CompanyCreateCommand extends Command
{
    use RunsInTenantContext;

    protected $signature = 'zerobook:company-create
        {--tenant= : the tenant subdomain to add the company to}
        {--name= : the company name}
        {--slug= : optional URL-safe short name (derived from the name if omitted)}';

    protected $description = 'Create a new, fully seeded company inside an existing tenant (Phase 12A)';

    public function handle(CompanyProvisioner $provisioner): int
    {
        $name = trim((string) $this->option('name'));
        if ($name === '') {
            $this->error('Pass --name="The Company Name".');

            return self::FAILURE;
        }

        $scope = $this->resolveTenantScope();
        if ($scope === null) {
            return self::FAILURE;
        }

        return $scope(function () use ($provisioner, $name) {
            try {
                $company = $provisioner->create($name, $this->option('slug') ?: null);
            } catch (ValidationException $e) {
                $this->error(collect($e->errors())->flatten()->implode(' '));

                return self::FAILURE;
            }

            $this->info("✓ Company “{$company->name}” created (slug {$company->slug}, id {$company->id}).");
            $this->line('  Seeded: 28 account groups · Cash + P&L · duty ledgers · TDS catalog · INR · forex ledgers · Main Location · fresh F11.');
            $this->line('  Companies in this tenant: '.Company::orderBy('id')->pluck('slug')->implode(', '));

            return self::SUCCESS;
        });
    }
}
