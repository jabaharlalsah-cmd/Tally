<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Phase 7B — create (or update) a login for a tenant's app (CENTRAL database).
 *
 *   php artisan zerobook:tenant-user acme owner@acme.com "Owner" --password=secret --role=owner
 *
 * The identity lives centrally keyed by (tenant_id, email), so the same email can be
 * a user of several tenants.
 */
class TenantUserCommand extends Command
{
    protected $signature = 'zerobook:tenant-user
        {tenant : the tenant subdomain}
        {email}
        {name?}
        {--password= : set an explicit password (else one is generated)}
        {--role=member : owner | accountant | member}';

    protected $description = 'Create or update a tenant user (central database)';

    public function handle(): int
    {
        $slug = (string) $this->argument('tenant');
        if (! Tenant::whereKey($slug)->exists()) {
            $this->error("Unknown tenant “{$slug}”. Provision it first.");

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');
        $password = $this->option('password') ?: Str::password(14);

        $user = TenantUser::updateOrCreate(
            ['tenant_id' => $slug, 'email' => $email],
            [
                'name' => $this->argument('name') ?: 'User',
                'password' => Hash::make($password),
                'role' => (string) $this->option('role'),
            ],
        );

        $this->info("Tenant user “{$user->email}” ({$user->role}) saved for tenant “{$slug}”.");
        if (! $this->option('password')) {
            $this->line("  Generated password: {$password}");
        }

        return self::SUCCESS;
    }
}
