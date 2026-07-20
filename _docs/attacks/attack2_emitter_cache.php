<?php
/**
 * ATTACK 2 — the emitter's per-process cache. Can it DROP or MISDIRECT an event?
 *
 * Polarity: each check PASSES if the code is correct, FAILS if the bug is present.
 */
require 'C:/laragon/www/tally/vendor/autoload.php';
$app = require 'C:/laragon/www/tally/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{AccountGroup, Company, Ledger, Tenant, WebhookDelivery, WebhookSubscription};
use App\Services\CompanyProvisioner;
use App\Services\Tenancy\TenantProvisioner;
use App\Services\Api\Webhooks\WebhookEmitter;
use App\Support\ActiveCompany;

$pass = 0; $fail = 0; $failures = [];
function check(string $label, $actual, $expected) {
    global $pass, $fail, $failures;
    $ok = $actual === $expected;
    $ok ? $pass++ : $fail++;
    if (! $ok) $failures[] = $label;
    printf("  [%s] %s\n        got=%s want=%s\n", $ok ? 'PASS' : '*FAIL*', $label,
        json_encode($actual), json_encode($expected));
}

$prov = app(TenantProvisioner::class);
foreach (['atkA', 'atkB'] as $s) { $prov->teardown($s); }
$tA = $prov->provision('atkA', 'Cache A', 'enterprise');
$tB = $prov->provision('atkB', 'Cache B', 'enterprise');

/** Seed a company with two ledgers; returns ids. */
$seed = function (string $name = null) {
    $company = $name ? app(CompanyProvisioner::class)->create($name) : Company::defaultCompany();
    return ActiveCompany::runAs($company->id, function () use ($company) {
        $g = fn ($n) => AccountGroup::where('name', $n)->value('id');
        return [
            'company' => $company->id,
            'cash' => Ledger::where('name', 'Cash')->value('id') ?: Ledger::create(['name' => 'Cash', 'group_id' => $g('Cash-in-Hand')])->id,
            'sales' => Ledger::firstOrCreate(['name' => 'SalesX'], ['group_id' => $g('Sales Accounts')])->id,
        ];
    });
};
$postIn = function (array $c, float $amt) {
    ActiveCompany::runAs($c['company'], fn () => (new App\Livewire\VoucherScreen)->post([
        'type' => 'journal', 'date' => '2026-07-15',
        'lines' => [
            ['ledger_id' => $c['cash'], 'dr_cr' => 'Dr', 'amount' => $amt],
            ['ledger_id' => $c['sales'], 'dr_cr' => 'Cr', 'amount' => $amt],
        ],
    ]));
};
$mkSub = fn (array $companies = []) => WebhookSubscription::create([
    'url' => 'https://r.test/h', 'event_types_json' => ['*'],
    'authorized_company_ids_json' => $companies,
    'secret' => str_repeat('c', 64), 'is_active' => true,
]);

echo "\n=== ATTACK 2 — emitter cache ===\n";

// ─────────────────────────────────────────────────────────────── 2.1 new subscription seen at once
$tA->run(function () use ($seed, $postIn, $mkSub) {
    echo "\n-- 2.1 a brand-new subscription must be seen by the very next post --\n";
    $c = $seed();
    WebhookSubscription::query()->delete(); WebhookDelivery::query()->delete();
    WebhookEmitter::flushCache();
    $postIn($c, 10);                       // warm the cache with "no subscriptions"
    $mkSub();                              // create AFTER the cache says empty
    WebhookDelivery::query()->delete();
    $postIn($c, 11);
    check('a subscription created after the cache warmed still receives the next event',
        WebhookDelivery::count() >= 1, true);
});

// ─────────────────────────────────────────────────────── 2.2 deleted / disabled must stop receiving
$tA->run(function () use ($seed, $postIn, $mkSub) {
    echo "\n-- 2.2 a DELETED subscription must stop receiving (both delete styles) --\n";
    $c = $seed();

    // (a) model delete — fires events
    WebhookSubscription::query()->delete(); WebhookDelivery::query()->delete();
    WebhookEmitter::flushCache();
    $s = $mkSub();
    $postIn($c, 12);                        // cache now holds $s
    $s->delete();                           // model delete
    WebhookDelivery::query()->delete();
    $postIn($c, 13);
    check('after a MODEL delete, no further deliveries', WebhookDelivery::count(), 0);

    // (b) query-builder delete — fires NO model events. This is what the tenant UI does.
    WebhookSubscription::query()->delete(); WebhookDelivery::query()->delete();
    WebhookEmitter::flushCache();
    $s2 = $mkSub();
    $postIn($c, 14);                        // cache now holds $s2
    WebhookSubscription::whereKey($s2->id)->delete();   // EXACTLY WebhooksList::deleteWebhook()
    WebhookDelivery::query()->delete();
    $postIn($c, 15);
    check('after the UI\'s query-builder delete, no further deliveries', WebhookDelivery::count(), 0);

    echo "\n-- 2.2c THE BLAST RADIUS: does one stale row cost OTHER subscriptions their events? --\n";
    WebhookSubscription::query()->delete(); WebhookDelivery::query()->delete();
    WebhookEmitter::flushCache();
    $doomed = $mkSub();
    $healthy1 = $mkSub();
    $healthy2 = $mkSub();
    $postIn($c, 16);                        // cache holds all three
    WebhookSubscription::whereKey($doomed->id)->delete();   // the UI delete
    WebhookDelivery::query()->delete();
    $postIn($c, 17);
    check('the two SURVIVING subscriptions still receive the next event',
        WebhookDelivery::whereIn('webhook_subscription_id', [$healthy1->id, $healthy2->id])->count(), 2);
});

// ───────────────────────────────────────────────── 2.3 cross-company: no cache-key collision
$tA->run(function () use ($seed, $postIn, $mkSub) {
    echo "\n-- 2.3 one company's 'no subscriptions' must not suppress another's events --\n";
    $c1 = $seed();
    $c2 = $seed('Second Co');
    WebhookSubscription::query()->delete(); WebhookDelivery::query()->delete();
    WebhookEmitter::flushCache();

    $onlyC2 = $mkSub([$c2['company']]);      // authorized for company 2 ONLY
    $postIn($c1, 20);                        // company 1 posts: correctly matches nothing, warms cache
    check('company 1 (no matching subscription) creates no delivery', WebhookDelivery::count(), 0);

    $postIn($c2, 21);                        // company 2 posts: MUST still fire
    check('… and company 2 still receives its event (no cross-company suppression)',
        WebhookDelivery::where('webhook_subscription_id', $onlyC2->id)->count(), 1);
});

// ───────────────────────────────────────── 2.4 tenant switch inside ONE process (the 12A bug class)
echo "\n-- 2.4 a tenant switch in one process must not judge B by A's cache --\n";
$cA = $tA->run(function () use ($seed, $postIn, $mkSub) {
    $c = $seed();
    WebhookSubscription::query()->delete(); WebhookDelivery::query()->delete();
    WebhookEmitter::flushCache();
    $postIn($c, 30);                         // tenant A warms its cache with ZERO subscriptions
    check('tenant A has no subscriptions and creates no delivery', WebhookDelivery::count(), 0);
    return $c;
});
$tB->run(function () use ($seed, $postIn, $mkSub) {
    $c = $seed();
    WebhookSubscription::query()->delete(); WebhookDelivery::query()->delete();
    $mkSub();                                // tenant B DOES have one
    $postIn($c, 31);
    check('tenant B\'s event fires despite tenant A\'s empty cache in the same process',
        WebhookDelivery::count() >= 1, true);
});
$tA->run(function () use ($cA, $postIn) {
    WebhookDelivery::query()->delete();
    $postIn($cA, 32);
    check('…and switching back, tenant A is still empty (B\'s subs did not leak in)',
        WebhookDelivery::count(), 0);
});

echo "\n=== ATTACK 2 RESULT: {$pass} passed, {$fail} failed ===\n";
if ($failures) { echo "FAILURES:\n"; foreach ($failures as $f) echo "  - {$f}\n"; }

foreach (['atkA', 'atkB'] as $s) { $prov->teardown($s); }
exit($fail === 0 ? 0 : 1);
