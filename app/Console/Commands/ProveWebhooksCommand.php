<?php

namespace App\Console\Commands;

use App\Models\AccountGroup;
use App\Models\Company;
use App\Models\CompanyFeature;
use App\Models\Ledger;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\Api\ApiKeyService;
use App\Services\Api\Webhooks\WebhookDispatcher;
use App\Services\Api\Webhooks\WebhookEmitter;
use App\Services\Api\Webhooks\WebhookEvents;
use App\Services\Api\Webhooks\WebhookSigner;
use App\Services\CompanyProvisioner;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\ActiveCompany;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Phase 16C — prove the outbound webhook system.
 *
 * TWO HARNESSES, because 16C has two surfaces:
 *
 *  • INBOUND (the management API) — the real HTTP kernel, exactly like prove-api-core.
 *  • OUTBOUND (delivery) — Http::fake(), which swaps the Http factory the dispatcher resolves. The
 *    dispatcher must therefore run IN-PROCESS (we call it directly); an Artisan subprocess would
 *    not see the fake and would emit a real request to the internet.
 *
 * Http::assertSent() is deliberately NOT used anywhere here: it calls into PHPUnit, which is a
 * require-dev package, so it fatals under `composer install --no-dev`. A prove command is not a
 * phpunit test. Assertions go through Http::recorded(), which is PHPUnit-free.
 *
 * The three sections that matter most are §3 (post-commit only), §4 (signature correctness) and
 * §9 (a webhook failure never breaks a post).
 */
class ProveWebhooksCommand extends Command
{
    protected $signature = 'zerobook:prove-webhooks {--keep : keep the throwaway tenants provisioned}';

    protected $description = 'Provision tenants and prove the 16C outbound webhook system — post-commit emission, HMAC signing, retry/backoff, isolation';

    private bool $ok = true;

    private array $slugs = ['whooka', 'whookb'];

    private string $key = '';

    public function handle(TenantProvisioner $provisioner, ApiKeyService $keys): int
    {
        try {
            $this->section('1 · Provision + issue a webhook:manage key');
            foreach ($this->slugs as $slug) {
                $provisioner->teardown($slug);
                $t = $provisioner->provision($slug, strtoupper($slug).' Books', 'enterprise');
                $this->expect("provisioned {$slug}", $t->status, 'active');
            }
            $tenantA = Tenant::find('whooka');
            $tenantB = Tenant::find('whookb');

            $this->key = $keys->generate(tenant: $tenantA, user: null, name: 'Hooks', permissions: ['*'], companyIds: [])['key'];
            $this->expect('key issued', str_starts_with($this->key, 'zb_live_'), true);

            $tenantA->run(fn () => $this->runAll($tenantB));
        } catch (Throwable $e) {
            $this->ok = false;
            $this->error('Fatal: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());
        } finally {
            tenancy()->end();
            ActiveCompany::set(null);
            if ($this->option('keep')) {
                $this->info('Tenants KEPT (--keep).');
            } else {
                foreach ($this->slugs as $slug) {
                    $provisioner->teardown($slug);
                }
                \App\Models\ApiKeyDirectory::whereIn('tenant_id', $this->slugs)->delete();
                $this->info('Tenants torn down.');
            }
        }

        $this->line('');
        $this->info($this->ok
            ? 'ALL WEBHOOK ASSERTIONS PASSED — emission is post-commit only, signatures verify, delivery retries, and a webhook can never break a post.'
            : 'WEBHOOK ASSERTIONS FAILED.');

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    private function runAll(Tenant $tenantB): void
    {
        $c = $this->seed();

        // Everything below posts vouchers and counts them, so it must run pinned to the seeded
        // company — BelongsToCompany scopes both the writes and the counts, and seed()'s own
        // runAs() has already reverted by the time we get here.
        ActiveCompany::runAs($c['company'], function () use ($c, $tenantB) {
            $this->subscriptionLifecycle($c);
            $this->emissionMatching($c);
            $this->postCommitOnly($c);
            $this->signature($c);
            $this->deliveryAndRetry($c);
            $this->autoDisable($c);
            $this->concurrency($c);
            $this->isolation($c, $tenantB);
            $this->neverBreaksAPost($c);
            $this->outstandingDedup($c);
            $this->hotPathIsFree($c);
            $this->keyCannotOutreach($c, Tenant::find('whooka'));
            $this->ackIsFinal($c);
            $this->independentFindings($c, Tenant::find('whooka'));
            $this->wiring();
        });
    }

    // ── 2. Subscription lifecycle over the real API ──────────────────────────────────────

    private function subscriptionLifecycle(array $c): void
    {
        $this->section('2 · Subscription lifecycle (API) — the secret appears exactly once');

        $r = $this->dispatch('POST', '/api/v1/webhooks', [
            'url' => 'https://receiver.test/hook', 'event_types' => ['*'], 'description' => 'All events',
        ], 'sub-create');
        $this->expect('create → 201', $r['status'], 201);
        $secret = $r['json']['secret'] ?? null;
        $id = $r['json']['id'] ?? null;
        $this->expect('secret returned ONCE at create', is_string($secret) && strlen($secret) === 64, true);

        // Every other response must be structurally incapable of leaking it.
        $show = $this->dispatch('GET', '/api/v1/webhooks/'.$id);
        $this->expect('detail does NOT carry the secret', array_key_exists('secret', $show['json'] ?? []), false);
        $list = $this->dispatch('GET', '/api/v1/webhooks');
        $this->expect('list does NOT carry the secret', str_contains(json_encode($list['json']), $secret), false);

        // Rotate: the old secret must stop validating, the new one must work.
        $rot = $this->dispatch('POST', '/api/v1/webhooks/'.$id.'/rotate-secret', [], 'sub-rotate');
        $newSecret = $rot['json']['secret'] ?? null;
        $this->expect('rotate → 200 with a NEW secret once', is_string($newSecret) && $newSecret !== $secret, true);

        $ts = time();
        $body = '{"x":1}';
        $sigNew = WebhookSigner::sign($newSecret, $ts, $body);
        $this->expect('the NEW secret validates', WebhookSigner::verify($newSecret, $ts, $body, $sigNew), true);
        $this->expect('the OLD secret no longer validates', WebhookSigner::verify($secret, $ts, $body, $sigNew), false);

        $upd = $this->dispatch('PUT', '/api/v1/webhooks/'.$id, [
            'url' => 'https://receiver.test/hook2', 'event_types' => ['voucher.created'],
        ], 'sub-update');
        $this->expect('update → 200', $upd['status'], 200);
        $this->expect('… event types replaced', $upd['json']['event_types'] ?? null, ['voucher.created']);

        $this->expect('missing Idempotency-Key on create → 422',
            $this->dispatch('POST', '/api/v1/webhooks', ['url' => 'https://x.test/h', 'event_types' => ['*']], null)['status'], 422);

        // THE SECRET MUST NOT BE PARKED IN THE IDEMPOTENCY TABLE. 16B stores each success response
        // for replay — storing this one verbatim would sit the plaintext secret in
        // api_idempotency_keys for 48h (a table nightly tenant backups capture), defeating the
        // encrypted column entirely, and would re-show it on every replay.
        $idemBodies = DB::table('api_idempotency_keys')->pluck('response_body')->implode(' ');
        $this->expect('the plaintext secret is NOT stored in api_idempotency_keys',
            str_contains($idemBodies, $secret) || str_contains($idemBodies, $newSecret), false);

        $replay = $this->dispatch('POST', '/api/v1/webhooks', [
            'url' => 'https://receiver.test/hook', 'event_types' => ['*'], 'description' => 'All events',
        ], 'sub-create');
        $this->expect('a replay of create does NOT re-show the secret ("once" means once)',
            $replay['json']['secret'] ?? null, null);

        // …while the subscription's own copy IS encrypted at rest (recoverable, because ZeroBook
        // must sign with it — the deliberate inversion of 16A's API-key hashing).
        $stored = DB::table('webhook_subscriptions')->where('id', $id)->value('secret');
        $this->expect('the stored secret is ciphertext, not plaintext', $stored === $newSecret, false);
        $this->expect('… and it decrypts back for signing', WebhookSubscription::find($id)->secret, $newSecret);

        $del = $this->dispatch('DELETE', '/api/v1/webhooks/'.$id);
        $this->expect('delete → 200', $del['status'], 200);
        $this->expect('gone', WebhookSubscription::find($id), null);
    }

    // ── 3. Emission + matching ───────────────────────────────────────────────────────────

    private function emissionMatching(array $c): void
    {
        $this->section('3 · Emission creates one row per MATCHING subscription only');

        WebhookSubscription::query()->delete();
        $wanted = $this->sub(['voucher.created'], []);                     // matches
        $wrongEvent = $this->sub(['party.outstanding.changed'], []);       // wrong event type
        $wrongCompany = $this->sub(['voucher.created'], [$c['other']]);    // unauthorized company
        $paused = $this->sub(['*'], [], false);                            // inactive

        WebhookDelivery::query()->delete();
        $this->postJournal($c, 100);

        $this->expect('the matching subscription got a row', WebhookDelivery::where('webhook_subscription_id', $wanted->id)->count(), 1);
        $this->expect('wrong event type → no row', WebhookDelivery::where('webhook_subscription_id', $wrongEvent->id)->count(), 0);
        $this->expect('unauthorized company → no row', WebhookDelivery::where('webhook_subscription_id', $wrongCompany->id)->count(), 0);
        $this->expect('paused subscription → no row', WebhookDelivery::where('webhook_subscription_id', $paused->id)->count(), 0);

        $row = WebhookDelivery::where('webhook_subscription_id', $wanted->id)->first();
        $this->expect('status pending', $row->status, 'pending');
        $this->expect('payload snapshotted (envelope + voucher data)', isset($row->payload_json['data']['id']), true);
        $this->expect('company stamped on the delivery', $row->company_id, $c['company']);
    }

    // ── 4. POST-COMMIT ONLY (the one that matters most) ──────────────────────────────────

    private function postCommitOnly(array $c): void
    {
        $this->section('4 · POST-COMMIT ONLY — a rolled-back post emits nothing');

        WebhookSubscription::query()->delete();
        $this->sub(['*'], []);

        // A committed post emits.
        WebhookDelivery::query()->delete();
        $this->postJournal($c, 110);
        $this->expect('a committed post emits', WebhookDelivery::count(), 1);

        // A post inside a transaction that ROLLS BACK must emit nothing — and must not even have
        // created a row mid-transaction (DB::afterCommit defers the INSERT past the commit).
        WebhookDelivery::query()->delete();
        DB::beginTransaction();
        $this->postJournal($c, 120);
        $during = WebhookDelivery::count();
        DB::rollBack();
        $this->expect('no row is created INSIDE the transaction', $during, 0);
        $this->expect('a rolled-back post emits nothing', WebhookDelivery::count(), 0);

        // A post that THROWS (invalid payload) never reaches emission.
        WebhookDelivery::query()->delete();
        try {
            (new \App\Livewire\VoucherScreen)->post([
                'type' => 'journal', 'date' => '2026-07-15',
                'lines' => [['ledger_id' => $c['cust'], 'dr_cr' => 'Dr', 'amount' => 100],
                    ['ledger_id' => $c['sales'], 'dr_cr' => 'Cr', 'amount' => 90]],   // unbalanced
            ]);
        } catch (Throwable) {
        }
        $this->expect('an invalid (throwing) post emits nothing', WebhookDelivery::count(), 0);
    }

    // ── 5. Signature correctness ─────────────────────────────────────────────────────────

    private function signature(array $c): void
    {
        $this->section('5 · Signature correctness (the scheme 16D reuses)');

        $secret = WebhookSigner::newSecret();
        $ts = time();
        $body = '{"event":"voucher.created","data":{"id":1}}';
        $sig = WebhookSigner::sign($secret, $ts, $body);

        // Independent computation — not by calling the same helper.
        $independent = 'sha256='.hash_hmac('sha256', $ts.'.'.$body, $secret);
        $this->expect('signature == an independently computed HMAC-SHA256 over "<ts>.<body>"', $sig, $independent);

        $this->expect('verify accepts the genuine signature', WebhookSigner::verify($secret, $ts, $body, $sig), true);
        $this->expect('verify rejects a TAMPERED body', WebhookSigner::verify($secret, $ts, $body.' ', $sig), false);
        $this->expect('verify rejects a WRONG secret', WebhookSigner::verify(WebhookSigner::newSecret(), $ts, $body, $sig), false);
        $this->expect('verify rejects a mangled signature', WebhookSigner::verify($secret, $ts, $body, 'sha256=deadbeef'), false);

        // Replay: a correctly-signed but STALE delivery is refused — the timestamp is inside the
        // MAC, so an attacker cannot re-stamp a captured body.
        $stale = $ts - 4000;
        $this->expect('verify rejects a STALE timestamp (outside the window)',
            WebhookSigner::verify($secret, $stale, $body, WebhookSigner::sign($secret, $stale, $body)), false);
        $this->expect('verify rejects a FUTURE timestamp too',
            WebhookSigner::verify($secret, $ts + 4000, $body, WebhookSigner::sign($secret, $ts + 4000, $body)), false);
        $this->expect('a fresh timestamp inside the window is accepted',
            WebhookSigner::verify($secret, $ts - 60, $body, WebhookSigner::sign($secret, $ts - 60, $body)), true);

        // Re-stamping a captured body with a fresh clock must NOT verify against the old signature.
        $this->expect('re-stamping a captured body invalidates the signature',
            WebhookSigner::verify($secret, $ts + 10, $body, $sig), false);
    }

    // ── 6. Delivery, retry/backoff, exhaustion ───────────────────────────────────────────

    private function deliveryAndRetry(array $c): void
    {
        $this->section('6 · Delivery, retry with backoff, exhaustion');

        WebhookSubscription::query()->delete();
        WebhookDelivery::query()->delete();
        $sub = $this->sub(['*'], []);
        $secret = $sub->fresh()->secret;
        $this->postJournal($c, 130);

        // Success path.
        $this->fakeReceiver(200, 'ok');
        [$a, $s, $f] = app(WebhookDispatcher::class)->dispatchDue();
        $row = WebhookDelivery::first();
        $this->expect('dispatch attempts the due row', $a, 1);
        $this->expect('2xx → succeeded', $row->fresh()->status, 'succeeded');
        $this->expect('succeeded_at stamped', $row->fresh()->succeeded_at !== null, true);
        $this->expect('subscription failure counter reset', $sub->fresh()->consecutive_failures, 0);

        // What the receiver actually got — verified with an INDEPENDENT signature check.
        $sent = Http::recorded()->first();
        $this->expect('the receiver got exactly one request', $sent !== null, true);
        if ($sent) {
            [$req] = $sent;
            $hTs = (int) $req->header(WebhookSigner::TIMESTAMP_HEADER)[0];
            $hSig = $req->header(WebhookSigner::SIGNATURE_HEADER)[0];
            $this->expect('the DELIVERED signature verifies against the stored secret',
                WebhookSigner::verify($secret, $hTs, $req->body(), $hSig), true);
            $this->expect('X-ZeroBook-Event header', $req->header(WebhookSigner::EVENT_HEADER)[0], 'voucher.created');
            $this->expect('X-ZeroBook-Event-Id present', ! empty($req->header(WebhookSigner::EVENT_ID_HEADER)[0]), true);
            $this->expect('signature is prefixed sha256=', str_starts_with($hSig, 'sha256='), true);
        }

        // Failure path: 500 → backoff ladder → exhausted, with a STABLE event id across attempts.
        WebhookDelivery::query()->delete();
        $this->postJournal($c, 140);
        $this->fakeReceiver(500, 'nope');
        $row = WebhookDelivery::first();
        $eventId = $row->event_id;

        $expected = [5, 30, 300, 1800, 10800];
        foreach ($expected as $i => $seconds) {
            $before = now();
            app(WebhookDispatcher::class)->attempt($row->fresh());
            $row = $row->fresh();
            $this->expect('attempt '.($i + 1).' → failed, count='.($i + 1), [$row->status, $row->attempt_count], ['failed', $i + 1]);
            // Carbon 3's diffInSeconds is SIGNED and reads "$a->diffInSeconds($b) === b − a", so the
            // earlier instant must be the receiver or the gap comes back negative.
            $gap = $before->diffInSeconds($row->next_attempt_at);
            $this->expect('… next attempt backs off ~'.$seconds.'s', abs($gap - $seconds) <= 2, true);
            $this->expect('… the event id is STABLE across retries', $row->event_id, $eventId);
            // Make the row due again for the next loop.
            $row->forceFill(['next_attempt_at' => now()->subSecond()])->save();
        }

        // The 6th (final) attempt exhausts it.
        app(WebhookDispatcher::class)->attempt($row->fresh());
        $row = $row->fresh();
        $this->expect('the 6th attempt → exhausted', [$row->status, $row->attempt_count], ['exhausted', 6]);
        $this->expect('an exhausted row has no next attempt', $row->next_attempt_at, null);
        $this->expect('the failing endpoint incremented consecutive_failures', $sub->fresh()->consecutive_failures >= 1, true);
    }

    // ── 7. Auto-disable ──────────────────────────────────────────────────────────────────

    private function autoDisable(array $c): void
    {
        $this->section('7 · Auto-disable a dead endpoint (unbounded growth guard)');

        WebhookSubscription::query()->delete();
        WebhookDelivery::query()->delete();
        $sub = $this->sub(['*'], []);

        // Walk it to the threshold.
        $threshold = (int) config('webhooks.auto_disable_after', 20);
        $sub->forceFill(['consecutive_failures' => $threshold - 1])->save();

        $this->fakeReceiver(500, 'dead');
        $this->postJournal($c, 150);
        $row = WebhookDelivery::first();
        $row->forceFill(['attempt_count' => WebhookDelivery::MAX_ATTEMPTS - 1])->save();
        app(WebhookDispatcher::class)->attempt($row->fresh());   // the exhausting attempt

        $sub = $sub->fresh();
        $this->expect('the subscription is auto-disabled at the threshold', $sub->isDisabled(), true);
        $this->expect('… with a reason', is_string($sub->disabled_reason) && $sub->disabled_reason !== '', true);

        // A disabled subscription must stop accumulating deliveries.
        WebhookDelivery::query()->delete();
        $this->postJournal($c, 160);
        $this->expect('a disabled subscription gets NO new deliveries', WebhookDelivery::count(), 0);
    }

    // ── 8. Concurrency — the single-flight claim ─────────────────────────────────────────

    private function concurrency(array $c): void
    {
        $this->section('8 · Concurrency — two overlapping runs never double-POST');

        WebhookSubscription::query()->delete();
        WebhookDelivery::query()->delete();
        $this->sub(['*'], []);
        $this->postJournal($c, 170);

        $this->fakeReceiver(200, 'ok');

        // Simulate the race deterministically: run A claims the row, then run B sweeps. True OS
        // thread concurrency is not reproducible in one console process, so we assert the ARBITER —
        // the conditional UPDATE — which is what actually decides the winner under real overlap.
        $dispatcher = app(WebhookDispatcher::class);
        [$a1] = $dispatcher->dispatchDue();          // pass A: claims + delivers
        [$a2] = $dispatcher->dispatchDue();          // pass B: nothing left to claim

        $this->expect('pass A delivered the row', $a1, 1);
        $this->expect('pass B found nothing to claim', $a2, 0);
        $this->expect('the receiver was POSTed exactly ONCE', Http::recorded()->count(), 1);

        // And the claim itself: a row already 'delivering' cannot be re-claimed by a second pass.
        //
        // claimed_at is back-dated 30s — claimed, but NOT yet stale — and that detail is doing real
        // work. With claimed_at = now(), a claim stripped of its status guard would still report
        // "not re-claimed", because MySQL counts only rows an UPDATE actually CHANGES and the
        // rewrite would be identical within the same second. The test would then pass for a reason
        // unrelated to the guarantee. Back-dated, a guardless claim genuinely changes the row and
        // wins — so only a real guard can produce this 0. Verified by mutation.
        $row = WebhookDelivery::first();
        $row->forceFill([
            'status' => WebhookDelivery::STATUS_DELIVERING,
            'claimed_at' => now()->subSeconds(30),
            'next_attempt_at' => now()->subSecond(),
        ])->save();
        [$a3] = $dispatcher->dispatchDue();
        $this->expect('a freshly-claimed (delivering) row is not re-claimed', $a3, 0);

        // A STALE claim (the worker died mid-POST) IS reclaimable — the event is not lost forever.
        $row->forceFill(['claimed_at' => now()->subSeconds((int) config('webhooks.claim_ttl', 120) + 60)])->save();
        [$a4] = $dispatcher->dispatchDue();
        $this->expect('a STALE claim is reclaimed (an event is never stranded)', $a4, 1);
    }

    // ── 9. Company + tenant isolation ────────────────────────────────────────────────────

    private function isolation(array $c, Tenant $tenantB): void
    {
        $this->section('9 · Company + tenant isolation');

        WebhookSubscription::query()->delete();
        WebhookDelivery::query()->delete();
        $companyOnly = $this->sub(['*'], [$c['company']]);      // this company only
        $otherOnly = $this->sub(['*'], [$c['other']]);          // the OTHER company only
        $allCompanies = $this->sub(['*'], []);                  // every company

        $this->postJournal($c, 180);   // an event in $c['company']

        $this->expect('the company-scoped subscription got it', WebhookDelivery::where('webhook_subscription_id', $companyOnly->id)->count(), 1);
        $this->expect('the OTHER company-scoped subscription got nothing', WebhookDelivery::where('webhook_subscription_id', $otherOnly->id)->count(), 0);
        $this->expect('the all-companies subscription got it', WebhookDelivery::where('webhook_subscription_id', $allCompanies->id)->count(), 1);

        // Tenant isolation is structural (separate databases) — assert it rather than assume.
        $bCount = $tenantB->run(fn () => \Illuminate\Support\Facades\Schema::hasTable('webhook_deliveries')
            ? WebhookDelivery::count() : -1);
        $this->expect("tenant B has NO deliveries from tenant A's event", $bCount, 0);
        $bSubs = $tenantB->run(fn () => WebhookSubscription::count());
        $this->expect("tenant B cannot see tenant A's subscriptions", $bSubs, 0);
    }

    // ── 10. A webhook failure NEVER breaks a post ────────────────────────────────────────

    private function neverBreaksAPost(array $c): void
    {
        $this->section('10 · A webhook failure never breaks a post');

        WebhookSubscription::query()->delete();
        WebhookDelivery::query()->delete();
        $this->sub(['*'], []);

        // Force the emitter to throw by binding a sabotaged one into the container. If emission
        // were not swallowed, this post would fail — which is exactly the bug we must not have.
        app()->bind(WebhookEmitter::class, fn () => new class extends WebhookEmitter
        {
            public function afterCommit(string $eventType, callable $payloadFactory, ?int $companyId = null): void
            {
                throw new \RuntimeException('sabotaged emitter');
            }

            public function wants(string $eventType, ?int $companyId = null): bool
            {
                throw new \RuntimeException('sabotaged emitter');
            }
        });

        $before = \App\Models\Voucher::count();
        $threw = false;
        try {
            $this->postJournal($c, 190);
        } catch (Throwable) {
            $threw = true;
        }
        $after = \App\Models\Voucher::count();

        $this->expect('the post did NOT throw despite a broken emitter', $threw, false);
        $this->expect('the voucher still committed', $after - $before, 1);
        $this->expect('no delivery row (emission was swallowed + logged)', WebhookDelivery::count(), 0);

        // Restore the real emitter for the remaining sections.
        app()->forgetInstance(WebhookEmitter::class);
        app()->bind(WebhookEmitter::class, fn () => new WebhookEmitter);
    }

    // ── 11. party.outstanding.changed — fires once per party, only on real change ────────

    private function outstandingDedup(array $c): void
    {
        $this->section('11 · party.outstanding.changed — once per party, only on a real change');

        WebhookSubscription::query()->delete();
        WebhookDelivery::query()->delete();
        $this->sub([WebhookEvents::PARTY_OUTSTANDING_CHANGED], []);

        // A bill-wise sale to ONE party, with several allocations on the party leg.
        CompanyFeature::current()->update(['bill_by_bill' => true]);
        Ledger::whereKey($c['cust'])->update(['maintain_bill_by_bill' => true]);

        (new \App\Livewire\VoucherScreen)->post([
            'type' => 'sales', 'date' => '2026-07-15', 'party_ledger_id' => $c['cust'], 'reference_no' => 'OS-1',
            'lines' => [
                ['ledger_id' => $c['cust'], 'dr_cr' => 'Dr', 'amount' => 300, 'allocations' => [
                    ['ref_type' => 'new', 'ref_name' => 'OS-1-A', 'amount' => 100],
                    ['ref_type' => 'new', 'ref_name' => 'OS-1-B', 'amount' => 100],
                    ['ref_type' => 'new', 'ref_name' => 'OS-1-C', 'amount' => 100],
                ]],
                ['ledger_id' => $c['sales'], 'dr_cr' => 'Cr', 'amount' => 300],
            ],
        ]);

        $rows = WebhookDelivery::where('event_type', WebhookEvents::PARTY_OUTSTANDING_CHANGED)->get();
        $this->expect('one voucher with THREE allocations to one party fires ONCE', $rows->count(), 1);

        $payload = $rows->first()?->payload_json['data'] ?? [];
        $this->expect('… names the party', $payload['party_ledger_id'] ?? null, $c['cust']);
        $this->expect('… previous outstanding was 0.00', $payload['previous_outstanding'] ?? null, '0.00');
        $this->expect('… new outstanding is 300.00', $payload['new_outstanding'] ?? null, '300.00');

        // A voucher that touches NO bill-wise party must not fire it. Deliberately posted between
        // two NON-bill-wise ledgers: 'Cust' is bill-wise now, so a journal to it would (rightly) be
        // rejected for missing allocations — which would test the bill-wise gate, not this event.
        WebhookDelivery::query()->delete();
        $cash = Ledger::where('name', 'Cash')->value('id');
        (new \App\Livewire\VoucherScreen)->post([
            'type' => 'journal', 'date' => '2026-07-15',
            'lines' => [
                ['ledger_id' => $cash, 'dr_cr' => 'Dr', 'amount' => 25],
                ['ledger_id' => $c['sales'], 'dr_cr' => 'Cr', 'amount' => 25],
            ],
        ]);
        $this->expect('a voucher with no bill allocations fires no outstanding event',
            WebhookDelivery::where('event_type', WebhookEvents::PARTY_OUTSTANDING_CHANGED)->count(), 0);
    }

    // ── 12. Wiring ───────────────────────────────────────────────────────────────────────

    private function wiring(): void
    {
        $this->section('12 · Wiring');

        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/webhooks'));

        $this->expect('webhook routes are registered', $routes->count() >= 8, true);
        $this->expect('every webhook route declares the webhook:manage scope',
            $routes->every(fn ($r) => ($r->defaults[\App\Http\Middleware\EnforceApiPermissions::SCOPE_DEFAULT] ?? null) === 'webhook:manage'), true);

        $writes = $routes->filter(fn ($r) => array_intersect(['POST', 'PUT'], $r->methods()));
        $this->expect('every webhook WRITE requires an Idempotency-Key',
            $writes->every(fn ($r) => in_array(\App\Http\Middleware\RequiresIdempotencyKey::class, $r->gatherMiddleware(), true)), true);

        $this->expect('the tenant Webhooks screen is routed',
            (bool) app('router')->getRoutes()->getByName('account.webhooks'), true);

        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $commands = collect($schedule->events())->map(fn ($e) => $e->command)->implode(' ');
        $this->expect('webhook-dispatch is scheduled', str_contains($commands, 'webhook-dispatch'), true);
        $this->expect('webhook-prune is scheduled', str_contains($commands, 'webhook-prune'), true);
    }

    // ── 13. Emission is FREE for the tenants who never use it ────────────────────────────

    /**
     * Almost every tenant has zero webhooks, and every one of them pays for this feature on every
     * single post. Uncached that was 4 extra queries per post — two of them information_schema hits
     * from Schema::hasTable. A feature nobody enabled must not tax the accounting hot path, so the
     * emitter reads the live set once per process and this pins it there.
     */
    private function hotPathIsFree(array $c): void
    {
        $this->section('13 · Emission costs a webhook-less tenant nothing per post');

        WebhookSubscription::query()->delete();
        WebhookEmitter::flushCache();

        // §11 turned 'Cust' bill-wise, so post Cash→Sales: a journal to 'Cust' would now be
        // rejected for missing allocations, which has nothing to do with what this section measures.
        $cash = Ledger::where('name', 'Cash')->value('id');
        $post = fn (float $amt) => (new \App\Livewire\VoucherScreen)->post([
            'type' => 'journal', 'date' => '2026-07-15',
            'lines' => [
                ['ledger_id' => $cash, 'dr_cr' => 'Dr', 'amount' => $amt],
                ['ledger_id' => $c['sales'], 'dr_cr' => 'Cr', 'amount' => $amt],
            ],
        ]);

        $count = 0;
        DB::listen(function ($q) use (&$count) {
            if (stripos($q->sql, 'webhook') !== false || stripos($q->sql, 'information_schema') !== false) {
                $count++;
            }
        });

        $post(231);   // warm: the first post pays the one-time read
        $count = 0;
        $post(232);   // measured: steady state

        $this->expect('a post by a tenant with NO webhooks costs 0 webhook/schema queries', $count, 0);

        // …and the memo must never go stale. A subscription created RIGHT NOW, in this same
        // process, has to be seen by the very next post — otherwise the cache above would have
        // bought speed by silently dropping events, which is far worse than the cost it saved.
        $this->sub(['*'], []);
        WebhookDelivery::query()->delete();
        $post(233);

        $this->expect('a subscription created mid-process is seen by the next post (memo is not stale)',
            WebhookDelivery::count() >= 1, true);

        // FAN-OUT IS PER-RECEIVER. One unusable subscription must never cost the others their
        // events. Found by attacking the cache: the tenant UI deleted through the query builder,
        // which fires no model events, so a stale row survived in the memo — its INSERT failed the
        // foreign key, aborted the fan-out loop, and deleting ONE webhook silently stopped every
        // OTHER webhook in the tenant. Both the cause (UI now deletes through the model) and the
        // blast radius (per-row guard in the emitter) are fixed; this pins the blast radius,
        // because that is the part a future caller could otherwise reintroduce.
        WebhookSubscription::query()->delete();
        WebhookDelivery::query()->delete();
        WebhookEmitter::flushCache();
        $doomed = $this->sub(['*'], []);
        $healthyA = $this->sub(['*'], []);
        $healthyB = $this->sub(['*'], []);
        $post(234);                                              // memo now holds all three
        DB::table('webhook_subscriptions')->where('id', $doomed->id)->delete();   // no model event
        WebhookDelivery::query()->delete();
        $post(235);

        $this->expect('an unusable subscription does not cost the OTHERS their event',
            WebhookDelivery::whereIn('webhook_subscription_id', [$healthyA->id, $healthyB->id])->count(), 2);

        WebhookSubscription::query()->delete();
        WebhookDelivery::query()->delete();
    }

    // ── 14. A webhook can never out-reach the API key that created it ────────────────────

    /**
     * The company boundary, for a STANDING grant rather than a single request.
     *
     * 16A restricts an API key to a set of companies and enforces it per request against
     * X-Company-Id. A webhook subscription is different in kind: it keeps receiving data long after
     * the request that created it, and the dispatcher POSTs with no key in sight. So a key
     * restricted to company A that could register an all-companies webhook would hold a durable
     * read of company B — books it cannot fetch over REST. Every assertion here is that escalation,
     * closed from a different direction.
     */
    private function keyCannotOutreach(array $c, Tenant $tenantA): void
    {
        $this->section('14 · A webhook can never out-reach the API key that created it');

        WebhookSubscription::query()->delete();

        // A key restricted to ONE company — the 'selected' mode the API Keys UI issues.
        $restricted = app(ApiKeyService::class)->generate(
            tenant: $tenantA, user: null, name: 'OnlyA', permissions: ['*'], companyIds: [$c['company']],
        )['key'];

        // THE ESCALATION: ask for every company. An omitted list means "all", so the default must
        // come from the KEY, not from the field being absent.
        $all = $this->dispatch('POST', '/api/v1/webhooks', [
            'url' => 'https://receiver.test/hook', 'event_types' => ['*'],
        ], 'esc-1', $restricted);

        $this->expect('a company-restricted key creating a webhook does NOT get all companies',
            $all['json']['authorized_company_ids'] ?? null, [$c['company']]);

        // …and asking for the other company OUTRIGHT is refused, not silently narrowed.
        $other = $this->dispatch('POST', '/api/v1/webhooks', [
            'url' => 'https://receiver.test/hook', 'event_types' => ['*'],
            'authorized_company_ids' => [$c['other']],
        ], 'esc-2', $restricted);

        $this->expect('… and naming a company the key cannot read is 403', $other['status'], 403);

        $mixed = $this->dispatch('POST', '/api/v1/webhooks', [
            'url' => 'https://receiver.test/hook', 'event_types' => ['*'],
            'authorized_company_ids' => [$c['company'], $c['other']],
        ], 'esc-2b', $restricted);

        $this->expect('… and a MIXED list (own + forbidden) is refused, not silently narrowed',
            $mixed['status'], 403);

        $explicitAll = $this->dispatch('POST', '/api/v1/webhooks', [
            'url' => 'https://receiver.test/hook', 'event_types' => ['*'],
            'authorized_company_ids' => [],
        ], 'esc-3', $restricted);

        $this->expect('… and asking for [] ("all companies") explicitly is 403', $explicitAll['status'], 403);

        // THE UPDATE PATH MUST ENFORCE THE SAME RULE AS CREATE — and this is not a theoretical
        // worry. Verified by mutation: making ONLY update() skip the subset check left this whole
        // command green while a restricted key silently widened its own subscription to every
        // company. Create-side assertions cannot cover an update-side regression.
        $ownId = $all['json']['id'] ?? null;

        $widen = $this->dispatch('PUT', '/api/v1/webhooks/'.$ownId, [
            'url' => 'https://receiver.test/hook', 'event_types' => ['*'],
            'authorized_company_ids' => [$c['other']],
        ], 'esc-u1', $restricted);
        $this->expect('widening an OWN subscription to a forbidden company is 403', $widen['status'], 403);

        $widenAll = $this->dispatch('PUT', '/api/v1/webhooks/'.$ownId, [
            'url' => 'https://receiver.test/hook', 'event_types' => ['*'],
            'authorized_company_ids' => [],
        ], 'esc-u2', $restricted);
        $this->expect('widening an OWN subscription to [] (all companies) is 403', $widenAll['status'], 403);

        // A refused widening must not have partially applied — check the STORED scope, not just
        // the status code. A 403 with the row already updated would be the worst of both.
        $this->expect('… and the stored scope is unchanged after the refused widenings',
            WebhookSubscription::find($ownId)?->authorizedCompanyIds(), [$c['company']]);

        // …while an update that OMITS the field still works and does not widen. The guard must
        // refuse escalation, not break ordinary edits.
        $plainEdit = $this->dispatch('PUT', '/api/v1/webhooks/'.$ownId, [
            'url' => 'https://receiver.test/hook2', 'event_types' => ['*'],
        ], 'esc-u3', $restricted);
        $this->expect('an update that omits the field still succeeds', $plainEdit['status'], 200);
        $this->expect('… and omission does not widen the scope',
            WebhookSubscription::find($ownId)?->authorizedCompanyIds(), [$c['company']]);

        // THE SECOND DIRECTION: a subscription that already spans companies the key cannot read
        // must be invisible to it — this table has no company_id and the model has no company
        // scope, so nothing else would have stopped route-model binding from handing it over.
        $foreign = $this->sub(['*'], [$c['other']]);
        $wide = $this->sub(['*'], []);

        $this->expect('a foreign company\'s subscription 404s for a restricted key',
            $this->dispatch('GET', '/api/v1/webhooks/'.$foreign->id, null, null, $restricted)['status'], 404);
        $this->expect('an ALL-companies subscription 404s for a restricted key (it is wider than the key)',
            $this->dispatch('GET', '/api/v1/webhooks/'.$wide->id, null, null, $restricted)['status'], 404);
        $this->expect('… its delivery log — other companies\' voucher payloads — is not readable either',
            $this->dispatch('GET', '/api/v1/webhooks/'.$foreign->id.'/deliveries', null, null, $restricted)['status'], 404);
        $this->expect('… and it cannot be repointed at an attacker URL',
            $this->dispatch('PUT', '/api/v1/webhooks/'.$foreign->id, ['url' => 'https://evil.test/x', 'event_types' => ['*']], 'esc-4', $restricted)['status'], 404);
        $this->expect('… nor its secret rotated out from under its owner',
            $this->dispatch('POST', '/api/v1/webhooks/'.$foreign->id.'/rotate-secret', [], 'esc-5', $restricted)['status'], 404);
        $this->expect('… nor deleted',
            $this->dispatch('DELETE', '/api/v1/webhooks/'.$foreign->id, null, null, $restricted)['status'], 404);
        $this->expect('… nor used to fire a test event at its owner\'s endpoint',
            $this->dispatch('POST', '/api/v1/webhooks/'.$foreign->id.'/test', [], 'esc-6', $restricted)['status'], 404);

        $listed = collect($this->dispatch('GET', '/api/v1/webhooks', null, null, $restricted)['json']['data'] ?? [])
            ->pluck('id')->all();
        $this->expect('the list shows neither of them', array_intersect($listed, [$foreign->id, $wide->id]), []);

        // THE BINDING MUST SURVIVE `route:cache`, WHICH IS WHAT PRODUCTION ACTUALLY RUNS.
        //
        // deploy.sh caches routes on every deploy, and a cached route table means the route FILES
        // are never evaluated. A Route::bind declared in routes/api.php therefore vanishes in
        // production while passing every assertion above locally — implicit binding takes over and
        // hands a restricted key another company's subscription. That is not hypothetical: it is
        // what happened, and it is why the binding lives in AppServiceProvider::boot(). Assert
        // against a REAL cached route table so the two environments can never diverge again.
        $this->expect('{webhook} is not left to implicit binding',
            (bool) app('router')->getBindingCallback('webhook'), true);

        $this->call('route:cache');
        try {
            $this->expect('… and the scope still holds with routes CACHED (what production runs)',
                $this->dispatch('GET', '/api/v1/webhooks/'.$foreign->id, null, null, $restricted)['status'], 404);
        } finally {
            $this->call('route:clear');
        }

        // The unrestricted key still sees everything — the scope must not break the normal case.
        $listedAll = collect($this->dispatch('GET', '/api/v1/webhooks', null, null)['json']['data'] ?? [])
            ->pluck('id')->all();
        $this->expect('an all-companies key still sees every subscription',
            count(array_intersect($listedAll, [$foreign->id, $wide->id])), 2);

        WebhookSubscription::query()->delete();
    }

    // ── 15. A 2xx is final; a dead subscription's backlog is not delivered ───────────────

    private function ackIsFinal(array $c): void
    {
        $this->section('15 · An accepted event is never re-sent; a dead endpoint gets no backlog');

        WebhookSubscription::query()->delete();
        WebhookDelivery::query()->delete();
        $sub = $this->sub(['*'], []);
        $this->postCashJournal($c, 240);

        // A receiver that ACKs with a body that is NOT valid UTF-8 — a latin-1 error page is the
        // everyday case. Those bytes reach a utf8mb4 column under STRICT_TRANS_TABLES, so an
        // unscrubbed excerpt makes the SUCCESS write throw; when the try still spanned succeed(),
        // that landed in the transport catch and re-POSTed an event the receiver had accepted.
        $this->fakeReceiver(200, "Ungl\xFCltige Anfrage");
        [$attempted, $succeeded, $failed] = app(WebhookDispatcher::class)->dispatchDue();

        $this->expect('a 2xx with a non-UTF-8 body is recorded as SUCCESS, not a failure', $succeeded, 1);
        $row = WebhookDelivery::first();
        $this->expect('… the row is terminal', $row->status, WebhookDelivery::STATUS_SUCCEEDED);
        $this->expect('… it is not scheduled for another attempt', $row->next_attempt_at, null);
        $this->expect('… and the stored excerpt is valid UTF-8', mb_check_encoding((string) $row->last_response_body_excerpt, 'UTF-8'), true);

        $sent = count(Http::recorded());
        app(WebhookDispatcher::class)->dispatchDue();
        $this->expect('a second tick does NOT re-send the accepted event', count(Http::recorded()), $sent);

        // A DISABLED subscription must not have its BACKLOG delivered. Auto-disable exists to stop
        // hammering a dead endpoint; delivering everything already queued would defeat it entirely.
        WebhookDelivery::query()->delete();
        $this->postCashJournal($c, 241);
        $this->expect('an event is queued while the subscription is live', WebhookDelivery::count(), 1);

        $sub->forceFill(['disabled_at' => now(), 'disabled_reason' => 'test'])->save();
        $this->fakeReceiver(200);
        app(WebhookDispatcher::class)->dispatchDue();

        $this->expect('the backlog of a DISABLED subscription is never POSTed', count(Http::recorded()), 0);
        $this->expect('… and its rows are parked in a terminal state, not left to accumulate',
            WebhookDelivery::first()->status, WebhookDelivery::STATUS_EXHAUSTED);

        // A TICK MUST BOUND ITS OWN RUNTIME. Row count does not: a full batch against hanging
        // endpoints is 50 × timeout = 500s, past the scheduler's 5-minute overlap lock, so ticks
        // would pile up exactly when endpoints are slow. Unreached rows must stay PENDING — the
        // budget may defer work, never drop it.
        WebhookSubscription::query()->delete();
        WebhookDelivery::query()->delete();
        $budgetSub = $this->sub(['*'], []);
        foreach (range(1, 3) as $i) {
            $this->postCashJournal($c, 250 + $i);
        }
        $queued = WebhookDelivery::count();
        $this->expect('three events are queued', $queued >= 3, true);

        $this->fakeReceiver(200);
        config(['webhooks.max_seconds' => 0]);       // budget already spent before the first row
        [$attempted] = app(WebhookDispatcher::class)->dispatchDue();
        config(['webhooks.max_seconds' => 240]);

        $this->expect('an exhausted time budget stops the tick before any row', $attempted, 0);
        $this->expect('… and nothing was POSTed', count(Http::recorded()), 0);
        $this->expect('… while every row stays PENDING for the next tick (deferred, not dropped)',
            WebhookDelivery::where('status', WebhookDelivery::STATUS_PENDING)->count(), $queued);

        // With a normal budget the same rows go out — the guard must not be a permanent stall.
        app(WebhookDispatcher::class)->dispatchDue();
        $this->expect('… and the next tick delivers them all', count(Http::recorded()), $queued);

        // THE TEST EVENT IS THE ONE A CUSTOMER BUILDS THEIR RECEIVER AGAINST, so the id in its
        // header and the id in its body must be the same id — we tell them to dedupe on event_id.
        WebhookSubscription::query()->delete();
        WebhookDelivery::query()->delete();
        $ping = $this->sub(['*'], []);
        app(WebhookDispatcher::class)->sendTest($ping);
        $row = WebhookDelivery::where('event_type', WebhookEvents::PING)->first();

        $this->expect('the ping row and its payload carry the SAME event_id',
            $row->payload_json['event_id'] ?? null, $row->event_id);

        $this->fakeReceiver(200);
        app(WebhookDispatcher::class)->dispatchDue();
        $sentHeaders = Http::recorded()[0][0]->headers();
        $this->expect('… and the X-ZeroBook-Event-Id header agrees with the body',
            $sentHeaders['X-ZeroBook-Event-Id'][0] ?? null, $row->payload_json['event_id']);

        // Every row the emitter writes satisfies authorizesCompany(company_id); a test event must
        // not be the one row in the table that violates it.
        WebhookDelivery::query()->delete();
        $narrow = $this->sub(['*'], [$c['other']]);   // authorized for the OTHER company only
        app(WebhookDispatcher::class)->sendTest($narrow);
        $this->expect('a test event is never stamped with a company its subscription cannot hear about',
            WebhookDelivery::where('webhook_subscription_id', $narrow->id)->first()?->company_id, null);

        WebhookSubscription::query()->delete();
        WebhookDelivery::query()->delete();
    }

    // ── 16. What the INDEPENDENT review found ────────────────────────────────────────────

    /**
     * Four defects an independent adversarial pass found that this command's own author did not.
     * Each is reproduced end-to-end in _docs/attacks/attack4_independent_findings.php; these pin
     * them. They are grouped because they share one root shape: a check that was correct about the
     * present and wrong about a change over time.
     */
    private function independentFindings(array $c, Tenant $tenantA): void
    {
        $this->section('16 · Findings from the independent review');

        WebhookSubscription::query()->delete();
        WebhookDelivery::query()->delete();
        WebhookEmitter::flushCache();

        $restricted = app(ApiKeyService::class)->generate(
            tenant: $tenantA, user: null, name: 'Narrow', permissions: ['*'], companyIds: [$c['company']],
        )['key'];

        // (1) TIGHTENING A SCOPE MUST NOT RETROACTIVELY WIDEN A READ. The route binding authorizes
        // against a subscription's CURRENT company set, but the delivery log is HISTORICAL — each
        // row carries the company it was emitted for. Narrowing an all-companies subscription to
        // [own] therefore handed its whole back-catalogue of the OTHER company's rows to a
        // restricted key. The log is now filtered by company in its own right.
        $wide = $this->sub(['*'], []);
        foreach ([$c['company'], $c['other']] as $cid) {
            WebhookDelivery::create([
                'webhook_subscription_id' => $wide->id, 'event_id' => (string) \Illuminate\Support\Str::ulid(),
                'event_type' => 'voucher.created', 'company_id' => $cid,
                'payload_json' => ['event' => 'voucher.created', 'company_id' => $cid],
                'attempt_count' => 0, 'status' => WebhookDelivery::STATUS_PENDING,
                'next_attempt_at' => now(), 'created_at' => now(),
            ]);
        }
        $wide->forceFill(['authorized_company_ids_json' => [$c['company']]])->save();   // the narrowing

        $log = $this->dispatch('GET', '/api/v1/webhooks/'.$wide->id.'/deliveries', null, null, $restricted);
        $leaked = collect($log['json']['data'] ?? [])->pluck('company_id')
            ->reject(fn ($id) => $id === $c['company'])->values()->all();
        $this->expect('narrowing a subscription does NOT expose its wider delivery history', $leaked, []);

        // …and the SEND side of the same rule, which is the half with the bigger blast radius: the
        // rows above are still QUEUED. Filtering the log without re-checking at delivery time would
        // hide the removed company's payload from the API while still POSTing the full voucher body
        // to the endpoint. Restricting a webhook has to bind the backlog, not just the future.
        $this->fakeReceiver(200);
        app(WebhookDispatcher::class)->dispatchDue();
        $sentCompanies = collect(Http::recorded())->map(function ($pair) {
            $body = json_decode((string) $pair[0]->body(), true);

            return $body['company_id'] ?? null;
        })->filter()->unique()->values()->all();

        $this->expect('… the still-authorized company is delivered',
            in_array($c['company'], $sentCompanies, true), true);
        $this->expect('… and the REMOVED company\'s already-queued payload is never sent',
            in_array($c['other'], $sentCompanies, true), false);

        // (2) AN OMITTED FIELD MEANS "LEAVE IT ALONE". is_active used to default to true on every
        // write, so a routine "reconcile my config" PUT silently un-paused a subscription AND
        // cleared an auto-disable — permanently defeating the dead-endpoint protection, since every
        // reconcile revived it with a fresh failure budget.
        $wide->forceFill(['is_active' => false, 'disabled_at' => now(), 'disabled_reason' => 'auto',
            'consecutive_failures' => 20])->save();
        $this->dispatch('PUT', '/api/v1/webhooks/'.$wide->id,
            ['url' => 'https://receiver.test/hook', 'event_types' => ['*'], 'description' => 'renamed'], 'ind-1');
        $reloaded = WebhookSubscription::find($wide->id);

        $this->expect('a PUT omitting is_active leaves a paused subscription paused', (bool) $reloaded->is_active, false);
        $this->expect('… does not clear the auto-disable', $reloaded->disabled_at !== null, true);
        $this->expect('… and does not reset the failure budget', (int) $reloaded->consecutive_failures, 20);

        // (3) CLAIM MUST RE-STATE EVERY CONDITION THAT MADE THE ROW ELIGIBLE. claim() checked
        // status but not next_attempt_at, so under overlap one tick could fail a row (scheduling it
        // 5s out) and a second tick immediately re-claim and re-POST it INSIDE its backoff window.
        WebhookSubscription::query()->delete();
        WebhookDelivery::query()->delete();
        WebhookEmitter::flushCache();
        $s = $this->sub(['*'], []);
        $this->postCashJournal($c, 260);
        $this->fakeReceiver(500);
        app(WebhookDispatcher::class)->dispatchDue();          // one failure → backoff into the future
        $row = WebhookDelivery::first();
        $this->expect('the failed row is scheduled into the future', $row->next_attempt_at->isFuture(), true);

        $d = app(WebhookDispatcher::class);
        $claim = new \ReflectionMethod($d, 'claim');
        $claim->setAccessible(true);
        $this->expect('a not-yet-due row is NOT re-claimable (no re-POST inside backoff)',
            $claim->invoke($d, $row->fresh()), false);

        // (4) PAUSED AND DISABLED ARE DIFFERENT ANSWERS. Parking both as terminal meant pausing a
        // subscription for even a moment permanently destroyed everything already queued. A pause
        // is reversible and must only DEFER; only a dead (auto-disabled) endpoint is terminal.
        WebhookDelivery::query()->delete();
        $this->postCashJournal($c, 261);
        $queued = WebhookDelivery::count();
        $s->forceFill(['is_active' => false])->save();          // paused mid-flight
        $this->fakeReceiver(200);
        app(WebhookDispatcher::class)->dispatchDue();

        $this->expect('a paused subscription is not POSTed to', count(Http::recorded()), 0);
        $this->expect('… and its backlog is DEFERRED, not destroyed',
            WebhookDelivery::where('status', WebhookDelivery::STATUS_PENDING)->count(), $queued);

        $s->forceFill(['is_active' => true])->save();            // resumed
        app(WebhookDispatcher::class)->dispatchDue();
        $this->expect('… and resuming delivers it promptly', count(Http::recorded()), $queued);

        // (5) REDELIVER ON A ROW THAT HAS NOT FINISHED IS A NO-OP, not a duplicate. The button sits
        // in the delivery log beside rows that are merely slow; minting a second live copy of the
        // same event_id made ZeroBook itself the source of the duplicate the receiver then has to
        // dedupe. A finished row still redelivers normally (§3 covers that).
        WebhookSubscription::query()->delete();
        WebhookDelivery::query()->delete();
        WebhookEmitter::flushCache();
        $this->sub(['*'], []);
        $this->postCashJournal($c, 262);
        $queuedRow = WebhookDelivery::first();

        app(WebhookDispatcher::class)->redeliver($queuedRow);
        $this->expect('redelivering an un-sent row creates no second live copy',
            WebhookDelivery::where('event_id', $queuedRow->event_id)
                ->whereIn('status', [WebhookDelivery::STATUS_PENDING, WebhookDelivery::STATUS_DELIVERING])->count(), 1);

        $this->fakeReceiver(200);
        app(WebhookDispatcher::class)->dispatchDue();
        $this->expect('… so the receiver is POSTed exactly once for that event', count(Http::recorded()), 1);

        WebhookSubscription::query()->delete();
        WebhookDelivery::query()->delete();
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────

    /** A fresh company with two ledgers, plus a SECOND company id for the isolation tests. */
    private function seed(): array
    {
        $company = app(CompanyProvisioner::class)->create('Hooks Co');
        $other = app(CompanyProvisioner::class)->create('Other Co');

        return ActiveCompany::runAs($company->id, function () use ($company, $other) {
            $g = fn (string $n) => AccountGroup::where('name', $n)->value('id');

            return [
                'company' => $company->id,
                'other' => $other->id,
                'sales' => Ledger::create(['name' => 'Sales', 'group_id' => $g('Sales Accounts')])->id,
                'cust' => Ledger::create(['name' => 'Cust', 'group_id' => $g('Sundry Debtors')])->id,
            ];
        });
    }

    /**
     * Point the fake receiver at a fresh response.
     *
     * Http::fake() MERGES stub callbacks rather than replacing them, so calling it twice leaves the
     * FIRST stub matching forever — a 500 registered after a 200 would silently never fire. Swap in
     * a clean factory each time so each phase gets exactly the receiver it asked for.
     */
    private function fakeReceiver(int $status, string $body = 'ok'): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['receiver.test/*' => Http::response($body, $status)]);
    }

    private function sub(array $events, array $companyIds, bool $active = true): WebhookSubscription
    {
        return WebhookSubscription::create([
            'url' => 'https://receiver.test/hook',
            'event_types_json' => $events,
            'authorized_company_ids_json' => $companyIds,
            'secret' => WebhookSigner::newSecret(),
            'is_active' => $active,
        ]);
    }

    /**
     * A post that does NOT touch a bill-wise party. §11 turns 'Cust' bill-wise, so postJournal()
     * would be rejected for missing allocations in any section after it — a real rule, but not the
     * one those sections are testing.
     */
    private function postCashJournal(array $c, float $amount): void
    {
        $cash = Ledger::where('name', 'Cash')->value('id');

        ActiveCompany::runAs($c['company'], fn () => (new \App\Livewire\VoucherScreen)->post([
            'type' => 'journal', 'date' => '2026-07-15',
            'lines' => [
                ['ledger_id' => $cash, 'dr_cr' => 'Dr', 'amount' => $amount],
                ['ledger_id' => $c['sales'], 'dr_cr' => 'Cr', 'amount' => $amount],
            ],
        ]));
    }

    private function postJournal(array $c, float $amount): void
    {
        ActiveCompany::runAs($c['company'], fn () => (new \App\Livewire\VoucherScreen)->post([
            'type' => 'journal', 'date' => '2026-07-15',
            'lines' => [
                ['ledger_id' => $c['cust'], 'dr_cr' => 'Dr', 'amount' => $amount],
                ['ledger_id' => $c['sales'], 'dr_cr' => 'Cr', 'amount' => $amount],
            ],
        ]));
    }

    /** The real HTTP kernel — the same harness prove-api-core uses for the inbound surface. */
    private function dispatch(string $method, string $uri, ?array $body = null, ?string $idem = 'k', ?string $key = null): array
    {
        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer '.($key ?? $this->key),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
        if ($idem !== null) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idem.'-'.$uri.'-'.$method;
        }

        // IdentifyTenantByApiKey CLOBBERS process state: it calls ActiveCompany::set(null) and
        // re-pins to the API KEY's company on every request. That is correct for a real request,
        // but this console proof pins its own seeded company — and ActiveCompany is a static, so a
        // dispatch would silently repoint every later company-scoped query (and ActiveCompany::runAs
        // only restores on exit, so it cannot protect against a mid-body change). Save and restore.
        $pinned = ActiveCompany::id();

        $request = Request::create($uri, $method, [], [], [], $server, $body === null ? null : json_encode($body));
        $kernel = app(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        ActiveCompany::set($pinned);

        return ['status' => $response->getStatusCode(), 'json' => json_decode($response->getContent(), true)];
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->line("── {$title} ".str_repeat('─', max(1, 62 - mb_strlen($title))));
    }

    private function expect(string $label, mixed $actual, mixed $expected): void
    {
        $pass = $actual === $expected;
        if (! $pass) {
            $this->ok = false;
        }
        $this->line(sprintf('   [%s] %s = %s%s', $pass ? 'PASS' : 'FAIL', $label,
            $this->short($actual), $pass ? '' : ' (expected '.$this->short($expected).')'));
    }

    private function short(mixed $v): string
    {
        $s = json_encode($v);

        return strlen($s) > 160 ? substr($s, 0, 160).'…' : $s;
    }
}
