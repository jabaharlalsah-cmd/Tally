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

echo "FOUND\n";
echo Illuminate\Support\Facades\Hash::check('12345678', $user->password) ? "PASSWORD_OK\n" : "PASSWORD_BAD\n";
