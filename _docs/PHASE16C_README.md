# Phase 16C — Outbound webhooks (HMAC-signed, exponential backoff)

16B let a customer's website *ask* ZeroBook. 16C lets ZeroBook *tell* the customer's website: a
voucher posts, and their HMS, transport portal, or storefront hears about it within the minute —
signed, retried, and logged.

The correctness spine of 16B was "one posting path". The spine of 16C is narrower and harsher:

> **A webhook can never break a post.** A voucher that committed is correct and finished. Anything
> that happens afterwards on behalf of an integration is our problem, never the poster's.

That guarantee is structural here, not a matter of care — see *Post-commit only* below.

---

## Step 0 — audit result

**Before 16C: 33/33 prior `prove-*` commands pass. After 16C: 34/34 green**, with
`zerobook:prove-webhooks` adding **145 assertions across 16 sections**. The inbound management API
is driven through the real HTTP kernel (the `prove-api-core` harness); outbound delivery runs the
real dispatcher against a faked receiver.

16C touches the accounting engine in **exactly two places**, both in `VoucherScreen`, both
additive, and both incapable of failing into the caller:

| File | Edit |
|---|---|
| `VoucherScreen::post()` | capture bill state before the write; hand the committed voucher to `WebhookVoucherEvents::afterWrite()` |
| `VoucherScreen::cancelVoucher()` | snapshot before the delete; `afterCancel()` after it |

Everything else is new: two tenant tables, six services, two commands, eight routes, one Livewire
screen, one notification.

---

## Post-commit only — why `DB::afterCommit` is required, not merely tidy

Emission registers through `DB::afterCommit`. I verified its three behaviours empirically before
relying on them (zero prior uses existed in the codebase):

| Situation | Behaviour |
|---|---|
| No open transaction | fires immediately |
| Inside a transaction | fires after commit |
| Transaction rolls back | **never fires** |

That single primitive buys four properties at once. A rolled-back post emits nothing. The Tally
importer's dry-run — which posts every voucher and rolls the lot back — emits nothing. A
duplicate-voucher-number retry inside `persistNew`'s loop emits once (the discarded attempt's
callback is discarded with it), not once per attempt.

And the fourth is the one that matters: **the delivery-row INSERT lands outside the accounting
transaction.** If emission ran inside it, a failed INSERT would roll back a *committed voucher*.
The catch-all in `WebhookEmitter::emit()` is the second line of defence; `afterCommit` is what
makes the guarantee structural rather than merely careful.

Suppressed deliberately: `BulkMode` (the importer's 10k-voucher runs — one webhook per imported
voucher is a stampede, not an integration) and scenario/what-if vouchers (they are not real books).

---

## The secret is encrypted, not hashed — the deliberate inversion of 16A

16A's rule was absolute: *hash API keys on write, never store the raw key.* 16C does the opposite,
and the difference is not inconsistency — it is what each secret is **for**:

|  | 16A API key | 16C webhook secret |
|---|---|---|
| Who presents it | the customer, on every request | nobody |
| What we do with it | **compare** a candidate | **compute** an HMAC |
| Therefore | `Hash::make` (one-way) | `Crypt` (recoverable) |
| Stored as | bcrypt hash | ciphertext (`encrypted` cast, APP_KEY) |

You cannot sign with a hash. ZeroBook must reproduce the secret to sign every delivery, so it must
be recoverable. What we can still deny an attacker is the *at-rest* copy: a database dump without
`APP_KEY` yields nothing. The model marks it `$hidden`, so no resource, list, Livewire snapshot, or
log line can carry it out by accident. It is shown exactly **twice** in the product's lifetime —
the create response and the rotate response — and never again.

### The leak this uncovered (found and fixed during self-review)

16B stores every successful write's response body for 48h so a retry can replay it. That is
correct for a voucher and *actively harmful* for a secret: it parked the plaintext in
`api_idempotency_keys` — a table 14C's nightly backups capture — defeating the encrypted column
entirely, and re-showed the secret on every replay, making "shown once" a slogan.

Idempotency and show-once are in genuine conflict here, and **show-once wins**:
`IdempotentOperation::$storeBody` lets an operation persist a *different* body than it returns. A
replay is still idempotent where it counts — no second webhook, same resource — it simply cannot
resurrect a value the customer was told to save. Proven both ways: the plaintext is absent from
`api_idempotency_keys`, and a replay returns `secret: null` with an explanation.

---

## The signature scheme (16D reuses this verbatim)

```
signed_payload = "<timestamp>.<raw_request_body>"
signature      = "sha256=" + hmac_sha256(secret, signed_payload)
```

| Header | Meaning |
|---|---|
| `X-ZeroBook-Signature` | `sha256=<hex>` |
| `X-ZeroBook-Timestamp` | unix seconds — **inside the signature**, so it cannot be edited |
| `X-ZeroBook-Event` / `X-ZeroBook-Event-Id` | type, and the id that is **stable across retries** |
| `X-ZeroBook-Delivery-Id` | unique per *attempt* — the id to quote in a support ticket |

The timestamp is signed, which is what makes replay defence possible: `WebhookSigner::verify()`
enforces a symmetric ±300s window and compares with `hash_equals` (constant-time). Receivers must
sign the **raw body bytes**, never a re-encoded copy — documented with worked PHP and
language-neutral pseudocode at `/docs/api`.

`WebhookSigner` is deliberately free of any outbound assumption. 16D verifies *inbound* events with
the same `sign`/`verify`/`withinTolerance`.

---

## Delivery, retry, auto-disable

Retry ladder — `[5s, 30s, 5m, 30m, 3h]`, 6 attempts, then `exhausted`. Any 2xx succeeds; everything
else retries. The contract is **at-least-once**: receivers must dedupe on `event_id`, which is
stable across every retry of one event.

**Concurrency** is a conditional UPDATE, not a lock:

```sql
UPDATE webhook_deliveries SET status='delivering', claimed_at=NOW()
 WHERE id = ? AND (status IN ('pending','failed') OR (status='delivering' AND claimed_at < ?))
```

Only the pass that changes exactly 1 row owns the row, so two overlapping workers cannot
double-POST. The `claimed_at` staleness window means a worker that dies mid-flight releases its row
instead of stranding it forever.

**Auto-disable** after 20 consecutive failures: the subscription is disabled, the delivery stops,
and the tenant's owners get a notification naming the endpoint and how to re-enable it. Re-enabling
by hand resets the failure budget — the customer is asserting the endpoint is healthy again.

---

## What the adversarial review found

48 agents: attackers proposing defects, then independent refuters trying to kill each finding on
code-reality, correctness, and exploitability. Findings that survived refutation are below. Each is
fixed AND pinned by an assertion — the fix, and the proof that it stays fixed.

### 1. A restricted key could mint an unrestricted webhook *(high — company escalation)*

An API key limited to company 3 could `POST /v1/webhooks` with no `authorized_company_ids` — which
defaulted to `[]`, meaning **every company** — and then receive full voucher payloads for company 1.
The same key is `403`'d from reading company 1 over REST. The webhook was a durable read of books
the key cannot touch, and the dispatcher POSTs later with no key in sight to notice.

The reviewer's sharpest point was about the *proof*, not the code: `prove-webhooks` issued exactly
one key, `companyIds: []`, so the battery **could not have caught this** — while `prove-api-core`
does test the same list as a hard ACL on the 16B surface. The boundary was enforced everywhere it
was tested and nowhere it wasn't.

Fixed in both directions:
- **Creation** — an omitted list now defaults to *the key's own companies*, and naming a company
  the key cannot read (or asking for `[]`) is `403 company_not_authorized`. New primitive:
  `ApiKey::authorizesCompanySet()` — a resource's company set must be a **subset** of the key's.
- **Access** — `{webhook}` resolves through `Route::bind` with `visibleToApiKey()` applied, rather
  than implicit binding on an unscoped model. Bound once rather than checked in six actions, so the
  next `{webhook}` route added is scoped by construction. Out-of-reach subscriptions `404` (a
  restricted key has no business learning they exist).

#### 1b. …and the fix for it was dead in production *(caught by testing the fix, not the code)*

The binding started life in `routes/api.php`, where it passed every assertion above. **`deploy.sh`
runs `route:cache` on every deploy, and a cached route table means the route files are never
evaluated** — so the binding would not have existed on zerobook.in. Implicit binding takes over and
the escalation is fully live: I cached routes locally and watched all five guards flip from 404 to
200, including rotating another company's secret and reading their delivery log.

Proven locally, dead where it matters, invisible to any test that doesn't cache routes first — the
worst shape a bug can have. The binding now lives in `AppServiceProvider::boot()`, which runs either
way, and §14 asserts the scope **against a real cached route table** so the two environments cannot
diverge again.

**No backfill.** Tightening an authorization rule usually raises a "what about existing rows?"
question. Not here: `webhook_subscriptions` ships *with* this phase and has never been deployed, so
there are no subscriptions anywhere that predate the rule. It applies from the first row on.

### 2. An accepted event could be re-sent *(high — at-most-once violation)*

`attempt()`'s `try` spanned `succeed()`, not just the HTTP call. So *any* internal error after a
2xx was recorded as a **delivery failure** and re-POSTed an event the receiver had already accepted.
Reachable in the ordinary case: a receiver returning a latin-1 error page ("Ungültige Anfrage")
produces an excerpt that is invalid utf8mb4, which MySQL under `STRICT_TRANS_TABLES` rejects — so
the success write throws, and the catch re-sends.

Fixed at both levels: the `try` now wraps the HTTP call **and nothing else** ("the receiver said
yes" and "our bookkeeping threw" are different events and must not share a handler), and the
untrusted excerpt is scrubbed to valid UTF-8 before storage. If the success write somehow still
fails, it is retried *without* the excerpt — never re-sent.

### 3. A disabled endpoint still received its backlog *(high)*

Auto-disable stopped *new* events but not the ones already queued — each still got its 6 attempts,
so a dead endpoint was hammered exactly as hard as before, and a customer who paused a subscription
kept receiving events. `attempt()` now parks a non-live subscription's rows in a terminal state
without POSTing (terminal, not pending — the pruner only reclaims finished rows, so pending would
accumulate forever).

### 4. A killed tick could stop all delivery for 24 hours *(low, cheap)*

`withoutOverlapping()` defaults to a **1440-minute** lock. On an every-minute command, one
ungraceful kill (deploy, OOM) leaves a lock nothing releases and webhook delivery silently stops for
a day. Now `withoutOverlapping(5)`. Safe, because overlapping ticks cannot double-POST — the
conditional claim UPDATE is what makes delivery single-flight, not the scheduler lock.

### 5. The test event contradicted itself *(low, but it is the one event customers build against)*

`sendTest()` called `Str::ulid()` twice — once for the row, once for the payload — so a ping's
`X-ZeroBook-Event-Id` header disagreed with the `event_id` in its body. We tell integrators to dedupe
on `event_id`; the test event is precisely the thing they check that logic against, and it was the
one event where the two ids never matched. It also stamped `company_id` from ambient state without
asking whether the subscription was authorized for that company — making it the only row in the
table to violate the invariant `sub->authorizesCompany(row->company_id)`, in the log customers read
to audit what we sent. Both fixed: one id, and a company only when the subscription may hear it.

### 6. The deploy script — four defects in the fix I wrote for the cron gap

A second review pass targeted **only the fixes made in this phase**, on the principle that a fresh
fix is exactly where a new bug hides. It was right to. Every finding below is in code I wrote today,
and the first is the worst kind:

- **The cron would never have installed.** I wrote `CRONDIR="$HOME/$APPDIR"` on the stated premise
  that `$APPDIR` is relative. It is not — `DOMAIN=/home/u958726172/domains/zerobook.in`, so
  `$APPDIR` is *already absolute* and the rest of the script cds to it directly. My line produced
  `$HOME//home/u958726172/domains/...`, a path that cannot exist. **My own verification missed it
  because I simulated the expansion with an invented `DOMAIN="zerobook.in"` instead of reading the
  real value** — a check that confirms your premise instead of testing it is worse than no check.
- **`crontab -l | crontab -` could have destroyed the server's crontab.** The `2>/dev/null` that
  hides the benign "no crontab for user" also hides a real read failure (spool lock, permissions).
  Piping an empty read back in would have *replaced* the crontab with only our line — deleting
  Hostinger's own backup and certbot entries. Now: the read is captured, its status checked, a
  failed-but-non-empty read aborts, and the previous crontab is backed up to `$HOME` first.
- **The self-check could not detect the failure it existed for.** `( … | head -12 ) || echo '!!'`
  takes the exit status of `head`, which is 0 even when artisan fatals — so the alarm could only
  ever fire for a failed `cd`. Now the status comes from artisan itself, and the check *also*
  asserts `webhook-dispatch` actually appears in the output.
- **It validated the wrong deploy.** The self-check ran *before* `config:cache`/`route:cache`, and
  `bootstrap/cache` is excluded from the upload — so it exercised the **previous** deploy's caches.
  Moved to after the re-cache.

All four are verified against the real variable values, with the remote command string executed
against stubs across seven cases: healthy server with existing entries, fresh server with none,
re-deploy (no stacking), failed-read-with-data, artisan fatal, `webhook-dispatch` missing, and a
missing app directory.

> **Coverage gap, stated plainly.** That second pass ran five attackers; **four died on an API
> session limit** and only the deploy-script one completed. So the company-scoping fix, the emitter
> cache, and the ack-is-final restructure were *not* independently attacked — they were covered by
> assertions I wrote myself, which is weaker evidence, and the one lens that did run found four
> real defects in a single file.
> (The workflow's own summary reported these five as "refuted"; that was an artifact of dead
> refuter agents returning null, not a verdict. I verified all five by hand instead.)
>
> **→ CLOSED.** All three surfaces were subsequently attacked to completion — see
> **[Re-Review](#re-review--the-three-surfaces-the-first-review-never-reached)** at the end of this
> document. It found **one real bug** (silent multi-webhook event loss via the tenant UI's delete)
> and **one real proof gap** (the update path), both fixed and pinned. Read that section rather than
> this note for the current state.

### 7. A tick could outlive its own overlap lock

`withoutOverlapping(5)` bounds the *lock*, not the *work*: a 50-row batch against a hanging endpoint
is 50 × 10s = 500s, so the lock expired mid-tick exactly when endpoints were slow, and ticks piled
up. Bounding the lock alone just moves the guess, so `dispatchDue()` now carries a **wall-clock
budget** (`webhooks.max_seconds`, 240s — deliberately under the lock). Unreached rows stay
`pending` for the next tick: under load, delivery spreads across ticks instead of stacking them.
Proven that the budget defers and never drops — with the budget exhausted, nothing is POSTed and
every row stays pending; the next tick delivers all of them.

### Known limit (accepted, not fixed)

**`dueRows()` is strict FIFO by id**, with no per-subscription fairness, so one subscription's
backlog can occupy a whole batch ahead of a healthy one in the same tenant. The time budget above
bounds the damage per tick but does not make it fair. If delivery volume grows, the answer is to
move it onto the queue with a job per delivery.

### Also fixed: emission was taxing every post in the product

Not from the review — I measured it. A plain voucher post by a tenant with **zero webhooks** cost
**4 extra queries**, two of them `information_schema` hits from `Schema::hasTable`, to re-learn
"nobody is listening" on every post. A feature almost no tenant has enabled must not tax the hot
accounting path: the emitter now reads the live set once per process, invalidated on any
subscription write. **4 → 0**, pinned by §13, which also proves the memo can't go stale (a
subscription created mid-process is seen by the very next post — buying speed by silently dropping
events would be far worse than the cost it saved).

> **That last worry was justified, and it had already happened.** "Invalidated on any subscription
> write" was true only for writes through a *model*; the tenant UI deleted through the query
> builder, which fires no model events. The [Re-Review](#attack-2--the-emitter-cache--one-real-bug-fixed)
> found that deleting one webhook silently stopped every **other** webhook in that tenant from
> receiving events. Fixed at the cause and made structurally impossible at the blast radius. The
> optimisation stands; the invariant it depended on did not hold, and the caveat in
> `WebhookSubscription::booted()` had named this exact hazard while asserting no path hit it.

---

## ⚠️ Production: the scheduler has never run on zerobook.in

**This is the most important operational fact in this README, and it predates 16C.**

`deploy.sh` never installed a scheduler cron entry. Nothing scheduled has *ever* run in production:
trial-check, reminders, backups, offboarding, and 16B's prune are all dormant. 16C's dispatcher
would have been the sixth dead command — a webhook system that queues and never delivers.

`deploy.sh` now installs the cron idempotently (it greps for a marker, so re-deploying never stacks
duplicates), with two details that matter:

- **The path is absolute.** `$APPDIR` is relative (`zerobook.in/app`) — fine for ssh, which lands in
  `$HOME`, but cron's working directory is not something to bet on. A relative `cd` that fails in
  cron short-circuits the `&&` and sends its complaint to `/dev/null`: the scheduler would look
  installed and silently never run — precisely the failure this block exists to end. `$HOME` is
  expanded on the server at install time so the crontab gets a literal path.
- **It self-checks.** An installed crontab whose command doesn't work is the same as no crontab, so
  the deploy runs `schedule:list` from the cron path and prints what cron will run.

**Read this before the next deploy:** it is a one-way door. The first tick will run five commands
that have never run against production data — most notably `trial-check`, which can flip lapsed
tenants to read-only *en masse* on its first pass.

Recommended: on the next deploy, read the `schedule:list` output the deploy now prints, then run
`trial-check` manually and read *its* output, before letting cron take over. This is a judgement
call about live customer data, so I have left it to you rather than making it silently.

---

## Acceptance checklist

`php artisan zerobook:prove-webhooks` — 145 assertions, 16 sections, all green. The three the brief
named as mattering most, verbatim from the run:

```
4 · POST-COMMIT ONLY — a rolled-back post emits nothing
 [PASS] no row is created INSIDE the transaction = 0
 [PASS] a rolled-back post emits nothing = 0

5 · Signature correctness (the scheme 16D reuses)
 [PASS] signature == an independently computed HMAC-SHA256 over "<ts>.<body>"

10 · A webhook failure never breaks a post
 [PASS] the post did NOT throw despite a broken emitter = false
 [PASS] the voucher still committed = 1
 [PASS] no delivery row (emission was swallowed + logged) = 0
```

§10 is the one worth reading the source of: it binds a **deliberately sabotaged emitter** — one that
throws from every entry point — into the container and then posts a voucher. The post succeeds. That
is the guarantee tested by breaking it on purpose rather than by trusting a `try`.

The other sections: subscription lifecycle + show-once (§2), matching (§3), delivery/retry/backoff
ladder + exhaustion (§6), auto-disable (§7), concurrency — two overlapping runs never double-POST
(§8), company + tenant isolation (§9), `party.outstanding.changed` fires once per party and only on a
real change (§11), wiring (§12), zero hot-path cost (§13), key-scoping (§14), ack-is-final (§15).

---

## Files

**New** — `WebhookSigner` · `WebhookEmitter` · `WebhookVoucherEvents` · `PartyOutstandingWatcher` ·
`WebhookDispatcher` · `WebhookEvents` · models `WebhookSubscription`/`WebhookDelivery` ·
`WebhooksController` · 2 resources · `WebhooksList` + view · `WebhookSubscriptionDisabled` ·
`WebhookDispatchCommand` · `WebhookPruneCommand` · `ProveWebhooksCommand` · `config/webhooks.php` ·
tenant migration `2026_07_29_000001_add_webhooks.php` · `_docs/phase16c_tenant_schema.sql`

**Modified** — `VoucherScreen` (2 additive hooks) · `ApiKey` (+`authorizesCompanySet`) ·
`IdempotentOperation` (+`$storeBody`) · `IdempotencyService` (stores `storedBody()`) ·
`config/webhooks.php` (+max_seconds) · `routes/api.php` · `routes/console.php` · `AppServiceProvider` (the {webhook} binding) · `routes/tenant.php` · `deploy.sh` · `docs/api.blade.php`

---

## Hand-off to 16D — inbound event ingestion

`WebhookSigner` is the shared surface, and it already works in both directions — 16D calls
`verify($secret, $timestamp, $rawBody, $signature)` on the way in exactly as 16C calls `sign()` on
the way out. Same ±300s window, same `hash_equals`.

Three things worth carrying over:

1. **The secret's storage rule inverts again.** A secret ZeroBook *verifies with* still has to be
   recoverable, so `encrypted` remains right — but the show-once machinery
   (`IdempotentOperation::$storeBody`) is what keeps it out of the idempotency table. Reuse it.
2. **Reuse the insert-first idempotency arbiter.** An inbound event will arrive twice; that is
   normal, not exceptional. The unique index is the arbiter, not a check-then-insert.
3. **Symmetry is the tolerance window's point.** It is enforced in both directions (a *future*
   timestamp is as suspect as an old one). A one-sided window is the classic replay hole.

`zerobook:prove-webhooks --keep` leaves both tenants and a `webhook:manage` key provisioned, which
is the fastest way to drive the surface by hand.

---

# Re-Review — the three surfaces the first review never reached

The original 16C review was compromised: four of five attacker agents died on a session limit, and
the workflow reported the survivors as "refuted" when that was dead agents returning `null`, not a
verdict. Three surfaces were therefore never independently attacked, and rested only on assertions
I had written myself. This pass closes that gap before 16D builds on them.

**Method.** Every finding below is reproduced by an executable probe driving the real HTTP kernel or
the real dispatcher — never by reading code. And because a passing test proves nothing until it can
fail, **each guard was mutated to reintroduce its bug and the probe re-run**, to show the test
actually catches it. Probes are committed at `_docs/attacks/attack{1,2,3}_*.php` — run them with `php _docs/attacks/attack1_company_scoping.php` (each provisions and tears down its own tenants; attack 1 also takes `--cached`).

## Attack 1 — the company-scoping privilege escalation

**25 checks, uncached and again with `route:cache` active. All pass. No vulnerability found.**

Covered: creating with the field omitted (must inherit the key's companies, not `[]`), naming a
forbidden company, asking for `[]` outright, a mixed own+forbidden list; **updating** an own
subscription to widen it (to a forbidden company and to `[]`), and that a refused widening leaves
the *stored* scope untouched; and `GET`/`PUT`/`DELETE`/`rotate-secret`/`deliveries`/`test` against
both a foreign-company and an all-companies subscription (all `404`).

Two controls keep the result honest: an all-companies key **does** see those same subscriptions and
**can** read the foreign delivery log — so the `404`s are real scoping, not a broken fixture.

Mutation evidence — the probe has teeth:

| Mutation | Probe result |
|---|---|
| `authorizesCompanySet()` → `return true` | **8 failures** (every create *and* update escalation) |
| `Route::bind` removed (implicit binding) | **13 failures** (foreign read/rotate/repoint/delete) |
| *neither* (shipped code) | 25 passed, 0 failed — cached and uncached |

### …but the proof had a real gap, and it is now closed

`prove-webhooks` §14 tested the **create** path and foreign access, and never tested **widening an
own subscription**. That is not hypothetical. Mutating only `update()` to skip the subset check:

```
--- does the CURRENT prove-webhooks catch it? ---
ALL WEBHOOK ASSERTIONS PASSED          ← green, while a restricted key silently widened to all companies
--- does the attack script catch it? ---
=== UNCACHED RESULT: 20 passed, 5 failed ===
```

That regression would have shipped. §14 now asserts the update path (widen-to-forbidden,
widen-to-`[]`, stored-scope-unchanged, omission-does-not-widen), the mixed-list create, and
`DELETE`/`test` on a foreign subscription. Re-run with the same mutation still in place, the proof
now **fails** with 4 assertions naming the update path exactly.

## Attack 2 — the emitter cache — **one real bug, fixed**

**FOUND: deleting one webhook in the tenant UI silently stopped every *other* webhook in that
tenant from receiving events.**

`WebhooksList::deleteWebhook()` deleted through the query builder
(`WebhookSubscription::whereKey($id)->delete()`), which fires **no model events**, so the emitter's
live-subscription memo kept serving the deleted row. Its `INSERT` then failed the foreign key —
and because the fan-out loop had a single `try` around the *whole* loop, that exception aborted it
before the surviving subscriptions were reached, and the outer catch swallowed it. Silent.

The single-subscription case looks fine, which is why this survived earlier: only the blast-radius
test exposes it.

```
-- 2.2c THE BLAST RADIUS: does one stale row cost OTHER subscriptions their events? --
  [*FAIL*] the two SURVIVING subscriptions still receive the next event
        got=0 want=2
```

Mechanism confirmed in the log, not inferred:

```
webhook.emit_failed … SQLSTATE[23000]: Integrity constraint violation: 1452
  Cannot add or update a child row: a foreign key constraint fails
  (`tenantatka`.`webhook_deliveries`, CONSTRAINT `webhook_deliveries_webhook_subscription_id_foreign`)
```

Fixed in two layers, because one of them is a rule a future caller could break again:
1. **Cause** — the UI now deletes through the model, so `deleted` fires and the memo flushes.
2. **Blast radius (structural)** — the fan-out is now guarded **per subscription**. Delivering to N
   independent receivers must be independent per receiver regardless of why one is unusable.

Also verified clean: a subscription created after the memo warmed is seen by the very next post; a
model delete stops delivery; one company's "no subscriptions" verdict does not suppress another
company's events; and a tenant switch inside one process does not judge tenant B by tenant A's memo
(the 12A memo-by-database bug class) — **9/9 after the fix.**

Pinned in `prove-webhooks` §13. Mutation-verified: reverting the per-row guard makes the new
assertion fail (`got=0 want=2`).

## Attack 3 — ack-is-final / double-delivery

**25 checks. All pass. No bug found.**

Covered: the conditional-`UPDATE` claim is single-flight; `claim_ttl` provably exceeds the longest
possible single attempt (`timeout + connect_timeout`), so a live-but-slow worker cannot have its row
stolen mid-flight; a succeeded row cannot be re-claimed, survives three further ticks, and stays put
even with `next_attempt_at` forced a day into the past; `redeliver()` is additive (new row, same
`event_id`, original left `succeeded` with its attempt count intact); the wall-clock budget defers
rather than drops, strands nothing in `delivering`, and the next tick delivers everything; and an
`exhausted` row stays dead with its attempt count unreset.

### A flaw found in the test, not the code

Mutating `claim()` to drop its status guard caught only 2 of the checks — the "worker 2 loses" check
still passed, **for the wrong reason**: MySQL counts only rows an `UPDATE` actually *changes*, and
with `claimed_at = now()` the guardless rewrite was identical within the same second. A test that
passes when the guarantee is broken is not evidence.

Fixed by back-dating the claim 30s (claimed, not yet stale) so a guardless claim genuinely changes
the row and wins. The strengthened probe catches **3** failures under the same mutation. The same
latent weakness existed in `prove-webhooks` §8 and was corrected there too — with the reasoning
written into the assertion so it is not "simplified" back later.

## Coverage — stated honestly

All three assigned surfaces were attacked to completion; nothing was blocked or skipped, and nothing
is reported here as refuted that was not actually run. Out of scope by instruction and untouched:
`WebhookSigner`, the post-commit emission guarantee, the retry schedule, `deploy.sh`.

**What "verified" means here, precisely.** These probes were written by the same author as the code,
so this is not the same thing as an independent reviewer. What makes it materially stronger than the
self-assertions it replaces is that the evidence is *falsifiable and reproducible*: every probe is
executable and committed, and every guard was **mutated to reintroduce its bug** to demonstrate the
probe fails when the property does not hold. A test that has never been observed failing is not
evidence — that is the lesson of the update-path gap, which was green for weeks. Independent
attackers would still add value, chiefly by questioning framing this pass took for granted (for
instance: is subset-of-key the right rule at all, or should a subscription bind to its creating key?).

One residual limit, unchanged and still accepted: `dueRows()` is strict FIFO by id with no
per-subscription fairness. The time budget bounds the damage per tick; it does not make it fair.

## Result

| Surface | Verdict |
|---|---|
| Company scoping | No vulnerability. Proof gap found and closed; mutation-verified. |
| Emitter cache | **One real bug** (silent multi-webhook event loss). Fixed at cause + blast radius, pinned. |
| Ack-is-final | No bug. One test weakness found and fixed. |

`zerobook:prove-webhooks` — **145 assertions** (123 → 132 → 145). Full battery after every fix in this pass: **34/34, zero failures** (clean run, no concurrent process).

---

# Independent attack — what self-review could not find

The re-review above was thorough but self-written, and I said so at the time: *"these probes were
written by the same author as the code… independent attackers would still add value, chiefly by
questioning framing this pass took for granted."* That was correct, and understated.

**52 agents, zero died.** Four attackers on distinct lenses, each finding refuted by three
independent lenses (code-reality, exploitability, intent+regression). It produced **16 reproduced
findings**; 27 of 48 refuter verdicts came back CONFIRMED. Four were severe enough to fix
immediately, and **all four are things a self-review structurally could not have caught** — three of
them are places where my own reasoning was correct in one spot and never carried to the next.

## 1. Tightening a scope retroactively widened a read *(high)*

`deliveries()` filtered on `webhook_subscription_id` alone, inheriting authorization from the route
binding — which tests a subscription's **current** company set. The delivery log is **historical**:
each row carries the company it was emitted for. So narrowing an all-companies subscription to
`[own]` — an ordinary, encouraged tightening — handed its entire back-catalogue of the *other*
company's rows to a restricted key.

The binding's own docblock states the goal as stopping a key from reading "another company's voucher
payloads straight out of its delivery log". Narrowing defeated exactly that. One refuter went
further and showed the narrowing principal need not even be privileged: an `[A,B]` key tightening
its **own** subscription to `[A]` exposes B's rows. It also falsified an invariant I assert *and pin*
in `sendTest()` — that every row satisfies `authorizesCompany(company_id)`.

Fixed: the log is now filtered by company in its own right, failing closed on a null `company_id`.

**I initially fixed only half of this, and caught the rest by re-reading the finding rather than my
patch.** Filtering the log stops a restricted key *reading* the removed company's rows — but those
rows were still **queued**, and `attempt()` verified `isLive()` without ever re-checking company
authorization. So the endpoint still received the removed company's full voucher payload: the API
hid it while the webhook shipped it. Restricting a webhook has to bind the backlog, not just the
future. `attempt()` now re-verifies `authorizesCompany(company_id)` at send time and parks a row
that no longer qualifies. Mutation-verified: reverting it fails the send-side assertion while the
read-side one still passes — which is exactly how a half-fix hides.

## 2. An omitted `is_active` silently resurrected a paused subscription *(medium)*

`attributes()` defaulted `is_active` to `true` on every write. So a routine "reconcile my webhook
config" `PUT` — the standard integration pattern, never mentioning `is_active` — un-paused a
subscription the owner had deliberately switched off, cleared the auto-disable, and zeroed the
failure budget. **Auto-disable was permanently defeatable** by any client that reconciles on a timer.

This is the sharpest finding, because ten lines below it I had written exactly the right reasoning
for the *other* field: *"defaulting an omitted field to [] would silently WIDEN an update."* I made
the argument and applied it once. An omitted field now means "leave it alone" for both.

## 3. `claim()` omitted the due-time predicate `dueRows()` enforces *(high)*

`claim()` is the arbiter under overlap, so it must re-state **every** condition that made a row
eligible — a `WHERE` that only `dueRows()` enforces is not a guard, because the row can change
between the read and the claim. Two ticks both read a row as due; tick A delivered, got a 5xx, and
scheduled it 5s out; tick B then claimed it — now `failed`, which `claim()` accepted — and re-POSTed
**immediately, inside the backoff window**. Fixed by adding `next_attempt_at <= now()` to the claim.

## 4. Pausing a subscription destroyed its backlog *(medium)* — my own regression

This one was introduced by *my* fix in the previous pass. To stop auto-disable hammering a dead
endpoint I parked every non-live subscription's rows as terminal — but that conflated "not now" with
"never". Pausing for even a moment permanently destroyed everything queued; the customer resumed and
the events were simply gone.

Now: **auto-disabled → terminal** (the endpoint is dead, which was the real intent), **paused →
deferred** (kept pending, re-checked later, `attempt_count` untouched so a pause never eats the
delivery budget), bounded by the retention window so an abandoned pause cannot accumulate forever.
Re-enabling releases the backlog immediately rather than making the customer wait out the deferral.

## Verification

All four reproduced first in `_docs/attacks/attack4_independent_findings.php` (**8 checks: 6 failing
before, 8 passing after**), then pinned in `prove-webhooks` §16. The three earlier probes and the
whole battery were re-run to confirm no regression.

## Remaining findings — not fixed, recorded

Twelve further reproduced findings were not addressed in this pass. They are real but are design
questions or lower-severity, and changing them is redesign rather than attack-and-fix. The
substantial ones for 16D to weigh:

- **A subscription is not owned by its creating key.** Any wider key in the tenant can rotate,
  repoint, or read the delivery log of a narrower key's subscription. Subset-of-key may be the wrong
  rule; binding a subscription to its creator is the alternative.
- **A revoked or expired key's subscription keeps delivering forever.** Revocation stops the API
  surface but not the standing grant the key created.
- ~~**`redeliver()` performs no status check**~~ — **reproduced and fixed.** Redelivering a row that
  had not finished minted a SECOND live copy of the same `event_id`, and the next tick POSTed both.
  Survivable (we tell integrators to dedupe on `event_id`) but it made ZeroBook the source of the
  duplicate, from a button sitting in the delivery log next to rows that are merely slow. It is now
  a no-op that hands back the row already in flight; a finished row still redelivers normally. The
  UI message was corrected too — it had claimed a re-queue that did not happen.
- ~~**Emission reads whatever connection is current at commit time**~~ — **tested, does not
  reproduce.** A post wrapped in a transaction on the CENTRAL connection still emits to the tenant's
  subscriptions: `DB::afterCommit` registers against the default connection, which under tenancy is
  the tenant's. Kept as a probe (`attack5` G3) rather than a fix.
- **One unreadable subscription row does not stop the others** — also tested, does not reproduce; the
  per-row guard added earlier already covers it (`attack5` G2).
- ~~**`flushCache()` does not clear `$tableCache`**, and that docblock's justification is wrong.~~
  **Fixed.** A reset that silently leaves state behind is not a reset. The docblock had claimed "a
  table cannot un-exist under a running tenant" — false, since a teardown drops the database and the
  prove commands tear down and re-provision the same name in one process. The memo is safe because
  it is flushed, not because the fact is immutable. Corrected rather than left standing: a false
  invariant in a comment is what made the earlier cache bug invisible.

## What this changes about the verdict

The self-review found 1 bug and 1 proof gap. The independent pass found 16 more, 4 of them serious,
in code that had just been attacked and mutation-tested by its author and was passing 132 assertions
and 34/34. **Mutation-verified self-review is necessary and not sufficient** — it proves your tests
catch the bugs you imagined, and says nothing about the ones you did not. Independent attack on a
security-relevant surface is not optional polish.


---

## Final state — 16C

| | |
|---|---|
| `zerobook:prove-webhooks` | **145 assertions**, 16 sections, all green |
| Full battery | **34/34, zero failures** (clean run, no concurrent process) |
| Attack probes | `attack1` 25/25 · `attack2` 9/9 · `attack3` 25/25 · `attack4` 10/10 |
| Bugs fixed this phase | 7 from review + 6 from independent attack |
| Recorded, not fixed | 11 findings — design questions, listed above |

**16D can build on the company-scoping guard and `WebhookSigner`**, with two caveats that are
decisions rather than defects and should be settled as part of its design:

1. **A subscription is not owned by its creating key.** Any wider key in the tenant can rotate,
   repoint, or read the delivery log of a narrower key's subscription. Subset-of-key may be the
   wrong rule; binding a subscription to its creator is the alternative.
2. **A revoked or expired key's subscription keeps delivering.** Revocation closes the API surface
   but not the standing grant that key created.

`WebhookSigner` itself was out of scope for both review passes by instruction — it was covered by
the first review, which independently verified the signature scheme, the ±300s symmetric tolerance
window, and `hash_equals`. 16D reuses it unchanged.
