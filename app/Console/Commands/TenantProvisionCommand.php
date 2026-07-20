<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 7B — provision a tenant from the CLI (a UI can wrap this later).
 *
 *   php artisan zerobook:tenant-provision acme "Acme Traders" professional
 *
 * Idempotent: re-running completes a partially-failed provision (creates the DB if
 * missing, migrates, seeds once) without duplicating anything.
 */
class TenantProvisionCommand extends Command
{
    protected $signature = 'zerobook:tenant-provision
        {subdomain : the tenant subdomain / slug (e.g. acme → acme.zerobook.in)}
        {name? : display name (defaults to the capitalised slug)}
        {plan=starter : plan tier — starter | professional | enterprise | trial | paid-monthly}
        {--country= : india (GST/INR) | nepal (VAT/NPR) — sets the default company regime}';

    protected $description = 'Create (or repair) a tenant: central row, database, full migration set, and seed data';

    public function handle(TenantProvisioner $provisioner): int
    {
        $slug = (string) $this->argument('subdomain');

        $this->line('');
        $this->info("Provisioning tenant “{$slug}” …");

        try {
            $tenant = $provisioner->provision(
                $slug,
                $this->argument('name'),
                (string) $this->argument('plan'),
                $this->option('country') ?: null,
            );
        } catch (Throwable $e) {
            $this->error('Provisioning failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $dbName = $tenant->database()->getName();
        $seeded = Tenant::find($tenant->id)?->getInternal('seeded') ? 'yes' : 'no';

        $this->line('');
        $this->line('  Tenant     : '.$tenant->id.'  ('.$tenant->name.')');
        $this->line('  Plan       : '.($tenant->plan?->name ?? '—').' ['.($tenant->plan?->tier ?? '—').']');
        $this->line('  Database   : '.$dbName);
        $this->line('  Subdomain  : '.$tenant->id.'.<your-domain>  (domains row: '.$tenant->domains()->count().')');
        $this->line('  Status     : '.$tenant->status.'  · seeded: '.$seeded);
        $this->line('  Provisioned: '.$tenant->provisioned_at?->format('d-M-Y H:i'));
        $this->line('');
        $this->info("✓ “{$slug}” is ready. Its accounting data lives only in [{$dbName}].");

        return self::SUCCESS;
    }
}
