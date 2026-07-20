# Phase 14A — SaaS operations: self-signup + platform-admin surface

ZeroBook's front door. Until now a tenant was provisioned by an artisan command only you
could run. 14A turns that into a public web signup a prospective customer completes
themselves, and gives you a platform-admin console to see and manage every tenant —
including impersonation for support. No billing (14B), no backups/export (14C).

---

## Post-delivery adversarial review (3 real defects found + fixed)

A 7-dimension adversarial review (each candidate finding independently refuted by 2 verifiers;
`prove-signup` grew 69 → **89** assertions locking the fixes) surfaced three CONFIRMED defects,
all fixed:

1. **HIGH — write-block bypass via quick-create + master-workspace Livewire actions.** The write
   block was enforced only at `VoucherScreen::post()/cancelVoucher()`, but ~40 other write
   actions (Alt+C quick-create ledger/group/stock-item, every master workspace save/alter/delete,
   F11, currency, company-group, forex revaluation) had no gate — so a **view-only impersonator**
   or a suspended/expired tenant could still create master data. Fixed with a single global
   **`App\Livewire\Support\TenantWriteGuard`** Livewire `ComponentHook` that refuses the enumerated
   write actions when `TenantGate::writable()` is false, closing the whole class in one place.
   **Registration gotcha (found while browser-verifying):** the hook must be registered in
   `AppServiceProvider::register()`, not `boot()` — Livewire's `ComponentHookRegistry::boot()` runs
   during the package's `boot()` (before the app's) and snapshots the registered hooks to wire
   their mount/hydrate listeners, so a hook registered in the app's `boot()` never attaches. Live-
   verified: suspended tenant's `saveQuickLedger` creates no row; active tenant's does (no
   regression); reads are never gated (the hook short-circuits on the method-name check).

2. **MEDIUM — verification-email failure left an orphaned, unrecoverable tenant.** The email is
   sent synchronously *after* `provisionForSignup()` commits, so a mailer failure escaped the
   rollback: the tenant/DB/admin persisted, the subdomain was burned, and the user was wrongly told
   "already taken." Fixed in `SignupService::signup` — the send is wrapped so a failure tears the
   tenant down and rethrows (invariant 2 restored: a user-visible failure frees the subdomain).
   Verified: a failing SMTP transport → tenant fully absent (row + DB dropped).

3. **MEDIUM — a valid 59-63-char subdomain produced a >64-char MySQL DB name that always failed.**
   The tenant DB is `tenant<slug>`; a 63-char slug → 69-char identifier > MySQL's 64 limit, so
   `CreateDatabase` always threw for that length class. Fixed with `Subdomain::maxLength()` =
   `64 − strlen(prefix) − strlen(suffix)` (58 for the default `tenant` prefix), enforced by the
   availability check and the signup validation.

Correctly **refuted** (not real): a same-slug concurrent-signup race (the `Tenant::create` is
outside the rollback try, so it can't tear down the winner), a route-cache closure abort (Laravel
13 serializes closures), and Host-header injection in `EnsureAdminHost` (the Host isn't
attacker-aimable at a victim).

---

## Step 0 — audit result

Before writing any 14A code, all **24 prior `prove-*` commands were re-run and pass**:

```
prove-balance, prove-sales-purchase, prove-gst, prove-vat, prove-billwise,
prove-costcentre, prove-item-invoice, prove-stock-journal, prove-inventory-integration
        → run against a tenant DB:  DB_DATABASE=tenant<slug> php artisan zerobook:prove-*
prove-tally-import                  → php artisan zerobook:prove-tally-import --tenant=<slug>
prove-notes, prove-sync, prove-order-flow, prove-tds, prove-26q, prove-forex,
prove-intercompany, prove-inter-company-lots, prove-consolidation, prove-gstr1,
prove-gstr3b, prove-nepal-vat-return, prove-multi-company, prove-multi-tenant
        → self-provision throwaway tenants; run directly.
```

**After 14A: 25/25 green** (24 prior + `prove-signup`, which is **69/69** assertions).

The existing `Services\Tenancy\TenantProvisioner` was already shared by the CLI command and
the admin form (7B did the "extract" the deliverable calls for), so 14A **extends** it rather
than rebuilding it: adds country→regime, an admin-user step, a trial window, a
`pending_verification` status, and — new — an explicit rollback path.

### A latent bug the audit's rollback path surfaced (fixed)

`BelongsToCompany` (Phase 12A) memoised, positive-only and keyed by `db.table`, whether the
`company_id` column has appeared yet — so the pre-12A migration window (which seeds
`tds_sections` / currencies before that column exists) stays inert and switches on the moment
the column arrives. That memo assumed a database's schema only ever moves **forward**. A
self-signup that **rolls back** (drops the DB) and is then **retried on the same subdomain in
the same process** — or the same PHP-FPM worker reused across a failed signup and its retry —
violated that: the recreated DB inherited the stale "column present" verdict and its early TDS
migration tried to stamp `company_id` before the column existed, failing closed. Fixed by
moving the memo into `App\Support\CompanySchemaMemo` (shared, keyed by `db.table`) and having
`TenantProvisioner::teardown()` **forget the dropped database**, so a retry re-checks the
(absent) column. Behaviour is otherwise identical; all 24 proofs still pass.

---

## The signup flow (country / regime)

Public, on the central domain (`zerobook.in` / `www.` / `localhost` dev):

1. **`GET /`** — honest one-page landing + "Start free trial" CTA.
2. **`GET /signup`** — one form: company name, desired subdomain, country, admin name/email/
   password. Standard web patterns (Enter submits, Tab advances, **no F-keys**).
   - **Live subdomain check** — `GET /api/subdomain-available?slug=…` returns
     `{available, reason}` as you type. Reserved words (`admin`, `www`, `api`, `mail`, `docs`,
     `blog`, `app`, `status`, `support`, and a fuller infra/product list in
     `config/zerobook.php`) are always unavailable; taken slugs are rejected too.
   - **Country → regime** — India (GST regime, ₹ INR base) or Nepal (VAT regime, रू NPR base).
     Two options this phase; extensible via `config('zerobook.countries')`.
3. **`POST /signup`** provisions the tenant **transactionally** (see below) and emails a
   verification link, then shows **`/signup/check-email`**.
4. **`GET /verify/{id}/{hash}`** (temporary signed URL) marks the email verified, flips the
   tenant `pending_verification → active`, and hands off to the tenant subdomain **already
   logged in** (a second short signed URL → `/_auth/consume` on `<slug>.<domain>`).

Until the email is confirmed the admin **cannot log in** (`TenantAuthController` blocks an
unverified user whose password is otherwise correct).

### Provisioning (idempotent, transactional, reversible)

`TenantProvisioner::provisionForSignup()` runs, in order:

1. central `tenants` row → status `provisioning`, plan `trial`, `trial_ends_at = now + 30d`;
2. create the tenant MySQL database;
3. run tenant migrations;
4. seed (28 groups, Cash + P&L A/c, Main Location, GST/VAT duty ledgers);
5. set the default company's **regime + base currency** from the country choice;
6. create the admin owner in `tenant_users` (unverified);
7. status → `pending_verification`; then the caller sends the verification email.

**On ANY failure** the whole thing rolls back — `teardown()` drops the tenant DB (if created)
and deletes the central row (cascading the domain + the half-created admin user) — so the
tenant is **fully absent** and the subdomain is free to retry. The CLI path
(`zerobook:tenant-provision`, now with `--country`) keeps its idempotent **repair-forward**
behaviour, which is correct for an operator who can re-run it.

---

## Platform-admin surface (`admin.<central-domain>`)

Served on the reserved **`admin.`** subdomain (added to `central_domains`; `EnsureAdminHost`
pins the `/admin/*` routes there so the platform session is scoped to it; `RootController`
routes `/` by host: tenant → gateway, admin → console, else → landing).

- **Login** (`platform_admins` central table, separate from tenant login).
- **Tenants list** — every tenant with subdomain, plan, status, provisioned date, admin email,
  companies-in-tenant, last-active. Filterable by status.
- **Tenant detail** — status/plan/admin/trial + an **activity summary that is COUNTS ONLY**
  (vouchers this month, companies, users, database size, last active). No voucher amounts, no
  ledger names — the operator gauges usage without seeing a customer's books.
- **Actions** (each writes a `platform_admin_actions` audit row):
  - **Suspend** → status `suspended` (soft-block writes, reads still work).
  - **Reactivate** → back to `active`.
  - **Extend trial** → adds N days to `trial_ends_at` (re-activates an expired trial).
  - **Change plan** (manual, pre-billing) → e.g. `trial → paid-monthly`; a paid plan clears the
    trial window.
  - **Impersonate** → support session (below).
- **Manual provision** — for onboarding a pilot yourself (customers self-serve at `/signup`).

### Impersonation safety

Start records the intent (`impersonate_start`, with the admin's identity + **required reason**)
and mints a **short-lived signed URL** to the tenant's `/_impersonate/consume`; the browser
follows it onto the tenant subdomain, where the tenancy middleware switches to the tenant DB and
the admin is logged in **as the tenant's owner** — an ordinary tenant-scoped session, so **cross-
tenant isolation holds by construction** (an impersonated admin in tenant A cannot reach tenant
B's data). A **persistent banner** on every tenant screen reads *"PLATFORM ADMIN IMPERSONATING
&lt;tenant&gt; — &lt;admin email&gt;"* with a status pill and an **Exit impersonation** button.

Writes are **view-only by default**; an explicit "Enable writes" toggle in the banner turns them
on (each flip logged `impersonate_write_on/off`). Exit logs `impersonate_end`, ends the session,
and returns to the admin surface. The whole session is auditable in `platform_admin_actions`.

---

## Suspend / expire enforcement (the write-block)

`App\Support\TenantGate` is the single authority on whether the current tenant request may
**write**. Blocked when the tenant is `suspended` / `expired_trial`, **or** when the request is a
view-only impersonation session. Reads are always allowed.

- **Interactive path** — `VoucherScreen::post()` and `cancelVoucher()` call
  `TenantGate::assertWritable()` before any validation, so a blocked tenant gets a clear error and
  nothing is persisted. (The gate lives at the voucher chokepoint, not as a blanket block on the
  Livewire update endpoint, because that endpoint carries reads too.)
- **Plain-HTTP path** — the `RequireActiveTenant` middleware blocks non-GET tenant routes (chiefly
  the desktop sync push) when not writable, whitelisting auth/nav (login, logout, company switch,
  impersonation hand-offs).

`TrackTenantActivity` touches `last_active_at` (throttled, one conditional UPDATE per minute);
`LogImpersonation` shares the banner context to every tenant view.

### Trial mechanism

New signups land on the **`trial` plan** (all features unlocked) with `trial_ends_at = now + 30d`
(`config('zerobook.trial_days')`). **`zerobook:trial-check`** runs **daily** (scheduled in
`routes/console.php`) and flips lapsed active trials to `expired_trial` (read-only). Extend or
convert to a paid plan to restore writes.

### Audit log

`platform_admin_actions` (central): `admin_id, action, tenant_id, target_user_id, reason, meta,
ip_address, user_agent, created_at`. Actions: `provision, impersonate_start,
impersonate_write_on, impersonate_write_off, impersonate_end, suspend, reactivate, extend_trial,
plan_change`.

---

## Rate limiting

- `POST /signup` → **5 / IP / hour** (`throttle:zerobook-signup`).
- `GET /api/subdomain-available` → **60 / IP / minute** (`throttle:zerobook-subdomain`).

Limiters registered in `AppServiceProvider`.

---

## Acceptance checklist → where it's proven

`php artisan zerobook:prove-signup` (69/69). Each row asserts against fresh throwaway tenants:

| Criterion | Proven by |
|---|---|
| Signup happy path (tenant + email + verify + login + 28 groups/Cash/P&L/Main Location/GST ledgers) | §1 |
| Country → regime (India GST/INR **and** Nepal VAT/NPR + duty ledgers) | §1, §2 |
| Subdomain reservations rejected (`admin`, `www`, `api`, …) | §3 |
| Subdomain collision rejected | §4 |
| **Failed provisioning rollback** — DB dropped, central row deleted, admin not persisted, same subdomain succeeds again | §5 |
| Availability endpoint correct + rate-limited | §6 |
| Platform-admin login + tenants list + status filter | §7 |
| Impersonation — view-only, write-toggle, full audit trail, isolation | §8 |
| **Trial expiry** → `expired_trial`, can still read but **cannot post a voucher** | §9 |
| Suspend blocks writes; reactivate restores them | §10 |
| Extend trial delays `trial_ends_at` | §11 |
| Manual plan change (`trial → paid-monthly`), logged | §12 |
| Cross-tenant isolation | §13 |

**Browser-verified end-to-end** on `localhost:8777` / `admin.localhost:8777` /
`<slug>.localhost:8777`: signup form + live subdomain check (reserved→"reserved",
free→"available"), full signup → provisioned tenant (GST/INR, 28 groups) → verification link →
cross-domain auto-login → Gateway; platform login → tenants list → detail (counts-only) →
impersonation banner (VIEW-ONLY → WRITE ENABLED → exit) → audit log. **0 console errors.**
A real self-signup demo tenant is left provisioned: **`brightmart`**
(`priya@brightmart.test` / `secret123`, on trial). A found-and-fixed bug: impersonation Exit
built `admin.<slug>.<base>` instead of `admin.<base>` — the base domain behind a tenant host is
behind the *slug* label (`TenantUrl::baseDomainWithoutTenant`).

---

## Files

**Schema (central) — migrations + `_docs/phase14a_central_schema.sql`:**
- `2019_09_15_000007_add_trial_and_paid_plans.php` — `trial` + `paid-monthly` plans.
- `2019_09_15_000050_extend_tenants_for_saas.php` — `trial_ends_at`, `verified_at`, `last_active_at`.
- `2019_09_15_000060_add_verified_at_to_tenant_users.php`.
- `2019_09_15_000070_create_platform_admin_actions_table.php`.
- `App\Models\Tenant` (custom columns + casts + status helpers), `TenantUser` (MustVerifyEmail +
  Notifiable), `PlatformAdminAction`. **No tenant-DB migration.**

**Provisioning (E):** `Services\Tenancy\TenantProvisioner` (extended, + rollback), `SignupService`,
`Support\Subdomain`, `Support\CompanySchemaMemo`.

**Public signup (B):** `Central\SignupController`, `Notifications\VerifyTenantEmail`, views
`central/{signup,check-email,landing}.blade.php`, `Tenant\AuthConsumeController`.

**Platform admin (C):** `Central\PlatformController` (rewritten), `Central\PlatformAuthController`,
`Services\Platform\{PlatformActions,ImpersonationService}`, `Tenant\ImpersonationController`,
`Support\TenantUrl`, views `central/admin/{login,tenants,tenant-detail}.blade.php`, impersonation
banner in `layouts/app.blade.php`, `RootController` host branch.

**Middleware & scheduling (D):** `Http\Middleware\{RequireActiveTenant,TrackTenantActivity,
LogImpersonation,EnsureAdminHost}`, `Support\TenantGate`, `Livewire\Support\TenantWriteGuard`
(global write-block hook), `VoucherScreen::post/cancelVoucher` gate,
`Console\Commands\TrialCheckCommand` + daily schedule in `routes/console.php`.

**Config:** `config/zerobook.php` (trial days, reserved subdomains, countries),
`config/tenancy.php` (`admin.*` / `www.*` central domains), `config/auth.php` (`verification.expire`).

**Proof:** `Console\Commands\ProveSignupCommand`.

Existing environments: `php artisan migrate` (central migrations) — no tenant migration.

---

## Scope — explicitly NOT in 14A (honest notes)

- **Billing** — Phase 14B (Razorpay / Khalti / Stripe, subscription lifecycle). No payment is
  collected here; `paid-monthly` is a manual, admin-set label until then, and both `trial` and
  `paid-monthly` currently unlock every feature — 14B introduces real per-tier gating + pricing.
- **Automated backups / restore** — Phase 14C.
- **Tenant data export / offboarding** — Phase 14C.
- **Multi-user tenant onboarding** (invite more users) — the tenant admin already does this in
  the app's user management.
- **Custom password-reset** — Laravel's built-in flow suffices.
- **Full marketing site** (features/pricing/blog) — an honest one-page pitch is enough for pilots.
- **SSO / Google login** — later.
