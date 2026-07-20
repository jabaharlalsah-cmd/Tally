<?php

namespace App\Console\Concerns;

use App\Models\Company;
use App\Services\CompanyProvisioner;
use App\Support\ActiveCompany;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12A — company context for artisan commands (mirrors RunsInTenantContext).
 *
 * CLI has no session, so the BelongsToCompany scope reads nothing until a command
 * resolves a company explicitly. Three modes:
 *
 *   • --company=<slug|id>  — use that company (refuse loudly if unknown);
 *   • $fresh = true        — provision a THROWAWAY company (fully seeded chart via
 *                            CompanyProvisioner) — what every prove-* uses, so each
 *                            proof runs isolated in its own company and re-runs
 *                            never collide. Inside the prove's transaction it rolls
 *                            back with everything else;
 *   • $fresh = false       — the tenant's default company (lowest-id active).
 *
 * Returns null after printing an error when no company can be resolved — the
 * command should `return self::FAILURE`. FAIL-CLOSED: this concern never guesses
 * silently; a DB without the 12A migration is refused.
 */
trait ResolvesActiveCompany
{
    protected function resolveActiveCompany(bool $fresh = false, ?string $freshName = null): ?Company
    {
        if (! Schema::hasTable('companies')) {
            $this->error('This database has no companies table — run the Phase 12A migration first (php artisan tenants:migrate).');

            return null;
        }

        $wanted = method_exists($this, 'option') && $this->hasCompanyOption()
            ? trim((string) $this->option('company'))
            : '';

        if ($wanted !== '') {
            $company = ctype_digit($wanted)
                ? Company::find((int) $wanted)
                : Company::where('slug', $wanted)->first();

            if (! $company) {
                $this->error("No company \"{$wanted}\" in this tenant. Existing: ".
                    Company::orderBy('id')->pluck('slug')->implode(', '));

                return null;
            }

            ActiveCompany::set($company->id);

            return $company;
        }

        if ($fresh) {
            $name = $freshName ?: ('Prove '.class_basename($this).' '.(Company::max('id') + 1));
            $company = app(CompanyProvisioner::class)->create($name);
            ActiveCompany::set($company->id);

            return $company;
        }

        $company = Company::defaultCompany();

        if (! $company) {
            $this->error('This tenant has no company yet — provisioning is incomplete.');

            return null;
        }

        ActiveCompany::set($company->id);

        return $company;
    }

    private function hasCompanyOption(): bool
    {
        try {
            return $this->getDefinition()->hasOption('company');
        } catch (\Throwable) {
            return false;
        }
    }
}
