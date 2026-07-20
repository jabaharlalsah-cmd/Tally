# ZeroBook — Phase 7B: Multi-Tenant SaaS Packaging

**Goal:** turn one ZeroBook install into a subscription business — one deployment
serving many customers ("tenants"), each with their **own fully-isolated database**.
**Every correctness invariant from Phases 1–7A still holds, per-tenant** — the Trial
Balance balances, GST/VAT authority runs, weighted-average COGS locks correctly, the
Tally importer works — proven in each tenant, with zero cross-tenant leakage.

Isolation model: **`stancl/tenancy` v3.10, database-per-tenant** — each tenant is a
separate MySQL database, so a bug or exploit in one tenant's session cannot reach
another tenant's books. The **database connection is the isolation boundary**; the
accounting services were not changed at all.

```bash
php artisan zerobook:tenant-provision acme "Acme Traders" professional  # create a tenant
php artisan zerobook:prove-multi-tenant                                  # the isolation proof
```

---

## Step 0 — audit + the tenant boundary

**All ten prior `prove-*` commands PASS** (re-run as the 7B baseline; they now run
inside a tenant context — see below). **`stancl/tenancy v3.10.0` supports Laravel 13**
(`^10|^11|^12|^13`) — verified before installing.

**The tenant boundary.** In single-tenant ZeroBook the whole database *was* the
company. Under 7B, **one tenant = one company = one database**. `Shell::config()`,
`Shell::gstConfig()`, `Shell::vatConfig()` and `CompanyFeature` (the company profile —
there is no `CompanySetting` model) all read the default connection, so they become
per-tenant automatically once the connection is switched. No change was needed to any
of them.

### Central vs tenant split (as implemented)

| CENTRAL db (`zerobook_central`) | Each TENANT db (`tenant<slug>`) |
|---|---|
| `plans` (tier → F11-feature matrix, seeded) | **everything accounting**: `account_groups`, `ledgers`, `vouchers`, `voucher_entries`, `stock_entries`, `bill_allocations`, `cost_allocations`, `cost_centres`, `company_features`, `units`, `stock_groups`, `godowns`, `stock_items`, … |
| `tenants` (id=slug, name, plan_id, status, provisioned_at) | plus the framework tables (`users`, `sessions`, `cache`, `jobs`) |
| `domains` (subdomain → tenant) | |
| `tenant_users` (login identities, keyed tenant_id+email) | |
| `platform_admins` (ZeroBook operators) | |

The central DB holds **no accounting data** — the accounting tables don't even *exist*
there (proven). The legacy single-tenant `tally` database is **left untouched** and can
be adopted as a tenant later.

---

## Migration restructuring

All **22** app migrations moved to **`database/migrations/tenant/`** (run against a new
tenant's DB at provisioning). The **5 central** migrations stay in
`database/migrations/`: `plans`, `tenants` (extended with real columns), `domains`,
`tenant_users`, `platform_admins`. `php artisan migrate` runs **only** the central set
(it does not recurse into `tenant/`); `tenants:migrate` (via the provisioner) runs the
tenant set. No existing migration had a hard-coded database name or a cross-database FK.

---

## Tenancy wiring (how the connection switches)

* **`config/tenancy.php`** — `tenant_model` = `App\Models\Tenant`; bootstrappers trimmed
  to **only `DatabaseTenancyBootstrapper`**; `central_domains` = `zerobook.in`,
  `zerobook.local`, `localhost`, `127.0.0.1`; tenant migrations at
  `database/migrations/tenant`.
* **`App\Models\Tenant`** — `HasDatabase`, `HasDomains`, `TenantWithDatabase`; real
  columns via `getCustomColumns()`; **id = the subdomain slug** (passed explicitly, so
  the tenant DB is `tenant<slug>`, e.g. `tenantalpha`).
* **Central models pin the central connection** (`App\Models\Concerns\UsesCentralConnection`
  → resolves `tenancy.database.central_connection`) so a `Plan`/`TenantUser` lookup made
  *while a tenant is active* still hits the central DB, never the tenant's — a critical
  leak guard. (stancl's `Tenant`/`Domain` already pin central.)
* **Middleware** — tenant web routes (`routes/tenant.php`) run through
  `InitializeTenancyBySubdomain` + `PreventAccessFromCentralDomains`: the subdomain is
  read from the `Host` header, resolved to a tenant via `domains`, and the **default DB
  connection is switched to that tenant's database before any controller or Livewire
  component runs**. Central routes (`routes/web.php`) have no tenancy middleware and never
  touch a tenant DB.
* **Routing** — the only path both sides want is `/`. It's owned by `RootController`
  (landing on a central domain; redirect to the gateway on a tenant subdomain); the
  tenant gateway itself is at **`/app`**, and every other accounting path
  (`/masters`, `/vouchers`, …) is tenant-only — so the two route sets never collide.

De-risking decisions (documented in the code): **session/cache/queue moved to
`file`/`sync`** so those subsystems don't couple to the switched tenant connection
(the `database` drivers would otherwise write to whichever tenant's DB is active, and
the cache bootstrapper needs a taggable store the `file` driver isn't). The isolation
surface stays purely about accounting data.

---

## Provisioning

`php artisan zerobook:tenant-provision {subdomain} {name?} {plan=starter}` (and the
platform-admin form) run `App\Services\Tenancy\TenantProvisioner`, which is
**idempotent and recoverable** — each step is independently guarded, so re-running
completes a provision that failed halfway (never double-seeds):

1. central `tenants` row (on the slug PK),
2. `domains` row (the subdomain label),
3. create the tenant database **if it doesn't exist**,
4. migrate the full tenant set (Laravel skips applied migrations),
5. seed **once** (28 predefined groups, Cash + Profit & Loss A/c, Main Location godown,
   GST/VAT duty ledgers) — gated on an internal flag.

A new tenant starts life with a working, seeded, correct chart of accounts.

---

## F11 as a plan gate

`App\Support\PlanGate` reads the current tenant's plan (central DB) and decides which
F11 features **may** be switched on. The F11 screen disables plan-locked toggles and
shows the plan + an "upgrade to unlock…" banner — **UX only**. The **security boundary**
is server-side: `FeaturesScreen::save()` calls `PlanGate::violation()` and **rejects**
enabling any locked feature (and never persists it), whatever a crafted request sends.
The tier→feature matrix lives in `plans.features` (JSON), reconfigurable without a code
change. Seeded tiers:

| Feature | Starter | Professional | Enterprise |
|---|:--:|:--:|:--:|
| GST · VAT · Bill-wise | ✓ | ✓ | ✓ |
| Cost Centres | — | ✓ | ✓ |
| Multi-Currency | — | — | ✓ |

---

## The Tally importer, per-tenant

`zerobook:tally-import` and `zerobook:prove-tally-import` take **`--tenant=<subdomain>`**
and run inside that tenant's database. Without it (and outside a tenant context) they
**REFUSE** to run — an import must never touch the central database:

```bash
php artisan zerobook:tally-import company.xml --tenant=acme --dry-run
php artisan zerobook:tally-import company.xml --tenant=acme          # commits into acme's DB only
```

---

## The proof — `zerobook:prove-multi-tenant`

Provisions two fresh tenants (`alpha`/starter, `beta`/professional) and asserts every
acceptance item, then tears them down. **All pass:**

```
Running all 10 prove-* commands inside each tenant
  [PASS] [alpha] prove-balance … prove-tally-import   (all 10)
  [PASS] [beta ] prove-balance … prove-tally-import   (all 10)     ← every 1–7A invariant, per-tenant

Cross-tenant isolation
  [PASS] alpha sees its OWN marker · beta sees its OWN marker
  [PASS] alpha CANNOT see beta's data · beta CANNOT see alpha's data
  [PASS] Cross-read of tenant data via central connection is REJECTED

Central database is clean of accounting data
  [PASS] central has NO ledgers / vouchers / voucher_entries / stock_entries / account_groups table
  [PASS] central DOES have the tenants registry

Distinct databases + plans
  [PASS] alpha DB = tenantalpha · beta DB = tenantbeta · databases differ · plans differ

Interactive path (create ledger → post Payment → Day Book)
  [PASS] Payment posts + shows in alpha Day Book at 1,500

F11 plan gate
  [PASS] [alpha/starter] Cost Centres LOCKED · F11 save REJECTS it · does NOT persist
  [PASS] [beta/professional] Cost Centres allowed · Multi-Currency LOCKED
```

**HTTP layer** (verified over the running server with `Host` headers — no DNS needed):

```
central  Host: localhost           /            → 200 landing
central  Host: localhost           /admin/login → 200 · login → /admin dashboard 200
central  Host: localhost           /app,/masters→ 404 (blocked from central)
tenant   Host: alpha.zerobook.local /health/tenant → {"tenant":"alpha","database":"tenantalpha","ledger_count":10}
tenant   Host: alpha.zerobook.local /            → 302 /app → (guest) 302 /login
tenant   Host: alpha.zerobook.local /login       → 200 "Alpha Books" · POST creds → 302 /app · /app authed → 200 "Gateway of ZeroBook"
```

---

## Setup notes

**Local subdomains.** stancl reads the tenant from the `Host` header, so for a browser
you need `*.zerobook.local` to resolve to `127.0.0.1`. Either add hosts entries
(`C:\Windows\System32\drivers\etc\hosts`):

```
127.0.0.1  zerobook.local  alpha.zerobook.local  beta.zerobook.local
```

…or, since Laragon supports wildcard local domains, point `zerobook.local` at the app.
`localhost`, `127.0.0.1`, `zerobook.local` and `zerobook.in` are configured as central
domains. (For the automated proofs no DNS is needed — the CLI initializes tenancy
directly, and the HTTP checks pass the `Host` header explicitly.)

**Add a tenant.**
```bash
php artisan zerobook:tenant-provision demo "Demo Co" professional
php artisan zerobook:tenant-user demo owner@demo.test "Owner" --password=secret --role=owner
# then browse demo.zerobook.local/  →  login  →  the app
```

**Platform admin.**
```bash
php artisan zerobook:platform-admin ops@zerobook.in "Ops" --password=secret
# then browse localhost/admin/login
```

**Inspect a specific tenant's DB from the CLI** — run any command scoped to a tenant:
```bash
php artisan tenants:run "zerobook:prove-balance" --tenants=demo   # stancl's built-in
# or from tinker:
php artisan tinker
>>> App\Models\Tenant::find('demo')->run(fn () => App\Models\Voucher::count());
```

**Adopt the legacy `tally` DB as a tenant** (optional, later): register a tenant whose
database name is `tally` and add its domain — its existing books become that tenant's
data. (Its table set already matches the tenant migration set.)

---

## Scope NOT built in 7B (per the prompt)

Billing integration (plan tier is stored; a provider comes later), tenant self-signup
UI (artisan/admin provisioning is enough), cross-tenant reporting, tenant data
export/offboarding, and per-tenant backup/restore. A minimal but **functional** login
(tenant + platform, session-based, branded) is included — it is not a stub.

---

## Files delivered

```
config/tenancy.php                                  (configured: tenant model, 1 bootstrapper, central domains)
config/auth.php                                     (+ tenant & platform guards/providers)
bootstrap/providers.php                             (+ TenancyServiceProvider)
bootstrap/app.php                                   (host-aware guest redirects)
app/Providers/TenancyServiceProvider.php            (manual provisioning — auto-pipeline disabled)

app/Models/Tenant.php  Plan.php  TenantUser.php  PlatformAdmin.php
app/Models/Concerns/UsesCentralConnection.php
app/Services/Tenancy/TenantProvisioner.php
app/Support/PlanGate.php

app/Http/Controllers/RootController.php
app/Http/Controllers/Central/{PlatformAuthController,PlatformController}.php
app/Http/Controllers/Tenant/TenantAuthController.php
app/Console/Concerns/RunsInTenantContext.php
app/Console/Commands/{TenantProvisionCommand,ProveMultiTenantCommand,PlatformAdminCommand,TenantUserCommand}.php

routes/web.php (central)   routes/tenant.php (tenant subdomain)
resources/views/components/layouts/plain.blade.php
resources/views/central/{landing,platform-login,platform-dashboard}.blade.php
resources/views/tenant/login.blade.php

database/migrations/  2019_09_15_000005_create_plans_table … 000040_create_platform_admins_table  (5 central)
database/migrations/tenant/  (all 22 app migrations)
_docs/phase7b_central_schema.sql   (phpMyAdmin-importable central schema + seeded plans)

modified: app/Livewire/FeaturesScreen.php + view (plan gate); app/Console/Commands/{TallyImportCommand,ProveTallyImportCommand}.php (--tenant)
```

No stubs, no TODOs. The ten prior proves + `prove-multi-tenant` are all green.
