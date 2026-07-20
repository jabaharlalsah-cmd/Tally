<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$guard = Auth::guard('tenant');
$ok = $guard->attempt([
    'email' => 'nslpoint@gmail.com',
    'password' => '12345678',
    'tenant_id' => 'nslpoint',
], false);
echo $ok ? "ATTEMPT_OK\n" : "ATTEMPT_FAIL\n";
if ($ok) {
    echo $guard->user()->email . "\n";
}
