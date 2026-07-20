<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$user = App\Models\TenantUser::where('email', 'nslpoint@gmail.com')->first();
if ($user) {
    echo $user->email . PHP_EOL;
    echo $user->tenant_id . PHP_EOL;
    echo $user->password . PHP_EOL;
} else {
    echo 'NO_USER' . PHP_EOL;
}
