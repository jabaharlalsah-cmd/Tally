<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
$cands = ['secret','secret123','password','Password123','demo','zerobook','Zerobook@123','password123','12345678','Secret@123'];
foreach (DB::table('tenant_users')->get() as $r) {
    $hit = '(no match)';
    foreach ($cands as $c) { if (Hash::check($c, $r->password)) { $hit = $c; break; } }
    printf("%-12s %-30s %-8s %-11s => %s\n", $r->tenant_id, $r->email, $r->role, $r->verified_at?'verified':'UNVERIFIED', $hit);
}
