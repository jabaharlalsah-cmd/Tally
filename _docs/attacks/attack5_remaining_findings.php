<?php
/**
 * ATTACK 5 — the remaining MECHANICAL findings (not the two design questions).
 * Polarity: PASS = code correct, FAIL = bug present.
 */
require 'C:/laragon/www/tally/vendor/autoload.php';
$app = require 'C:/laragon/www/tally/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{AccountGroup, Company, Ledger, WebhookDelivery, WebhookSubscription};
use App\Services\Api\Webhooks\{WebhookDispatcher, WebhookEmitter};
use App\Services\Tenancy\TenantProvisioner;
use App\Support\ActiveCompany;
use Illuminate\Support\Facades\{DB, Http};

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

$SLUG = 'atk5';
$prov = app(TenantProvisioner::class);
$prov->teardown($SLUG);
$t = $prov->provision($SLUG, 'Attack Five', 'enterprise');

echo "\n=== ATTACK 5 — remaining mechanical findings ===\n";

$t->run(function () {
    $company = Company::defaultCompany();
    ActiveCompany::set($company->id);
    $g = fn ($n) => AccountGroup::where('name', $n)->value('id');
    $cash = Ledger::where('name', 'Cash')->value('id');
    $sales = Ledger::create(['name' => 'SalesZ', 'group_id' => $g('Sales Accounts')])->id;
    $post = fn ($amt) => (new App\Livewire\VoucherScreen)->post([
        'type' => 'journal', 'date' => '2026-07-15',
        'lines' => [['ledger_id' => $cash, 'dr_cr' => 'Dr', 'amount' => $amt],
                    ['ledger_id' => $sales, 'dr_cr' => 'Cr', 'amount' => $amt]],
    ]);
    $mkSub = fn () => WebhookSubscription::create([
        'url' => 'https://r.test/h', 'event_types_json' => ['*'], 'authorized_company_ids_json' => [],
        'secret' => str_repeat('i', 64), 'is_active' => true,
    ]);

    // ───────────────────────────── G1. redeliver() on a live row must not double-send
    echo "\n-- G1: redelivering a PENDING row must not put two copies of one event in flight --\n";
    WebhookSubscription::query()->delete(); WebhookDelivery::query()->delete();
    WebhookEmitter::flushCache();
    $mkSub(); $post(400);
    $row = WebhookDelivery::first();
    check('one delivery is queued', WebhookDelivery::count(), 1);

    try { app(WebhookDispatcher::class)->redeliver($row); } catch (Throwable $e) { /* refusal is a valid answer */ }

    $live = WebhookDelivery::where('event_id', $row->event_id)
        ->whereIn('status', [WebhookDelivery::STATUS_PENDING, WebhookDelivery::STATUS_DELIVERING])->count();
    check('redelivering an un-sent row does not create a SECOND live copy', $live, 1);

    receiver(200);
    app(WebhookDispatcher::class)->dispatchDue();
    check('… so the receiver is POSTed exactly once for that event', count(Http::recorded()), 1);

    // ───────────────────────────── G2. one unreadable subscription row must not kill emission
    echo "\n-- G2: a single corrupt subscription row must not stop OTHER subscriptions --\n";
    WebhookSubscription::query()->delete(); WebhookDelivery::query()->delete();
    WebhookEmitter::flushCache();
    $healthy = $mkSub();
    // A row whose encrypted secret is unreadable — a restored/partial backup, a rotated APP_KEY.
    // Reading it throws on decrypt; the question is whether that costs the healthy one its event.
    $badId = DB::table('webhook_subscriptions')->insertGetId([
        'url' => 'https://r.test/bad', 'event_types_json' => json_encode(['*']),
        'authorized_company_ids_json' => json_encode([]), 'secret' => 'not-valid-ciphertext',
        'is_active' => 1, 'consecutive_failures' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    WebhookEmitter::flushCache();
    $post(401);
    check('the healthy subscription still receives its event',
        WebhookDelivery::where('webhook_subscription_id', $healthy->id)->count(), 1);
    DB::table('webhook_subscriptions')->where('id', $badId)->delete();
    WebhookEmitter::flushCache();

    // ───────────────────────────── G3. emission must key the cache by the TENANT database
    echo "\n-- G3: emission inside a CENTRAL transaction must still read the tenant's subscriptions --\n";
    WebhookSubscription::query()->delete(); WebhookDelivery::query()->delete();
    WebhookEmitter::flushCache();
    $mkSub();
    $central = config('tenancy.database.central_connection', 'mysql');
    DB::connection($central)->transaction(function () use ($post) { $post(402); });
    check('a post wrapped in a CENTRAL transaction still emits to the tenant subscription',
        WebhookDelivery::count() >= 1, true);
});

echo "\n=== ATTACK 5 RESULT: {$pass} passed, {$fail} failed ===\n";
if ($failures) { echo "FAILURES:\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
$prov->teardown($SLUG);
exit($fail === 0 ? 0 : 1);
