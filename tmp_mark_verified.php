<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$user = App\Models\TenantUser::where('email', 'nslpoint@gmail.com')->where('tenant_id', 'nslpoint')->first();
if (! $user) {
    echo "NOT_FOUND\n";
    exit(0);
}
$user->forceFill(['verified_at' => now()])->save();
echo "UPDATED\n";
