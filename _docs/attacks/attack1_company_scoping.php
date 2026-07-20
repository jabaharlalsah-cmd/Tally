<?php
/**
 * ATTACK 1 — company-scoping privilege escalation, driven through the REAL HTTP kernel.
 *
 * Test polarity (the rule this re-review exists to enforce): every check is written so it PASSES
 * if the code is CORRECT and FAILS if the bug is present. No check asserts its own premise.
 *
 * Run with:  php attack1_company_scoping.php [--cached]
 *   --cached  runs every attack against a REAL cached route table (what production runs).
 */
require 'C:/laragon/www/tally/vendor/autoload.php';
$app = require 'C:/laragon/www/tally/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{Company, Tenant, WebhookSubscription};
use App\Services\Api\ApiKeyService;
use App\Services\CompanyProvisioner;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\ActiveCompany;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

$CACHED = in_array('--cached', $argv, true);
$SLUG = 'atk1';
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
$prov->teardown($SLUG);
$tenant = $prov->provision($SLUG, 'Attack One', 'enterprise');

$keys = app(ApiKeyService::class);

$ctx = $tenant->run(function () {
    $a = Company::defaultCompany();                       // the key's OWN company
    $b = app(CompanyProvisioner::class)->create('Forbidden Co');   // the one it must never reach
    return ['a' => $a->id, 'b' => $b->id];
});

// A key restricted to company A only — the exact 'selected' mode the API Keys UI issues.
$restricted = $keys->generate(tenant: $tenant, user: null, name: 'OnlyA', permissions: ['*'], companyIds: [$ctx['a']])['key'];
$allKey     = $keys->generate(tenant: $tenant, user: null, name: 'All',   permissions: ['*'], companyIds: [])['key'];

function dispatch(string $key, string $method, string $uri, ?array $body = null, ?string $idem = null): array {
    $server = [
        'HTTP_AUTHORIZATION' => 'Bearer '.$key,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];
    if ($idem !== null) $server['HTTP_IDEMPOTENCY_KEY'] = $idem;
    $req = Request::create($uri, $method, [], [], [], $server, $body === null ? null : json_encode($body));
    $k = app(HttpKernel::class);
    $res = $k->handle($req);
    $k->terminate($req, $res);
    return ['status' => $res->getStatusCode(), 'json' => json_decode($res->getContent(), true)];
}

echo "\n=== ATTACK 1 — company-scoping escalation ".($CACHED ? "[ROUTES CACHED — production mode]" : "[routes uncached]")." ===\n";
echo "key restricted to company {$ctx['a']}; company {$ctx['b']} is forbidden to it\n\n";

// ---------------------------------------------------------------- CREATE paths
echo "-- CREATE --\n";
$r = dispatch($restricted, 'POST', '/api/v1/webhooks',
    ['url' => 'https://r.test/h', 'event_types' => ['*']], 'c1');
check('omitting authorized_company_ids does NOT yield all-companies',
    $r['json']['authorized_company_ids'] ?? null, [$ctx['a']]);
$ownSub = $r['json']['id'] ?? null;

$r = dispatch($restricted, 'POST', '/api/v1/webhooks',
    ['url' => 'https://r.test/h', 'event_types' => ['*'], 'authorized_company_ids' => [$ctx['b']]], 'c2');
check('naming a FORBIDDEN company is refused', $r['status'], 403);

$r = dispatch($restricted, 'POST', '/api/v1/webhooks',
    ['url' => 'https://r.test/h', 'event_types' => ['*'], 'authorized_company_ids' => []], 'c3');
check('asking for [] (all companies) explicitly is refused', $r['status'], 403);

$r = dispatch($restricted, 'POST', '/api/v1/webhooks',
    ['url' => 'https://r.test/h', 'event_types' => ['*'], 'authorized_company_ids' => [$ctx['a'], $ctx['b']]], 'c4');
check('a MIXED list (own + forbidden) is refused, not silently narrowed', $r['status'], 403);

// ---------------------------------------------------------------- UPDATE paths (the classic gap)
echo "\n-- UPDATE (the create rule must apply here too) --\n";
$r = dispatch($restricted, 'PUT', '/api/v1/webhooks/'.$ownSub,
    ['url' => 'https://r.test/h', 'event_types' => ['*'], 'authorized_company_ids' => [$ctx['b']]], 'u1');
check('widening an OWN subscription to a forbidden company is refused', $r['status'], 403);

$r = dispatch($restricted, 'PUT', '/api/v1/webhooks/'.$ownSub,
    ['url' => 'https://r.test/h', 'event_types' => ['*'], 'authorized_company_ids' => []], 'u2');
check('widening an OWN subscription to [] (all) is refused', $r['status'], 403);

$after = WebhookSubscription::on('tenant')->find($ownSub);
$tenant->run(function () use ($ownSub, $ctx) {
    $s = WebhookSubscription::find($ownSub);
    check('… and the stored scope is UNCHANGED after the refused widenings',
        $s->authorizedCompanyIds(), [$ctx['a']]);
});

$r = dispatch($restricted, 'PUT', '/api/v1/webhooks/'.$ownSub,
    ['url' => 'https://r.test/h2', 'event_types' => ['*']], 'u3');
check('an update that OMITS the field still succeeds (no accidental lockout)', $r['status'], 200);
$tenant->run(function () use ($ownSub, $ctx) {
    check('… and omission does not widen the scope',
        WebhookSubscription::find($ownSub)->authorizedCompanyIds(), [$ctx['a']]);
});

// ---------------------------------------------------------------- CROSS-KEY / foreign access
echo "\n-- CROSS-KEY ACCESS (foreign + wider subscriptions) --\n";
$foreign = $tenant->run(fn () => WebhookSubscription::create([
    'url' => 'https://owner.test/h', 'event_types_json' => ['*'],
    'authorized_company_ids_json' => [$ctx['b']],           // forbidden company
    'secret' => str_repeat('a', 64), 'is_active' => true,
])->id);
$wide = $tenant->run(fn () => WebhookSubscription::create([
    'url' => 'https://owner.test/h', 'event_types_json' => ['*'],
    'authorized_company_ids_json' => [],                     // all companies — wider than the key
    'secret' => str_repeat('b', 64), 'is_active' => true,
])->id);

foreach ([['foreign', $foreign], ['all-companies', $wide]] as [$name, $id]) {
    check("GET a {$name} subscription is 404", dispatch($restricted, 'GET', '/api/v1/webhooks/'.$id)['status'], 404);
    check("… its DELIVERY LOG (other companies' payloads) is 404",
        dispatch($restricted, 'GET', '/api/v1/webhooks/'.$id.'/deliveries')['status'], 404);
    check("… ROTATE-SECRET on it is 404",
        dispatch($restricted, 'POST', '/api/v1/webhooks/'.$id.'/rotate-secret', [], 'x'.$id)['status'], 404);
    check("… REPOINTING it at an attacker URL is 404",
        dispatch($restricted, 'PUT', '/api/v1/webhooks/'.$id, ['url' => 'https://evil.test/x', 'event_types' => ['*']], 'y'.$id)['status'], 404);
    check("… DELETING it is 404", dispatch($restricted, 'DELETE', '/api/v1/webhooks/'.$id)['status'], 404);
    check("… SEND-TEST on it is 404",
        dispatch($restricted, 'POST', '/api/v1/webhooks/'.$id.'/test', [], 'z'.$id)['status'], 404);
}

$listed = collect(dispatch($restricted, 'GET', '/api/v1/webhooks')['json']['data'] ?? [])->pluck('id')->all();
check('the LIST shows neither the foreign nor the all-companies subscription',
    array_values(array_intersect($listed, [$foreign, $wide])), []);

// The subscriptions really do still exist — proving the 404s are scoping, not a broken fixture.
$tenant->run(function () use ($foreign, $wide) {
    check('(control) both subscriptions genuinely exist in the tenant',
        WebhookSubscription::whereIn('id', [$foreign, $wide])->count(), 2);
});
$allListed = collect(dispatch($allKey, 'GET', '/api/v1/webhooks')['json']['data'] ?? [])->pluck('id')->all();
check('(control) an ALL-companies key DOES see both — the scope is not just breaking everything',
    count(array_intersect($allListed, [$foreign, $wide])), 2);
check('(control) and it can read a foreign delivery log',
    dispatch($allKey, 'GET', '/api/v1/webhooks/'.$foreign.'/deliveries')['status'], 200);

echo "\n=== ".($CACHED ? 'CACHED' : 'UNCACHED')." RESULT: {$pass} passed, {$fail} failed ===\n";
if ($failures) { echo "FAILURES:\n"; foreach ($failures as $f) echo "  - {$f}\n"; }

$prov->teardown($SLUG);
\App\Models\ApiKeyDirectory::where('tenant_id', $SLUG)->delete();
exit($fail === 0 ? 0 : 1);
