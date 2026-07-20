<?php
/**
 * ATTACK 4 — verifying the highest-severity findings from the INDEPENDENT review.
 * Polarity: PASS = code correct, FAIL = bug present.
 */
require 'C:/laragon/www/tally/vendor/autoload.php';
$app = require 'C:/laragon/www/tally/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{AccountGroup, Company, Ledger, WebhookDelivery, WebhookSubscription};
use App\Services\Api\ApiKeyService;
use App\Services\Api\Webhooks\{WebhookDispatcher, WebhookEmitter};
use App\Services\CompanyProvisioner;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\ActiveCompany;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

$pass = 0; $fail = 0; $failures = [];
function check(string $label, $actual, $expected) {
    global $pass, $fail, $failures;
    $ok = $actual === $expected;
    $ok ? $pass++ : $fail++;
    if (! $ok) $failures[] = $label;
    printf("  [%s] %s\n        got=%s want=%s\n", $ok ? 'PASS' : '*FAIL*', $label,
        json_encode($actual), json_encode($expected));
}
function receiver(int $s = 200) { Http::swap(new Illuminate\Http\Client\Factory); Http::fake(['r.test/*' => Http::response('ok', $s)]); }

$SLUG = 'atk4';
$prov = app(TenantProvisioner::class);
$prov->teardown($SLUG);
$tenant = $prov->provision($SLUG, 'Attack Four', 'enterprise');
$keys = app(ApiKeyService::class);

$ctx = $tenant->run(function () {
    $a = Company::defaultCompany();
    $b = app(CompanyProvisioner::class)->create('Other Co');
    return ['a' => $a->id, 'b' => $b->id];
});
$keyA   = $keys->generate(tenant: $tenant, user: null, name: 'A',   permissions: ['*'], companyIds: [$ctx['a']])['key'];
$keyAll = $keys->generate(tenant: $tenant, user: null, name: 'All', permissions: ['*'], companyIds: [])['key'];

function dispatch(string $key, string $m, string $u, ?array $b = null, ?string $i = null): array {
    $srv = ['HTTP_AUTHORIZATION' => 'Bearer '.$key, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($i !== null) $srv['HTTP_IDEMPOTENCY_KEY'] = $i;
    $req = Request::create($u, $m, [], [], [], $srv, $b === null ? null : json_encode($b));
    $k = app(HttpKernel::class); $res = $k->handle($req); $k->terminate($req, $res);
    return ['status' => $res->getStatusCode(), 'json' => json_decode($res->getContent(), true)];
}

echo "\n=== ATTACK 4 — independent-review findings ===\n";

// ══════════════════════════ F1. delivery log is not company-filtered
echo "\n-- F1: narrowing a subscription must not expose its WIDER history --\n";
$subId = $tenant->run(function () use ($ctx) {
    ActiveCompany::set($ctx['a']);
    $s = WebhookSubscription::create(['url' => 'https://r.test/h', 'event_types_json' => ['*'],
        'authorized_company_ids_json' => [], 'secret' => str_repeat('e', 64), 'is_active' => true]);
    foreach ([$ctx['a'], $ctx['b']] as $cid) {
        WebhookDelivery::create(['webhook_subscription_id' => $s->id, 'event_id' => (string) Illuminate\Support\Str::ulid(),
            'event_type' => 'voucher.created', 'company_id' => $cid,
            'payload_json' => ['event' => 'voucher.created', 'company_id' => $cid, 'data' => ['secret_amount' => 4242]],
            'attempt_count' => 0, 'status' => 'pending', 'next_attempt_at' => now(), 'created_at' => now()]);
    }
    return $s->id;
});
check('control: key A cannot read the ALL-companies log', dispatch($keyA, 'GET', '/api/v1/webhooks/'.$subId.'/deliveries')['status'], 404);

dispatch($keyAll, 'PUT', '/api/v1/webhooks/'.$subId,
    ['url' => 'https://r.test/h', 'event_types' => ['*'], 'authorized_company_ids' => [$ctx['a']]], 'narrow');

$log = dispatch($keyA, 'GET', '/api/v1/webhooks/'.$subId.'/deliveries');
$foreign = collect($log['json']['data'] ?? [])->pluck('company_id')->reject(fn ($c) => $c === $ctx['a'])->values()->all();
check('after narrowing, key A sees NO other company\'s delivery rows', $foreign, []);

// ══════════════════════════ F2. is_active resurrection
echo "\n-- F2: a PUT that never mentions is_active must not resurrect a paused/disabled sub --\n";
$tenant->run(fn () => WebhookSubscription::whereKey($subId)->first()
    ->forceFill(['is_active' => false, 'disabled_at' => now(), 'disabled_reason' => 'auto', 'consecutive_failures' => 20])->save());

dispatch($keyAll, 'PUT', '/api/v1/webhooks/'.$subId, ['url' => 'https://r.test/h', 'event_types' => ['*'], 'description' => 'renamed'], 'noflag');
$after = $tenant->run(fn () => WebhookSubscription::find($subId));
check('a PUT omitting is_active leaves it paused', (bool) $after->is_active, false);
check('… and does not clear the auto-disable', $after->disabled_at !== null, true);
check('… and does not reset the failure budget', (int) $after->consecutive_failures, 20);

// ══════════════════════════ F3. claim() ignores next_attempt_at → re-POST inside backoff
echo "\n-- F3: a FAILED row must not be re-claimed before its backoff elapses --\n";
$tenant->run(function () use ($ctx) {
    ActiveCompany::set($ctx['a']);
    WebhookSubscription::query()->delete(); WebhookDelivery::query()->delete();
    WebhookEmitter::flushCache();
    $s = WebhookSubscription::create(['url' => 'https://r.test/h', 'event_types_json' => ['*'],
        'authorized_company_ids_json' => [], 'secret' => str_repeat('f', 64), 'is_active' => true]);
    $row = WebhookDelivery::create(['webhook_subscription_id' => $s->id, 'event_id' => (string) Illuminate\Support\Str::ulid(),
        'event_type' => 'voucher.created', 'company_id' => $ctx['a'], 'payload_json' => ['event' => 'voucher.created'],
        'attempt_count' => 0, 'status' => 'pending', 'next_attempt_at' => now(), 'created_at' => now()]);

    receiver(500);
    app(WebhookDispatcher::class)->dispatchDue();     // fails once → backoff 5s into the future
    $row = $row->fresh();
    check('the row failed and is scheduled into the future', $row->status === 'failed' && $row->next_attempt_at->isFuture(), true);

    // An overlapping tick already holds this row object. claim() is the arbiter — it must refuse,
    // because the row is NOT due yet. dueRows() enforces that; claim() must not disagree.
    $d = app(WebhookDispatcher::class);
    $m = new ReflectionMethod($d, 'claim'); $m->setAccessible(true);
    check('a not-yet-due FAILED row is NOT re-claimable (no re-POST inside backoff)',
        $m->invoke($d, $row), false);
});

// ══════════════════════════ F4. pausing must not destroy the backlog
echo "\n-- F4: pausing briefly must not permanently destroy queued events --\n";
$tenant->run(function () use ($ctx) {
    ActiveCompany::set($ctx['a']);
    WebhookSubscription::query()->delete(); WebhookDelivery::query()->delete();
    WebhookEmitter::flushCache();
    $s = WebhookSubscription::create(['url' => 'https://r.test/h', 'event_types_json' => ['*'],
        'authorized_company_ids_json' => [], 'secret' => str_repeat('g', 64), 'is_active' => true]);
    WebhookDelivery::create(['webhook_subscription_id' => $s->id, 'event_id' => (string) Illuminate\Support\Str::ulid(),
        'event_type' => 'voucher.created', 'company_id' => $ctx['a'], 'payload_json' => ['event' => 'voucher.created'],
        'attempt_count' => 0, 'status' => 'pending', 'next_attempt_at' => now(), 'created_at' => now()]);

    $s->forceFill(['is_active' => false])->save();     // customer pauses for a moment
    receiver(200);
    app(WebhookDispatcher::class)->dispatchDue();       // a tick lands while paused
    $s->forceFill(['is_active' => true])->save();       // customer resumes
    app(WebhookDispatcher::class)->dispatchDue();

    check('an event queued before a brief pause is still delivered after resuming',
        count(Http::recorded()), 1);
});

// ══════════════════════════ F5. narrowing must stop the already-QUEUED payload too
echo "\n-- F5: narrowing must not still DELIVER a removed company's queued payload --\n";
$tenant->run(function () use ($ctx) {
    ActiveCompany::set($ctx['a']);
    WebhookSubscription::query()->delete(); WebhookDelivery::query()->delete();
    WebhookEmitter::flushCache();
    $s = WebhookSubscription::create(['url' => 'https://r.test/h', 'event_types_json' => ['*'],
        'authorized_company_ids_json' => [], 'secret' => str_repeat('h', 64), 'is_active' => true]);
    foreach ([$ctx['a'], $ctx['b']] as $cid) {
        WebhookDelivery::create(['webhook_subscription_id' => $s->id, 'event_id' => (string) Illuminate\Support\Str::ulid(),
            'event_type' => 'voucher.created', 'company_id' => $cid,
            'payload_json' => ['event' => 'voucher.created', 'company_id' => $cid, 'data' => ['amount' => 4242]],
            'attempt_count' => 0, 'status' => 'pending', 'next_attempt_at' => now(), 'created_at' => now()]);
    }
    $s->forceFill(['authorized_company_ids_json' => [$ctx['a']]])->save();   // the narrowing

    receiver(200);
    app(WebhookDispatcher::class)->dispatchDue();

    $sent = collect(Http::recorded())->map(function ($p) {
        $b = json_decode((string) $p[0]->body(), true);
        return $b['company_id'] ?? null;
    })->filter()->unique()->values()->all();

    check('the still-authorized company IS delivered', in_array($ctx['a'], $sent, true), true);
    check('the REMOVED company\'s queued payload is NOT delivered', in_array($ctx['b'], $sent, true), false);
});

echo "\n=== ATTACK 4 RESULT: {$pass} passed, {$fail} failed ===\n";
if ($failures) { echo "FAILURES:\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
$prov->teardown($SLUG);
App\Models\ApiKeyDirectory::where('tenant_id', $SLUG)->delete();
exit($fail === 0 ? 0 : 1);
