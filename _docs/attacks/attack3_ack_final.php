<?php
/**
 * ATTACK 3 — ack-is-final / double-delivery / lost-delivery.
 * Polarity: PASS = code correct, FAIL = bug present.
 */
require 'C:/laragon/www/tally/vendor/autoload.php';
$app = require 'C:/laragon/www/tally/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{AccountGroup, Company, Ledger, WebhookDelivery, WebhookSubscription};
use App\Services\Api\Webhooks\{WebhookDispatcher, WebhookEmitter};
use App\Services\Tenancy\TenantProvisioner;
use App\Support\ActiveCompany;
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
function receiver(int $status = 200) {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['r.test/*' => Http::response('ok', $status)]);
}

$prov = app(TenantProvisioner::class);
$prov->teardown('atk3');
$t = $prov->provision('atk3', 'Ack Three', 'enterprise');

echo "\n=== ATTACK 3 — ack is final ===\n";

$t->run(function () {
    $company = Company::defaultCompany();
    ActiveCompany::set($company->id);
    $g = fn ($n) => AccountGroup::where('name', $n)->value('id');
    $cash = Ledger::where('name', 'Cash')->value('id');
    $sales = Ledger::create(['name' => 'SalesY', 'group_id' => $g('Sales Accounts')])->id;
    $post = fn ($amt) => (new App\Livewire\VoucherScreen)->post([
        'type' => 'journal', 'date' => '2026-07-15',
        'lines' => [['ledger_id' => $cash, 'dr_cr' => 'Dr', 'amount' => $amt],
                    ['ledger_id' => $sales, 'dr_cr' => 'Cr', 'amount' => $amt]],
    ]);
    $mkSub = fn () => WebhookSubscription::create([
        'url' => 'https://r.test/h', 'event_types_json' => ['*'], 'authorized_company_ids_json' => [],
        'secret' => str_repeat('d', 64), 'is_active' => true,
    ]);
    $reset = function () use ($mkSub, $post) {
        WebhookSubscription::query()->delete(); WebhookDelivery::query()->delete();
        WebhookEmitter::flushCache();
        $s = $mkSub(); $post(rand(100, 999));
        return [$s, WebhookDelivery::first()];
    };

    // ───────────────────────────────────────────── 3.1 the claim is single-flight
    echo "\n-- 3.1 two overlapping workers must not both POST one row --\n";
    [$sub, $row] = $reset();
    $d = app(WebhookDispatcher::class);
    $claim = new ReflectionMethod($d, 'claim'); $claim->setAccessible(true);

    check('worker 1 wins the claim', $claim->invoke($d, $row), true);
    check('worker 2 LOSES the same claim (single-flight)', $claim->invoke($d, $row->fresh()), false);

    // The check above is weaker than it looks: MySQL reports 0 affected rows when an UPDATE
    // changes nothing, so a claim with NO status guard would still "lose" simply because
    // claimed_at happened to be identical within the same second. Back-date the claim so a
    // guardless UPDATE would genuinely change the row — now only a real status guard can refuse.
    WebhookDelivery::whereKey($row->id)->update(['claimed_at' => now()->subSeconds(30)]);   // claimed, NOT stale
    check('worker 2 still loses when its write would genuinely change the row',
        $claim->invoke($d, $row->fresh()), false);

    // Past claim_ttl the row IS reclaimable — deliberate, so a worker that died mid-flight cannot
    // strand a delivery forever. Asserted so the stale-recovery path stays intentional, not lucky.
    WebhookDelivery::whereKey($row->id)->update([
        'claimed_at' => now()->subSeconds((int) config('webhooks.claim_ttl') + 60),
    ]);
    check('… but a STALE claim is reclaimable (dead-worker recovery, by design)',
        $claim->invoke($d, $row->fresh()), true);

    // The margin that makes this safe: one attempt can never outlive the stale-claim window,
    // so a live-but-slow worker cannot have its row stolen mid-flight.
    $maxAttempt = (int) config('webhooks.timeout') + (int) config('webhooks.connect_timeout');
    check('claim_ttl exceeds the longest possible single attempt',
        (int) config('webhooks.claim_ttl') > $maxAttempt, true);

    // ───────────────────────────────────────────── 3.2 a SUCCEEDED row is terminal
    echo "\n-- 3.2 an acknowledged delivery can never be re-sent --\n";
    [$sub, $row] = $reset();
    receiver(200);
    app(WebhookDispatcher::class)->dispatchDue();
    $sent = count(Http::recorded());
    $row = $row->fresh();
    check('it delivered once', $sent, 1);
    check('… and is marked succeeded', $row->status, WebhookDelivery::STATUS_SUCCEEDED);

    check('a succeeded row cannot be re-claimed', $claim->invoke($d, $row), false);
    for ($i = 0; $i < 3; $i++) { app(WebhookDispatcher::class)->dispatchDue(); }
    check('… and three further ticks re-send nothing', count(Http::recorded()), $sent);

    // Force every field a scheduler looks at back to "due" — the row must STILL not go out,
    // because status is the authority, not the timestamps.
    WebhookDelivery::whereKey($row->id)->update(['next_attempt_at' => now()->subDay(), 'claimed_at' => null]);
    app(WebhookDispatcher::class)->dispatchDue();
    check('… even with next_attempt_at forced into the past, it is not re-sent',
        count(Http::recorded()), $sent);

    // ───────────────────────────────────────────── 3.3 redeliver is additive, not a resurrection
    echo "\n-- 3.3 manual redeliver must not resurrect the original row --\n";
    $before = $row->fresh();
    $new = app(WebhookDispatcher::class)->redeliver($before);
    $after = $before->fresh();
    check('the ORIGINAL row is still succeeded', $after->status, WebhookDelivery::STATUS_SUCCEEDED);
    check('… its attempt_count was not reset', $after->attempt_count, $before->attempt_count);
    check('a NEW row was created instead', $new->id !== $before->id, true);
    check('… carrying the SAME event_id so the receiver can dedupe', $new->event_id, $before->event_id);
    check('… and starting pending', $new->status, WebhookDelivery::STATUS_PENDING);

    // ───────────────────────────────────────────── 3.4 the time budget defers, never drops
    echo "\n-- 3.4 the wall-clock budget must defer rows, never drop them --\n";
    WebhookSubscription::query()->delete(); WebhookDelivery::query()->delete();
    WebhookEmitter::flushCache();
    $mkSub();
    foreach (range(1, 4) as $i) { $post(300 + $i); }
    $queued = WebhookDelivery::count();
    check('four events queued', $queued >= 4, true);

    receiver(200);
    config(['webhooks.max_seconds' => 0]);
    [$attempted] = app(WebhookDispatcher::class)->dispatchDue();
    config(['webhooks.max_seconds' => 240]);
    check('an exhausted budget attempts nothing', $attempted, 0);
    check('… POSTs nothing', count(Http::recorded()), 0);
    check('… leaves NO row stranded in delivering (claimed-then-abandoned)',
        WebhookDelivery::where('status', WebhookDelivery::STATUS_DELIVERING)->count(), 0);
    check('… and every row is still pending', WebhookDelivery::where('status', WebhookDelivery::STATUS_PENDING)->count(), $queued);
    app(WebhookDispatcher::class)->dispatchDue();
    check('… the next tick delivers all of them (deferred, not dropped)', count(Http::recorded()), $queued);

    // ───────────────────────────────────────────── 3.5 exhausted is terminal
    echo "\n-- 3.5 an exhausted delivery must stay dead --\n";
    [$sub, $row] = $reset();
    receiver(500);
    for ($i = 0; $i < WebhookDelivery::MAX_ATTEMPTS + 2; $i++) {
        WebhookDelivery::whereKey($row->id)->update(['next_attempt_at' => now()->subMinute()]);
        app(WebhookDispatcher::class)->dispatchDue();
    }
    $row = $row->fresh();
    check('the row exhausts after MAX_ATTEMPTS', $row->status, WebhookDelivery::STATUS_EXHAUSTED);
    check('… its attempt_count stopped at MAX_ATTEMPTS', $row->attempt_count, WebhookDelivery::MAX_ATTEMPTS);
    $postExhaust = count(Http::recorded());
    WebhookDelivery::whereKey($row->id)->update(['next_attempt_at' => now()->subDay(), 'claimed_at' => null]);
    app(WebhookDispatcher::class)->dispatchDue();
    check('… and nothing restarts it, even forced due', count(Http::recorded()), $postExhaust);
    check('… attempt_count was not reset by anything', $row->fresh()->attempt_count, WebhookDelivery::MAX_ATTEMPTS);
});

echo "\n=== ATTACK 3 RESULT: {$pass} passed, {$fail} failed ===\n";
if ($failures) { echo "FAILURES:\n"; foreach ($failures as $f) echo "  - {$f}\n"; }

$prov->teardown('atk3');
exit($fail === 0 ? 0 : 1);
