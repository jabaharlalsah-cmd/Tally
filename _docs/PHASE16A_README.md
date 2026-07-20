# Phase 16A — API foundation (authentication, scoping, rate limiting, logging)

The infrastructure every later API phase inherits. When 16A ships, a customer issues a key in
their ZeroBook admin panel, calls `GET /api/v1/ping`, and gets an authenticated response. There
is no accounting API yet — that is 16B.

The correctness spine of this phase is **security, not accounting math**. The failure modes here
are the quiet ones: they do not break a test, they ship, and they get exploited. Get the key auth
wrong and a tenant leaks. Get the scoping wrong and a read-only key writes. Get the isolation
wrong and tenant A's key reads tenant B. This document is organised around those.

---

## Step 0 — audit result

**Before 16A: 31/31 prior `prove-*` commands pass. After 16A: 32/32 green** (re-run end to end
after every 16A change landed — 32 run, 32 terminal PASSED verdicts, zero failures), with
`zerobook:prove-api-foundation` adding **130 assertions across 16 sections**.

The regression guarantee is structural rather than hopeful: 16A is **purely additive to the
accounting engine**. No service, model, or query used by the books was touched. The API is a new
route file, a new middleware group, two new tenant tables and one central table. The one edit to
shared code — `RequireActiveTenant` — is behind a branch that is only reachable when
`IdentifyTenantByApiKey` has stamped a verified API key on the request, so the interactive web
path is byte-identical to pre-16A.

> **Environment note that cost the first hour.** `php artisan` does not run on the default PATH:
> Laragon's toolchain is not global and the default `php` is 8.2.12, while the project requires
> ≥ 8.3. Every command must be prefixed with
> `export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:…:$PATH"`.

### The audit found a live deploy blocker (fixed here, not caused by 16A)

`deploy.sh` **never ran tenant migrations.** It runs only `artisan migrate --force`, which covers
`database/migrations/*.php` — the CENTRAL schema. Tenant migrations live on a separate path
(`config/tenancy.php` → `migration_parameters --path`), and plain `migrate` does not touch them.

Every prior deploy that added a per-tenant table shipped the code **without the table**. For 16A
that is fatal in a specific way: `api_keys` and `api_request_log` are per-tenant, so the routes
and middleware would deploy fine and then **500 on the first real API call in production**, with
local dev perfectly green. `deploy.sh` now runs `tenants:migrate --force` and reports failure
loudly rather than silently — a partial failure means some tenants are on an older schema, which
must be visible in the deploy output rather than discovered by a customer. (`deploy.bat` wraps
`deploy.sh`, so both are covered.)

---

## Six places the brief contradicted the codebase

16A was specified against an idea of the codebase. Six of those ideas were wrong, and each would
have produced a real defect. They are listed here because the *reasoning* is what 16B needs, not
just the outcome.

### 1. There is no "admin" role — gating on it would lock out every user

The brief: *"Access controlled to tenant admins only (existing role from 7B)."*

`tenant_users.role` is a plain string, default `'member'`, documented `owner | accountant |
member`. **No row ever holds `'admin'`**, so `role === 'admin'` denies 100% of users. There is
also no Gate, no Policy, no AuthServiceProvider and no role middleware anywhere: every existing
settings screen (`/subscription`, `/companies`, `/features`, `/account/data`) is open to any
authenticated user. **16A is the tenant app's first authorization gate** — there was no
"existing" pattern to follow.

Gate: `role === 'owner'`, checked **positively**. `role !== 'member'` would wrongly admit
`accountant`.

### 2. The brief's key placement cannot resolve a tenant — hence `api_key_directory`

The brief reasons itself into a dead end:

> *`tenant_id` (redundant but useful for cross-DB lookups — actually since it's in tenant DB, no
> need; drop this and rely on connection)*

There is no connection to rely on. An API request carries **one bearer token and nothing else** —
no subdomain, no session, no cookie. Resolving the tenant is precisely what the lookup must do,
so it cannot be answered by a table that can only be read *after* the tenant is resolved. With
`api_keys` per-tenant and no central index, authenticating one key means **scanning every tenant
database** — touching every customer's DB to serve one request.

**Resolution:** a central `api_key_directory` mapping `prefix → tenant_id`. The prefix is
deliberately non-secret and plaintext (it *is* the lookup handle), so a central index leaks
nothing; the authoritative row — hash, scopes, companies, revocation — stays in the tenant DB.
"Each tenant sees only its own keys" remains true of the schema. This is the standard model for
prefixed API keys, and the only O(1) option that keeps the brief's fixed key format.

The directory is a **router, not an authority**: both the hash verify and the revoked/expiry check
run against the tenant row, so a stale directory entry cannot authenticate anything — it can only
point at a tenant whose own row then rejects the key. Its `revoked_at` is a housekeeping mirror,
never read on the auth path, so the two cannot disagree in a way that grants access.

### 3. `RequireActiveTenant` did nothing for the API — and its JSON branch was dead code

The brief assumes the API inherits it. It is registered **only** in the `routes/tenant.php` group,
so it never ran for `api/*`, and its `$request->is('api/*')` branch was **unreachable**. Wired up
unchanged it would also have been wrong twice over: it lets `GET` through (a suspended tenant
keeps read access **by design**), and it returns `redirect()->route('tenant.login')` for a closed
account — meaningless to a machine client.

**Resolution:** an API-only branch inside it, keyed on the **request attribute** the identifier
stamps — *not* on the path. `api/*` already belongs to `/api/sync/push`, `/api/sync/pull` and
`/api/subdomain-available`; path-matching would have swept all three into the new rule and broken
desktop sync.

**A deliberate product divergence, worth a conscious yes:** the API is **stricter than the web**.
The web keeps a suspended tenant's books readable — a human should still see what they are paying
to restore. A machine integration is the opposite case: an HMS that keeps reading a suspended
account has not noticed anything is wrong. So the API goes dark and says why, while staff can
still log in and read.

### 4. The status list is incomplete, and a blocklist fails open

The brief names `suspended, expired_trial, archived, purge_scheduled, purged`. It **omits
`expired_subscription`** — a real status for a paying customer lapsed past grace — plus
`provisioning`, `pending_verification`, `cancelled`, `restored`. A blocklist built from those five
would have granted **full API access to a lapsed-subscription tenant**.

The rule is the **positive** test `$tenant->isWritable()` (`status === 'active'`). Only active is
active. `prove-api-foundation` asserts `expired_subscription → 403` specifically because the brief
would have let it through.

Related traps confirmed in the code: `TenantGate::statusWritable()` **fails open**
(`return $tenant ? $tenant->isWritable() : true`), so it is not consulted here — the tenant row is
read directly. And `purged` means the **database is gone**, so status is checked before connecting.

### 5. `api:{api_key_id}` is a cross-tenant rate-limit collision

The brief specifies that limiter key. But `api_keys` is **per-tenant**, so every tenant has a key
with id 1 — and the cache is deliberately **not** tenant-scoped (`config/tenancy.php` enables only
`DatabaseTenancyBootstrapper`; the cache bootstrapper needs a taggable store the file driver is
not). One global keyspace + a per-tenant id means `api:1` is **a single bucket shared by every
tenant's first key**: tenant A's traffic consumes tenant B's budget, and A can deny service to B
by spending it.

Key: **`api:{tenant_id}:{key_id}`**. The raw secret is never in the key — it would be written to
the cache, and to disk under the file store, in plaintext.

### 6. "Async" `last_used_at` is a fiction here

`QUEUE_CONNECTION=sync` makes `dispatch()` run the job **inline**, adding its latency to the
response — the exact opposite of the brief's intent. And no worker exists (`deploy.sh` has no
`queue:work`, supervisor, horizon or cron), so the `database` fallback would mean jobs **never
run**. Both async routes are worse than the write they replace.

**Resolution:** written from `LogApiRequest::terminate()`, which PHP-FPM runs **after the response
is flushed** — genuinely off the request the client waits on, with no worker to operate — and
**throttled to one write per minute per key**. A key doing its full 60 req/min costs 1 update per
minute, not 60: cheaper than a queue would have been. Cost: `last_used_at` can trail by up to a
minute, invisible on a screen that renders "2 minutes ago".

---

## Adversarial self-review — 11 findings, 3 confirmed, 8 refuted

A multi-agent review (6 attackers, each finding then judged by 3 independent skeptics through
different lenses) attacked this build before delivery. **All three confirmed findings are fixed**
and each now has a proof assertion so it cannot come back.

### 1. The timing equaliser was itself the biggest timing leak (MEDIUM — the important one)

`authFailed()` burns a dummy bcrypt so a miss costs what a hit costs. It memoised the dummy digest
in a **per-process static**. PHP-FPM is shared-nothing: statics reset on **every request**. So in
production the memo was *always* cold, and every miss paid `Hash::make` **plus** `Hash::check` — 2
bcrypts — while a real hit paid one. Measured **447ms vs 225ms, a 1.98x split**.

That is precisely the oracle the dummy verify exists to close, merely **inverted**: an unknown
prefix answered ~2x *slower* than a real one, so prefixes were enumerable by the clock exactly as
if there had been no dummy verify at all. The class docblock's residual-risk note ("bcrypt
dominates... the miss is not the slow path") was backwards.

**Why §5 reported a reassuring 0.99 the whole time — the part worth internalising.** The proof runs
in ONE long-lived console process. The first `authenticate()` warmed the static; every later call
reused it. **The proof measured the one process model production never runs.** It also asserted
only a *lower* bound (`miss >= hit * 0.25`), designed to catch a *missing* verify — a miss that is
too SLOW sailed through.

Fixed by caching the digest across processes (keyed by hashing config so re-tuning bcrypt cost
regenerates it at the new cost). Steady-state cost is now one cache read + one `Hash::check` — the
same single bcrypt a hit pays. §5 now **clears the static before every measurement** to model the
FPM process boundary, and asserts the ratio in **both** directions. Result: **0.98–1.00**.

### 2. Maintenance mode was reported to customers as a crash (MEDIUM)

`deploy.sh` runs `artisan down --retry=15` on **every deploy**. `PreventRequestsDuringMaintenance`
throws `HttpException(503)` carrying `Retry-After`. The envelope's `$status >= 500` arm swept that
in, rewrote it to a **bare 500**, dropped `Retry-After`, and minted an `error_id` **nothing logged**.

So every deploy window would have told every integration "ZeroBook has a bug" instead of "back in
15 seconds" — they would page their own on-call and retry hard rather than back off — and any
error_id they quoted was a **reference to nothing**, which is worse than no id because support goes
looking for it. Now: 503 stays 503 with its `Retry-After`, and every 5xx error_id is genuinely
logged. (405 keeps its `Allow` header for the same reason.)

### 3. Pre-routing errors had no `X-Request-Id` (LOW)

`LogApiRequest` is **route** middleware, and route middleware only runs once a route has *matched*.
A 404 on an unknown `/api/v1/*` path, a 405, and a maintenance 503 never reach it — so the
responses a customer is most likely to quote in a ticket shipped with **no request id**, quietly
making the "every response carries one" contract false exactly where it mattered. `ApiError` now
mints one when none is stamped.

### The 8 refuted

Recorded because the reasoning matters, not just the verdict: rejection paths skipping the rate
limiter (real mechanism — but a rejected request is *cheaper* than the counterfactual, and garbage
keys are already unthrottled, which is the documented gap below); a 429 still writing a log row
(the DB insert is dwarfed by the bcrypt that preceded it); the `?? 'unknown'` limiter fallback
(unreachable — identification guarantees a tenant); `query_string` persisting a key a client puts
in a URL (real, but requires the customer to misuse a parameter the product never documents —
hardening, not a vulnerability); `QueryException::getMessage()` in a log catch (true framework
mechanic, unreachable here); and `ApiKeyActivity`'s mount-only gate (Livewire re-runs `mount()` for
a full-page component on every request).

One refuted finding is worth flagging: a skeptic argued the group-attached scope fix was
"unimplementable in Laravel." It is implemented, it works, and §10/§14 assert it. **The skeptics
were not always right either** — which is why every fix here is backed by an executable assertion
rather than an argument.

---

## The bug this build found and fixed

Worth recording because it was invisible from the happy path and would have shipped.

`IdentifyTenantByApiKey` must beat `SubstituteBindings` (see *Middleware chain* below), so it is
added to Laravel's global middleware **priority list**. But the sorter only orders middleware the
list *names* — anything unlisted keeps its declared position and **loses every race against a
listed one**. Adding the identifier alone silently reordered the group:

```
declared:  LogApiRequest → IdentifyTenantByApiKey → …
actual:    IdentifyTenantByApiKey → SubstituteBindings → LogApiRequest → …
```

So a **rejected** request — a 401, the single most important thing to be able to trace — returned
before the logger ever ran: **no `X-Request-Id` on the response**, and no request id for the error
envelope to quote. A 200 still got one, so nothing looked wrong. The declared order in
`routes/api.php` was a lie about execution.

Fixed by naming `LogApiRequest` in the priority list too, ahead of the identifier.
`prove-api-foundation` §14 now asserts the **sorted** order (not the declared one), and §3/§4
assert `X-Request-Id` on both a 200 and a 401.

---

## API key format and hashing

```
zb_<env>_<32 random chars>        zb_live_a1b2c3d4e5f6…      (env: live | test)
prefix = 'zb_<env>_' + first 8 of the random part   →   zb_live_a1b2c3d4
```

* **The prefix is plaintext and indexed.** It is the lookup handle, not a secret.
* **The full key is stored only as a bcrypt hash.** There is no path back from the row to the
  key: lose it, revoke and re-issue.
* 32 chars from `Str::random`'s alphabet ≈ **190 bits**. Total key length 40 bytes, safely under
  **bcrypt's 72-byte truncation limit** — lengthening the format past 72 would silently stop
  distinguishing keys.
* Prefix generation loops against a uniqueness check; the prefix is uniquely indexed in **both**
  tables.

**`Hash::make` / `Hash::check`, not raw `password_hash` / `password_verify`.** The brief specifies
`password_hash()`; this codebase hashes exclusively through the `Hash` facade (grep: zero
`password_hash` calls). `Hash::make` **is** bcrypt and `Hash::check` calls `password_verify`
internally — so the brief's requirement (a bcrypt digest compared in constant time) is met
exactly, while `config/hashing.php` retains the ability to re-tune cost or migrate algorithms.

`key_hash` is in the model's `$hidden`, so it cannot ride out through `toArray()`/`toJson()` into
a response, a Livewire snapshot, or a `dd()`.

---

## Constant-time comparison discipline

Timing is the side channel this phase is most likely to leak through, so the rule is explicit:

> **Every failure path performs a real bcrypt verify against a dummy hash before returning null.**

A miss must cost what a hit costs. Without it, "prefix not found" returns in microseconds while
"prefix found, wrong secret" pays ~200ms of bcrypt — turning the response clock into an oracle for
enumerating valid prefixes offline. `ApiKeyService::authFailed()` is not decoration; deleting it
reintroduces the side channel. The dummy hash is generated once per process, so no fixed digest
ships in source.

There are **no early returns on a partial match**. Malformed key, unknown prefix, missing tenant,
closed tenant, prefix-in-directory-but-not-in-tenant-DB — all route through the same dummy verify.

**The dummy digest is cached across processes, and that is load-bearing — not an optimisation.**
A per-process `static ??= Hash::make(...)` is the obvious implementation and it is wrong: PHP-FPM
resets statics every request, so each miss would pay `Hash::make` + `Hash::check` (2 bcrypts) while
a hit pays 1 — a 2x oracle, inverted. That shipped and was caught in adversarial review; see above.
The cache key is derived from the hashing config so re-tuning bcrypt's cost regenerates the dummy
at the **new** cost, keeping both paths equal.

Measured by `prove-api-foundation` §5 (this run), **with the per-process memo cleared before every
measurement** so the numbers reflect PHP-FPM rather than a warm console process:

```
cold-process: found-prefix 220.4ms · unknown-prefix 214.9ms · malformed 215.1ms   (miss/hit 0.98)
```

The proof asserts each miss costs **≥ 25%** of a real verify (catching a *missing* verify — a miss
that is too fast) **and ≤ 1.6×** (catching the inverted oracle — a miss that is too slow). Both
bounds exist because only the lower one was there before, and the bug lived in the gap. This is a
structural check, not a statistical one: a loaded Windows dev box is too noisy for a tight bound,
but a skipped verify shows up 10–100× faster and a doubled one lands at ~2.0×, and the bounds
bracket both decisively.

**Honest residual:** a real prefix additionally opens a tenant DB connection that a miss does not.
bcrypt dominates that by orders of magnitude (the 0.98 ratio above includes it), but it is not
zero. Closing it fully would need a dummy connection on the miss path; judged not worth the cost.

Both the 401 bodies and their codes are **byte-identical** across "no header", "garbage", "unknown
prefix" and "real prefix, wrong secret" — asserted directly (§4), not assumed.

---

## Middleware chain

Actual execution order (asserted in §14 — **the priority list decides this, not `routes/api.php`**):

| # | Middleware | Why here |
|---|---|---|
| 1 | `LogApiRequest` | **Outermost**, so *every* response — 401s and 429s included — gets an `X-Request-Id`, and so the row is written from `terminate()` after the response is on the wire. |
| 2 | `IdentifyTenantByApiKey` | The security boundary: verify key → open tenant → pin company. |
| 3 | `SubstituteBindings` | Injected by the `api` group; must run **after** #2 (see below). |
| 4 | `RequireActiveTenant` | Lifecycle gate — needs a tenant to judge, and runs before any work is done for a tenant that should not get any. |
| 5 | `RateLimitByApiKey` | Per-key, so necessarily after the key is known. |
| 6 | `EnforceApiPermissions` | Innermost — the cheapest check, once identity is established. |

### The two ordering invariants

**Tenancy is initialized before the company is pinned.** `ActiveCompany` memoises the Company row
against the current database name. Pinning while still on the central connection memoises the
wrong database — and since **every tenant's default company is id 1**, that resolves silently to
another tenant's identity rather than erroring.

**The company is pinned before any handler runs.** This is the one to remember:

> **`BelongsToCompany` reads `ActiveCompany::id()` and, when it is null, SKIPS the WHERE clause.
> It does not throw. Reads FAIL OPEN.**

An API request that authenticated the tenant but forgot to pin a company returns **every
company's rows in that tenant, with HTTP 200 and no error anywhere**. Writes fail closed
(`ActiveCompany::check()` throws); reads do not. That asymmetry is the quietest way this phase
could have shipped a data leak, so the pin is unconditional and the middleware refuses the request
rather than continue without one.

`IdentifyTenantByApiKey` is prepended to the priority list ahead of `SubstituteBindings` for the
same reason `SetActiveCompany` is: with no company active the scope is inert, and a cross-company
id in a URL would resolve (HTTP 200, identity fields disclosed) instead of 404ing. 16A's only
route takes no bound model, so this changes nothing today — it is there so **the first 16B route
with a `{voucher}` binding cannot inherit that bug by omission**. stancl's
`makeTenancyMiddlewareHighestPriority()` force-prioritises only its *own* classes; a custom
identifier gets none of that and must ask.

### Why none of the 7B stack is reused

`SetActiveCompany` calls `$request->session()` and the `api` group has no `StartSession` — it
throws. `InitializeTenancyBySubdomain` has no subdomain to work from.
`PreventAccessFromCentralDomains` would reject the call outright. `web` would impose CSRF on a
machine client. The `api` group is `[SubstituteBindings]` only — no session, no CSRF, no throttle
— which is exactly the sessionless surface wanted, and means **60/min is entirely 16A's job**
(Laravel's default `throttle:api` limiter is never registered in this app).

---

## Company resolution

| Request | Behaviour |
|---|---|
| `X-Company-Id` present | Must be **authorized by the key** *and* active → else `403 company_not_authorized`. Never silently ignored. |
| Header absent | Lowest-id **active** company the key is authorized for. |
| No candidate | `403 company_unavailable`. |

Validated against the **key's** authorization list, never merely against the tenant — that is the
difference between company scoping and a suggestion. Non-numeric values are rejected outright
rather than cast (`'1abc'` must not become `1`).

There is **no fallback to the tenant's default company** when the requested one is unavailable.
`Company::defaultCompany()` is deliberately not used: it falls back to the lowest-id company of
*any* state, so it can hand back a deactivated company, and it ignores the key's list. The
codebase already learned this on the sync API, where silently serving a different company's books
merged two desktop mirrors. Refusing is the only safe answer.

`api_keys` deliberately does **not** use the `BelongsToCompany` trait — the scope would filter by
the active company, which at lookup time is exactly what has not been decided yet.

---

## Permission scopes

Catalog (`App\Support\ApiScopes`) — one list, shared by the issuing UI, the service, and the
enforcement middleware, so they cannot drift:

| Scope | Lands in |
|---|---|
| `voucher:create` · `voucher:read` · `voucher:alter` · `voucher:cancel` | 16B |
| `master:read` · `master:write` | 16B |
| `report:read` | 16B |
| `webhook:manage` | 16C |
| `event:ingest` | 16D |
| `*` | all scopes — for first-party integrations where the customer owns both sides |

`EnforceApiPermissions` is attached to the **group**, and each route names its scope as a **route
default**:

```php
Route::post('/v1/vouchers', …)->defaults(EnforceApiPermissions::SCOPE_DEFAULT, 'voucher:create');
Route::get('/v1/ping', …)    ->defaults(EnforceApiPermissions::SCOPE_DEFAULT, 'none');
```

**Fail-closed by omission — the decision that matters for 16B, and why the group placement IS the
mechanism.** A route declaring **no** scope is **refused**, not allowed. The alternative ("no
declaration = no requirement") means a 16B endpoint whose author forgets silently becomes readable
by **every key ever issued**, with no test to say so.

An earlier revision attached the middleware **per-route**
(`->middleware(EnforceApiPermissions::class.':voucher:create')`). That reads more idiomatically and
was quietly broken: forgetting the whole `->middleware(...)` call — strictly *easier* to do than
forgetting its argument, and the far likelier mistake — left the route with **no scope check at
all**. The "fail-closed" claim only covered the one mistake nobody makes. Verified by mounting a
route in the group without it: zero enforcement. Group-attaching it means the check **cannot be
skipped**, only under-declared — and under-declaring denies. §10 and §14 assert both the behaviour
*and* the structure, so a refactor back to per-route fails the proof.

(Route defaults are merged into `$route->parameters()`, so `api_scope` appears there. It is inert —
nothing binds or injects it, URL generation ignores it — which is the price of a carrier a route
cannot silently skip.)

A **misspelled scope is unsatisfiable** (`ApiScopes::satisfies` rejects anything outside the
catalog), so a typo closes a route rather than opening one. Unknown scopes are dropped at issue
time, so a key can only ever hold catalog scopes.

This is enforcement, not a UI hint: scopes come from the tenant-DB row. **No header, body field or
query parameter can widen them** — the only inputs are the route's declaration and the stored row.

---

## Rate limiting

* **60 requests/minute per key**, default from `config('zerobook.api.rate_limit_per_min')`,
  overridable per key via `api_keys.rate_limit_per_min` (a bulk integration asks for a higher
  ceiling rather than raising it for everyone).
* Key: **`api:{tenant_id}:{key_id}`** — see deviation #5.
* Exceeded → `429` + the error envelope carrying `retry_after_seconds`, plus a standard
  `Retry-After` header.
* Every response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`; a 429 adds `Retry-After`
  and `X-RateLimit-Reset` (matching `ThrottleRequests`' header names).
* `RateLimiter::hit()` takes **seconds**; `ThrottleRequests`' 4th arg takes **minutes**. Easy to
  conflate into a 60-*minute* window.

**Honest limits.** `FileStore::increment` is a read-modify-write with **no lock**, so genuinely
concurrent requests can undercount: 60/min is **best-effort, not exact**. It is a business
throttle, not a security control, and nothing downstream depends on its precision — so the proof
asserts the 61st *sequential* request is rejected and does not assert anything about concurrency.

**Known gap:** the limiter runs *after* identification (it is per-key, so it must), which leaves
the bcrypt verify itself reachable by unauthenticated callers — a CPU-burn vector. An IP-level
pre-limit would close it and is **not** in 16A; noted for 16E rather than silently assumed.

---

## Error envelope

```json
{"error": {"code": "snake_case", "message": "human readable", "details": {}}}
```

`code` is the machine contract — a customer's integration branches on it, so it stays stable
across phases. `message` is safe to show an end user. `details` is optional.

| Status | Codes |
|---|---|
| 401 | `invalid_key` · `key_revoked` |
| 403 | `tenant_not_active` · `insufficient_scope` (with `details.required`) · `company_not_authorized` · `company_unavailable` |
| 404 / 405 | `not_found` · `method_not_allowed` (keeps its `Allow` header) |
| 409 | `conflict` |
| 422 | `validation_failed` (with `details` = the validator's errors) |
| 429 | `rate_limited` (with `details.retry_after_seconds`) |
| 503 | `service_unavailable` (with `Retry-After`) — maintenance, **not** a crash |
| 500 | `internal_error` (with `details.error_id`) |

Three rules the envelope exists to enforce:

1. **Nothing internal leaks.** The renderer is scoped to `api/v1/*` and is **deliberately
   independent of `APP_DEBUG`**: Laravel's default renderer will happily put an exception message
   — and with debug on, the class, file, line and full stack — into the response body. On a public
   API that is an information-disclosure bug that only appears in production. A server fault
   always returns a generic body plus an `error_id`; the real exception goes to the app log under
   that same id, where support can find it and the client cannot.
2. **An `error_id` is only worth handing out if it is actually logged.** Every 5xx path writes its
   id to the app log. An id the client can quote but support cannot find is worse than no id,
   because someone goes looking. (One 5xx arm used to mint an unlogged id — see the adversarial
   review above.)
3. **Every response carries `X-Request-Id`**, errors included — *including* the ones that never
   reach the middleware chain (404, 405, 503), which is exactly where the guarantee first broke.
   `ApiError` mints an id when nothing has stamped one, so the contract holds without exception.
   That is what makes *"it failed at 10:04, request 01J…"* a traceable report rather than a guess.

**Status is preserved, not flattened.** A 503 stays a 503 and keeps `Retry-After`; a 405 keeps
`Allow`. The status and headers are part of the contract a client's retry logic reads — collapsing
them into a generic 500 tells an integration to alert a human when it should simply wait.

Scoped to `api/v1/*` and **not** `api/*` on purpose: `/api/sync/*` and `/api/subdomain-available`
are older surfaces with their own `{"message": …}` contract, and re-shaping their errors would
break the desktop client.

**A distinct `key_revoked` code is safe** even though the other auth failures are generic: only
someone holding the genuine secret can reach that line, so it leaks nothing — and it is the one
auth failure a customer can act on.

---

## CORS — inherited, not introduced (know this before 16B)

`/api/v1/*` responses carry `Access-Control-Allow-Origin: *`. That is **Laravel's framework
default**, not 16A: `HandleCors` is in the global stack with `cors.paths = ['api/*']` and
`allowed_origins = ['*']`, and it has applied to `/api/sync/*` since 7C. `config/cors.php` is not
even published.

It is **not** a vulnerability here, for a specific reason: `supports_credentials` is `false`, and
API auth is an explicit `Authorization` header that a browser **never attaches automatically**. So
a malicious page cannot make an authenticated call on a customer's behalf the way it could against
a cookie-authenticated endpoint — and `ACAO: *` is the correct posture for an integration API that
authenticates by bearer token. (It also cannot expose the cookie-authenticated sync endpoints:
browsers reject `ACAO: *` outright on a credentialed request.)

The real risk it *enables* is a customer putting a key in browser-side JavaScript, where any
visitor can read it. That is a documentation problem, not a code one — the docs stub tells
integrators the key is a server-side secret. Revisit if 16E ever offers a browser SDK.

## Request logging

`api_request_log`, per-tenant, append-only (`created_at` only), mirroring the central audit logs.

Stores method, path, query string, status, `duration_ms`, ip, user agent, the ULID request id —
and a **SHA-256 of the request body, never the body**. Bodies are PII and grow without bound; the
digest still answers the only question support asks of them ("was this the same payload?"). An
empty body hashes to `null` rather than to the constant digest of `""`.

**The query string is redacted before it is stored.** Anything matching `zb_(live|test)_<32>` is
replaced with `zb_live_[REDACTED]`; the rest of the query survives. The API only ever reads a
bearer header, so a key in a URL never authenticates — but customers put secrets in URLs, and
without this the first one to try `?api_key=…` would have their live key persisted in plaintext,
carried into every DB backup, and rendered on the Activity screen. Redacting costs nothing (the
parameter was never read) and makes "no raw key in the log" unconditional rather than
true-only-if-customers-are-careful. §12 now *sends* a key in the query string and then asserts,
because the assertion previously passed only by never having tried.

**Failed-auth requests are absent by construction:** with no valid key there is no tenant, and
therefore no tenant database to write the row to. Capturing them would need a central log —
deliberately out of scope, and called out here rather than left as a surprise.

The write is wrapped in a catch-all: it happens *after* the customer already has their response,
so a logging fault must never surface as an error they cannot act on, nor mask a response that
already succeeded. It goes to the app log instead.

---

## The show-once modal

The single moment the key exists in readable form. Accidental dismissal costs a revoke-and-reissue
across every system that was about to hold it, so it is **hard to dismiss by accident**:

* No backdrop click-to-close. No Esc handler.
* The only close button is **disabled until the acknowledgement checkbox is ticked** ("I've saved
  this key somewhere safe — I understand it won't be shown again").
* A copy button with a fallback: `navigator.clipboard` needs a secure context, so it degrades to a
  selection copy over plain http on a local install.
* The raw key lives in one Livewire property and is **cleared the moment the modal is dismissed**,
  so it stops riding in the snapshot.

**UI constraint worth knowing before touching this screen:** the Settings layout
(`components.layouts.plain`) loads **no Tabler, no Bootstrap, no Alpine and no Vite bundle** — it
inlines its own `<style>`. Tabler classes (`card`, `btn-primary`, `badge`, `modal`) render
**unstyled** there, and the codebase's `zb-modal`/Alpine pattern is equally unavailable. So modals
here are Livewire-state driven with local styles, and the clipboard button is a self-contained
IIFE modelled on that layout's own password eye-toggle. There was no copy-to-clipboard pattern
anywhere in the codebase to copy.

The admin gate is enforced in `mount()` **and re-checked in every write action** — a Livewire
action is a fresh request that re-hydrates the component without re-running `mount()`, so a
mount-only gate would leave create/revoke reachable.

---

## Verification

```
php artisan zerobook:prove-api-foundation          # 32nd proof, 130 assertions
```

**This proof dispatches real HTTP requests, and that is the point.**

No prior `prove-*` command has ever executed a middleware stack — the one HTTP-ish precedent
builds a `Request` and hands it straight to a controller, bypassing the router and every
middleware. 16A is a **middleware phase**: authentication, isolation, company scoping, scope
enforcement, rate limiting and logging *all* live in middleware. Copying that precedent would call
`PingController` directly, assert 200, and prove **nothing** — a completely unregistered chain
would still show ALL PASS.

So it drives `app(HttpKernel::class)->handle(Request::create('/api/v1/ping', …))`: no web server,
no phpunit, the same router, the same sorted middleware, the same responses production runs.
`prove-api-foundation` is the first proof in the project to do this.

| § | Covers |
|---|---|
| 1–2 | Provisioning; issuance, hashing, `password_verify`, raw key absent from every column |
| 3–4 | Ping 200 + headers; the generic-401 family, **byte-identical** bodies across 4 failure modes |
| 5 | Constant-time — **cold-process** timing, bounded in both directions; dummy digest cached |
| 6 | Revocation + expiry, soft delete |
| 7 | **Cross-tenant isolation**, including interleaved A/B/A/B/A and log placement |
| 8 | Company scoping + 6 header-manipulation probes (`1abc`, `01`, ` 2`, `1 or 1=1`, `-1`, `999`) |
| 9 | Suspended → 403; **`expired_subscription` → 403**; reactivate → 200 |
| 10 | Scope truth table; middleware denies; **undeclared route fails closed**; every `/api/v1/*` route both enforces *and* declares |
| 11 | 60 pass / 61st 429 + `Retry-After`; reset; tenant-namespaced key |
| 12 | Raw key absent from app log, request log, and the file cache — **with a positive control** |
| 13 | Request log fields; no body stored |
| 14 | Route registration; **sorted** middleware order; ping's scope declared explicitly |
| 14b | Errors *outside* the chain: 404/405 carry `X-Request-Id`; **maintenance stays 503 + `Retry-After`**; 5xx error_id really is logged; sync surface not reshaped |
| 15 | Owner-only gate: `member`/`accountant` blocked, `owner` allowed |

§12 carries a **positive control** on purpose: every check there is a "not found" assertion, and a
broken detector (wrong path, empty file, regex that never matches) would report clean while a key
leaked in plain sight. The control proves the detector catches a planted secret *before* the
absence assertions are trusted.

### Verified beyond the proof

* **Through real Apache**, not just the kernel: `200` with correct JSON + `X-RateLimit-*` +
  `X-Request-Id`; `Authorization: Basic <key>` → 401; `?api_key=<key>` → 401 (bearer only); docs
  page 200.
* **Through a real logged-in session** (cookie jar, real CSRF, real login POST): the API Keys
  screen renders with the key **masked** (`zb_live_k5svws21•••••••••`), Livewire runtime injected,
  and **zero** occurrences of a raw key or a bcrypt hash in the HTML. `member` → **403 "Only an
  account owner"**; `owner` → 200.
* **Through the real Livewire component**: create → modal renders the key **exactly once** →
  dismiss clears it from state and HTML; empty permissions rejected; "selected companies" with
  none chosen rejected (it must **not** silently mean "all"); revoke blocked on a wrong typed name,
  succeeds on the right one.

**Not verified — stated plainly:** live browser JS console errors. Both browser surfaces in this
environment refuse top-level navigation to the local `.test` vhost (Chrome's `fetch` reaches it and
returns 401, so it is an environment policy, not the app). The modal's JS is a self-contained IIFE
of the same shape as the layout's existing eye-toggle, and its markup is asserted server-side —
but the "no console errors" criterion rests on inspection, not observation. **Worth one manual
click-through before handover.**

---

## Scope notes — what 16A deliberately does not do

* **OAuth 2.0 — deferred.** API keys are the auth model. Nothing here forecloses it; a future
  guard can resolve a token to the same `ApiKey` row and the whole chain downstream is unchanged.
* **IP allowlist per key — deferred.** A real control for stricter customers. The schema has no
  column for it; adding one later is additive.
* **Staging/production key separation — deferred.** The format reserves `zb_test_`, and
  `ApiKeyService` accepts and parses it, but **the UI only issues `zb_live_`**: there is no
  "test tenant" concept in the product to attach it to, and inventing one is a DevOps feature, not
  this phase. The parsing support means adding it later needs no key-format migration.
* **Failed-auth telemetry — deferred.** A 401 has no tenant, so it cannot be written to a
  per-tenant log (see *Request logging*). Needs a central table.
* **IP-level pre-limit — deferred.** See *Rate limiting*; the bcrypt verify is reachable
  unauthenticated.
* **Business endpoints, webhooks, event ingestion, OpenAPI** — 16B / 16C / 16D / 16E.
* **Automatic key-rotation reminders — deferred.**

### Local dev state touched during verification

`owner@demo.test` on the local `demo` tenant had its password set to a known value and was marked
verified, so the UI could be driven through a real login. Local dev DB only — production untouched.
The `member@demo.test` account created for the gate test has been removed, as have all probe keys.

---

## Files

**Schema** — `database/migrations/2026_07_27_000001_create_api_key_directory.php` (central) ·
`database/migrations/tenant/2026_07_27_000001_add_api_foundation.php` ·
`_docs/phase16a_central_schema.sql` · `_docs/phase16a_tenant_schema.sql`

**Middleware** — `IdentifyTenantByApiKey` · `EnforceApiPermissions` · `RateLimitByApiKey` ·
`LogApiRequest` · `RequireActiveTenant` (API branch added)

**Service / support** — `App\Services\Api\ApiKeyService` · `App\Support\ApiScopes` ·
`App\Support\ApiError`

**Models** — `ApiKey` · `ApiRequestLog` (tenant) · `ApiKeyDirectory` (central)

**HTTP** — `routes/api.php` · `App\Http\Controllers\Api\V1\PingController`

**UI** — `App\Livewire\ApiKeysList` · `App\Livewire\ApiKeyActivity` + views ·
`resources/views/docs/api.blade.php` (public stub at `/docs/api`)

**Wiring** — `bootstrap/app.php` (`api:` route entry, 2 priority-list calls, envelope renderer) ·
`routes/tenant.php` · `routes/web.php` · `config/zerobook.php` · `deploy.sh` (tenants:migrate)

**Proof** — `App\Console\Commands\ProveApiFoundationCommand`

---

## For 16B

1. Mount routes in the existing group in `routes/api.php` and **declare a scope on every one** with
   `->defaults(EnforceApiPermissions::SCOPE_DEFAULT, 'voucher:create')`. Enforcement is
   group-attached, so you cannot skip it — but an undeclared route 403s by design, and §10 fails
   the proof if any `/api/v1/*` route lacks a declaration. Do **not** re-attach the middleware
   per-route.
2. Any route with a `{model}` binding is already safe: `IdentifyTenantByApiKey` is prioritised
   ahead of `SubstituteBindings`.
3. `ActiveCompany` is pinned before your handler; `BelongsToCompany` scopes automatically. Do not
   re-resolve the company.
4. Remember **reads fail open** if anything ever unsets the active company. Do not write code that
   clears it mid-request.
5. Idempotency (`Idempotency-Key`) is **not** implemented. Nothing in 16A reads or reserves that
   header, and the request log stores a body **hash** — which is a usable dedup fingerprint if you
   want one.
6. Throw normally: `ValidationException` → 422 with details, `abort(404)` → `not_found`, anything
   unhandled → a generic 500 + `error_id`. Do not hand-build error bodies; use `ApiError`.
