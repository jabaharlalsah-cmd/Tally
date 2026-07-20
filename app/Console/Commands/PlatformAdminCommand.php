<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Phase 7B — create (or update) a ZeroBook platform operator in the CENTRAL database.
 *
 *   php artisan zerobook:platform-admin ops@zerobook.in "Ops" --password=secret
 */
class PlatformAdminCommand extends Command
{
    protected $signature = 'zerobook:platform-admin {email} {name?} {--password= : set an explicit password (else one is generated)}';

    protected $description = 'Create or update a platform admin (central database)';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $password = $this->option('password') ?: Str::password(14);

        $admin = PlatformAdmin::updateOrCreate(
            ['email' => $email],
            ['name' => $this->argument('name') ?: 'Platform Admin', 'password' => Hash::make($password)],
        );

        $this->info("Platform admin “{$admin->email}” saved.");
        if (! $this->option('password')) {
            $this->line("  Generated password: {$password}");
        }

        return self::SUCCESS;
    }
}
