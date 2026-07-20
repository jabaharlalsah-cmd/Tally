# ZeroBook — Phase 12A: multi-company inside a tenant

**Status: done and proven.** `php artisan zerobook:prove-multi-company` → **59 assertions, 0 failures.**
Full cold regression: **21/21** prove-commands green (19 prior + `prove-multi-company` + `prove-multi-tenant`,
which itself re-runs the 10 legacy proofs inside two tenants). The phpMyAdmin script
`_docs/phase12a_schema.sql` was verified to reproduce the migration schema **byte-identically**
(provision → revert 12A via the migration's own `down()` → apply script → `SHOW CREATE TABLE` diff = 0
across all 26 tables). An adversarial multi-agent leak-hunt (6 lenses × 2-refuter verification) ran over
the finished build — results in "Adversarial review" below.

One tenant (one MySQL database — Phase 7B unchanged) now holds **N named companies**, each a fully
isolated set of books: its own chart of accounts, ledgers, vouchers, inventory, currencies, F11 profile,
filings. A CA firm managing 20 client books gets 20 isolated ledger sets under one login, switching with
**F1**. Everything still posts through the same `VoucherScreen::post()` — no parallel path.

---

## Step 0 — audit result

All 20 prior prove-commands were re-run before a line of 12A was written (the Phase 11 close-out run):
**20/20 green**. Nothing needed fixing.

---

## The exhaustive company-scoped table list (Step 0.2)

Verified against the live tenant DDL, 34 tables total. **25 gain `company_id`** (NOT NULL, FK →
`companies` ON DELETE CASCADE, backfilled to the default company by an explicit data-migration step):

| Tables | Unique-key change |
|---|---|
| `account_groups`, `ledgers`, `cost_centres`, `units`, `stock_groups`, `godowns`, `stock_items` | `unique(name)` → `unique(company_id, name)` — the same master names coexist per company |
| `vouchers` | **`unique(type, fy_start, number)` → `(company_id, type, fy_start, number)`** — per-company numbering. Without this, company B's voucher №N hits company A's row at the DB level while the scoped `nextNumber` re-derives the *same* number on every retry — posting would 500. Also `unique(client_uuid)` → `(company_id, client_uuid)` so the scoped sync dedupe and the index agree. Scan index `(company_id, date)` |
| `voucher_entries`, `bill_allocations`, `cost_allocations`, `stock_entries`, `order_lines`, `order_fulfillments`, `tds_deductions`, `tds_challans`, `tds_deductee_ytd`, `exchange_rates` | column + FK (+ scan indexes on the hottest: `(company_id, ledger_id)`, `(company_id, stock_item_id)`); their existing uniques are already company-safe via scoped FKs |
| `currencies` | `unique(code)` → `(company_id, code)` — **each company has its own base currency** (`is_base` is per-company; an Indian and a Nepali company coexist in one tenant) |
| `tds_sections` | `unique(code, effective_from)` → composite — the statutory rate table is a per-company **master** (the Tally model: every company owns its masters), 15 rows seeded per company |
| `gst_return_filings`, `vat_return_filings`, `tds_return_filings` | period/quarter uniques → composite — each company files its own returns |
| `company_features` | + `unique(company_id)` — **one F11 row per company**; the identity columns (`company_gstin/state/pan/tan`) **moved to `companies`** and were dropped |
| `sync_changes` | + `company_id` + `(company_id, entity, id)` index — the pull change-log is queried **raw** (scope-immune), and a `deleted` op is unresolvable to a company after the row is gone |

**No `company_id`:** Laravel infra (`migrations`, `cache*`, `sessions`, `jobs*`, `failed_jobs`,
`password_reset_tokens`) and `users` (legacy tenant scaffold — real auth is central `tenant_users`;
login identity is tenant-level, the active company is session state). **Central DB: zero changes** —
the tenant's plan (Phase 7B) gates every company's F11 identically.

### `companies` — the registry

`id, name (unique), slug (unique), state, gstin, pan, tan, base_currency_id (FK currencies, null),
financial_year_start_month (default 4), is_active, timestamps`. The identity quartet moved here from
the old single-row `company_features` (the Phase 5B `company_state`, 5B `company_gstin`, 5E/10B
`company_pan`, 10B `company_tan`); the F11 screen still edits them — it writes them to this row. The
26Q deductor address/responsible-person block stays on `company_features` (per company);
`CompanyFeature::gstProfile()/vatProfile()/deductorProfile()` keep their wire-format keys and read the
moved fields through the company row, so the client payloads and the 26Q exporter shape are unchanged.

---

## The `BelongsToCompany` pattern (Step 0.4)

One trait on all **24 scoped models**, mirroring how tenancy already isolates:

```php
static::addGlobalScope('company', function (Builder $q) {
    if (($id = ActiveCompany::id()) !== null && static::companyColumnExists($q->getModel())) {
        $q->where($q->getModel()->getTable().'.company_id', $id);   // table-qualified — join-safe
    }
});
static::creating(function (Model $m) {
    if ($m->getAttribute('company_id') !== null) return;            // explicit (runAs seeding)
    if (! static::companyColumnExists($m)) return;                  // pre-12A migration window
    $m->company_id = ActiveCompany::check();                        // FAIL CLOSED — never guess
});
```

- **Services did not change their query code.** `BalanceService`, `StockService`, `GstService`,
  `VatService`, `BillService`, `CostCentreService`, `ForexService`, `TdsService`, the importer and the
  sync engine all start their queries from scoped models; joins onto `vouchers`/`voucher_entries` ride
  the base row's own FK, which the creating-stamp guarantees never crosses companies.
- **Writes fail closed**: creating a scoped row with no active company throws (`ActiveCompany::check()`),
  rather than guessing a company.
- The `companyColumnExists` guard (positive-only memo, keyed by **database + table**) keeps the trait
  inert during the migration window — earlier migrations run seeders (TDS catalog, INR) before the 12A
  migration adds the columns, and one artisan process can provision several tenants in a row.
- `Company` itself is deliberately **unscoped** — it is the registry the picker and the Companies
  screen read.

### What the scope cannot see — the 8 fixes it forced

An Eloquent global scope only filters queries that *start from a scoped model*. The recon found and 12A
fixed every bypass:

1. **`exists:`/`unique:` validation rules** hit tables raw — all **48 call sites** (16 in
   `VoucherScreen`, 32 across the master workspaces + quick-create concerns) now carry
   `->where('company_id', ActiveCompany::check())`. A crafted payload naming another company's
   ledger/voucher/item/godown/centre/currency/section id is **rejected** (proven).
2. `GstService`/`VatService` `DB::table('account_groups')` name lookups → scoped `AccountGroup` model
   (seeded group names now exist once per company; the raw lookup would have resolved an arbitrary one).
3. `TdsService::remittedPaise` `DB::table('voucher_entries')` → scoped `VoucherEntry`.
4. `CompanyFeature::current()` (`first() ?? create`) → `firstOrCreate(['company_id' => active])` — all
   57 call sites inherit the re-keying unchanged.
5. `CurrencyMaster::makeBase` bulk update — scoped, and mirrors into `companies.base_currency_id`.
6. Sync: the change-log insert stamps **the model's** `company_id` (not the session's — it fires from
   imports and deletes); `SyncService::pull`'s raw query filters on it; the dedupe lookup (scoped) and
   the composite `(company_id, client_uuid)` unique agree.
7. Workspace delete-guards (`DB::table` existence checks) → scoped models, uniformly.
8. Voucher numbering — the composite unique above.

---

## Active-company session mechanism (Step 0.3)

- **`App\Support\ActiveCompany`** — process-level holder (deliberately not the HTTP session: CLI has
  none). `set/id/check/company/runAs`; helpers `activeCompanyId()` / `activeCompany()` (composer-autoloaded).
- **`SetActiveCompany` middleware** — on the tenant route group **and the Livewire update route** (the
  Phase 10A fix's stack). Resolution: session `active_company_id` if it names an existing *active*
  company → else the default company (lowest-id active, written back to the session — first login lands
  here) → else 503. Past this middleware a request always has exactly one active company — the sync
  API's snapshot/pull inherit it, fail-closed.
- **Switching** — `POST /company/switch` validates the company and writes the session; the client then
  does a **full-page redirect to the Gateway**. Every client cache (ZB_CONFIG, ZB_NAV, masters store,
  Livewire snapshots) is a page-load snapshot of the old company, so a fresh page is the only correct
  invalidation — and open voucher/report screens are discarded, exactly as Tally does it.
- **The stale-tab guard** — `GuardsActiveCompany`, a Livewire trait on all **30 components**: the active
  company id is stamped into the component at mount (checksummed into the snapshot) and every subsequent
  request **409s** if the session's company changed in another tab. Proven in `prove-multi-company`.
- **artisan** — `ResolvesActiveCompany` concern (mirrors `RunsInTenantContext`): `--company=<slug|id>`,
  or a **fresh throwaway company** for every prove-command (created inside the proof's transaction, so
  it rolls back with everything else — re-runs never collide and each green proof doubles as an
  isolation check), or the tenant's default company. The production importer and the four return
  exporters **refuse** to run without `--company=` — a return scoped to the wrong company is silent
  data corruption.

---

## The company picker — F1, and why not Alt+F1

The prompt suggested Tally's `Alt+F1`. **Verified collision**: the report context already binds
`alt+f1` as the Detailed/Condensed toggle (itself the Tally convention there), and the engine resolves
the active context *before* globals — a global Alt+F1 would be silently shadowed on Trial Balance,
Balance Sheet, P&L and Stock Summary, exactly where a CA lives. **`F1` is free** (bound to nothing,
already HARD_BLOCKed so the browser's help never fires, reliably preventable) — and F1 *is* Tally
ERP 9's own Select Company key. So: **F1, globally**, plus the clickable **top-bar company cell** and a
Go To entry.

The picker is a `zbGoto` clone (overlay, filter-by-typing, ↑/↓ + Enter, Esc pops the context); the
company list ships on `ZB_CONFIG` (zero network); it opens on the *current* company so Enter with no
movement is a no-op; Enter POSTs the switch and reloads. The top bar shows the **active company name**
(the hard-coded "ZeroBook Foundation Co." is gone — printed documents now carry the active company's
name and GSTIN/PAN too).

---

## Provisioning updates (Step 0.5)

- **Fresh tenant** — the 12A migration itself creates the default company (named after the tenant via
  `tenancy()`) and backfills the rows earlier migrations seeded (TDS catalog, INR, Main Location, the
  F11 row). `DatabaseSeeder` now runs `CompanySeeder` **first**: it pins the default company as active
  so every downstream seeder stamps its rows. `TenantProvisioner` itself is untouched (repair-run
  idempotency preserved).
- **Another company** — `zerobook:company-create {--tenant=} {--name=} {--slug=}` or the Companies
  screen → `CompanyProvisioner::create()`: the company row + `ActiveCompany::runAs` over the same seven
  seeders provisioning uses (28 groups, Cash + P&L, 9 duty ledgers, Sales/Purchase Return, 15 TDS
  sections, INR base, forex Gain/Loss) + the reserved Main Location + a fresh **all-off** F11 row —
  the CA configures each client book from zero.
- **Companies screen** (`/companies`, Gateway letter **Z** — the last free letter; the primary
  affordances are F1 and the top-bar cell): Create / Create Multiple / Display / Alter /
  **Deactivate** (never delete — books are preserved, the company just leaves the picker; reactivate
  from Alter). Guards: never the active company, never the last active one.
  `financial_year_start_month` is **locked once the company has vouchers** (historical `fy_start`
  bucketing and numbering were derived under it).

## Per-company fiscal year — books vs statute

`companies.financial_year_start_month` (default 4) drives the **books** FY: `Voucher::fyStartFor()`
(voucher `fy_start` at post → per-FY numbering), `fyOpenFor()` (default report periods, Day Book,
Notes Register, the Shell period line), `fyLabel()` (plain "2026" for calendar-year books). The **TDS
engine deliberately does not follow it**: `Voucher::statutoryFyStartFor()`/`statutoryFyLabel()` stay
Apr–Mar — Indian thresholds, section effectivity, YTD aggregation and 26Q assessment-year math are
statutory. Every TDS call site was repointed *before* `fyStartFor` became company-aware; proven with a
`financial_year_start_month = 1` company: a 10-Feb-2026 voucher books into fy_start **2026** while its
statutory FY stays **2025** — and an April-FY company in the same tenant still buckets it into 2025.

---

## `prove-multi-company` — 59/0

Provisions a throwaway tenant; companies **A** (default, from provisioning), **B** (via
`zerobook:company-create`), **C** (calendar-year FY). Highlights, all asserted to the row:

- provisioning creates exactly **one** default company owning all 28 groups / 15 ledgers;
- B is seeded complete (28 groups, 15 ledgers, 15 TDS sections, INR base, Main Location, all-off F11) —
  and A is untouched by B's seeding;
- "HDFC Bank" exists in **both** companies (composite unique), each row stamped with its company;
- GST on + GSTIN on A → B still off, no GSTIN, `GstService` disabled under B;
- a Payment in A: A's Day Book has 1, **B's is empty**, zero rows stamped B at the raw-SQL level;
- **numbering**: B's first Payment is also №1 (same type, same FY); A's next stays 2;
- a payload posted under B naming **A's ledger id** is **rejected** server-side;
- per-company Trial Balance: both balanced, A totals its own ₹1,500, B its own ₹900;
- USD added in A → B still sees only INR; B's base-currency bulk update **cannot touch A's flags**;
- stock in A → B's `stockEnabled` stays false;
- **sync**: every change-log row carries `company_id`; B's snapshot ships only B's voucher and B's 17
  ledgers; B's incremental pull never sees A's changes;
- `tally-import` without `--company` → **refused**;
- deactivation guards (active company / last active company) + books survive deactivation;
- the stale-tab guard: a component mounted under A **aborts 409** once B is active;
- `prove-balance` and `prove-gst` run green *inside* the multi-company tenant, each in its own fresh
  company that rolls back with its transaction.

Browser-verified on a migrated two-company tenant: F1 opens the picker (keyboard-navigable,
filter-by-typing), Enter switches with a full reload, the top bar updates; the second company's Day Book
is empty, its Trial Balance carries nothing of the first company's ledgers, its F11 is all-off, its
currency master shows only INR while the first company keeps INR + USD + EUR; switching back restores
the original book intact; **0 console errors**.

## Adversarial review — found and FIXED before delivery

A 6-lens multi-agent leak-hunt (raw-SQL residue, write-path stamping, HTTP/Livewire surface,
active-company lifecycle, client caches, migration/schema fidelity; 17 raw findings, each judged by two
adversarial refuters) ran over the finished build. **Real issues it caught, all fixed and re-proven:**

1. **(HIGH) `ActiveCompany`'s memoised Company row survived a TENANT switch** — every tenant's default
   company is id 1, and the memo invalidated only on id *change*, so after `tenancy()->initialize()` of
   a second tenant, identity (GSTIN/state/FY month) came from the FIRST tenant. Reproduced live
   (fxui's "Forex UI Co" served while `demo` was active), fixed by keying the memo by **database +
   id**, re-reproduced clean, and `prove-multi-tenant` re-run green.
2. **(HIGH) migration `down()` could partially MERGE a multi-company tenant** — MySQL DDL is
   non-transactional, so an abort mid-rollback (colliding restored uniques) would have already dropped
   `company_id` from some tables irreversibly. `down()` now **fails fast** if the tenant has more than
   one company, before touching anything.
3. **(HIGH) a sync pull after its company was deactivated silently fell back to the default company** —
   merging two companies' books into one desktop mirror. The middleware now flags fallback resolution
   and the sync API **refuses with 409** (interactive pages still fall back gracefully).
4. **(MEDIUM) `CurrencySeeder` re-runs force-re-marked INR as base** — corrupting an NPR-base company
   on any repair seeding. Now `firstOrCreate`, `is_base` decided only at creation (base only if the
   company has none yet).
5. **(MEDIUM) `tenants:seed` re-runs reached only the default company** — future statutory backfills
   (the established convention that shipped ReturnLedgerSeeder/ForexLedgerSeeder) would have missed
   companies 2..N. `DatabaseSeeder` now loops every active company (idempotent no-op where seeded).
6. **(LOW) `RecordsSyncChanges`' table-existence memo was per-process** — one process touching an
   unmigrated tenant then a migrated one would silently stop change-logging the second. Re-keyed by
   database (positive-only), mirroring `BelongsToCompany`'s memo discipline.
7. **(HIGH) route-model binding resolved URL ids UNSCOPED** — `SubstituteBindings` sat *before*
   `SetActiveCompany` in Laravel's middleware priority order, so `{voucher}`/`{ledger}`/… bindings ran
   while the scope was still inert: a company-B user requesting company-A's ledger drill got **HTTP
   200** with the bound row's identity fields (names, voucher header) instead of a 404 (bodies stayed
   empty — the drill services re-query scoped). Proven at runtime by the auditor; fixed by prepending
   `SetActiveCompany` to the priority list ahead of `SubstituteBindings` (bootstrap/app.php), then
   re-proven in the browser: every cross-company probe (`/reports/ledger/{A}/vouchers`,
   `/reports/bill/{A}`, `/vouchers/{A}/alter`, `/vouchers/{A}/print`) now **404s** as company B while
   the owning company still gets 200.

**Disputed finding settled empirically:** whether the `GuardsActiveCompany` hydrate hook actually fires
on real Livewire requests — verified live on the wire: a stale tab's `saveSingle` after a switch in
another tab returned **`POST /livewire/update → 409 Conflict`**, and zero rows landed in the new
company. Notable refuted findings: the workspaces' `LOWER(name)` checks ride the scope; the tenant-global sync cursor leaks
only activity *volume*, never row data (documented). Deliberate, documented decisions the hunt
surfaced: reads with **no** active company are unscoped (writes fail closed; every web request and
every command pins a company — the asymmetry is for migrations/tinker ergonomics), and the
`companies` FK CASCADE is unreachable in practice (RESTRICT FKs inside the chart block it) — correct,
since 12A deliberately has no company deletion.

---

## Files

**Schema** — `database/migrations/tenant/2026_07_20_000001_add_multi_company.php` (companies + 25
tables, explicit backfill, `down()` restores the pre-12A shape) · `_docs/phase12a_schema.sql` (verified).

**Core** — `app/Support/ActiveCompany.php` · `app/Support/helpers.php` · `app/Models/Company.php` ·
`app/Models/Concerns/BelongsToCompany.php` (on 24 models) · `app/Http/Middleware/SetActiveCompany.php` ·
`app/Models/CompanyFeature.php` (per-company `current()`, identity accessors re-pointed) ·
`app/Services/CompanyProvisioner.php` · `database/seeders/CompanySeeder.php`.

**UI** — `resources/js/engine/engine.js` (F1 global) · `resources/js/engine/components.js`
(`zbCompanyPicker`) · `resources/views/partials/company-picker.blade.php` · the top-bar company cell ·
`app/Livewire/CompanyWorkspace.php` + `resources/views/livewire/company-workspace.blade.php` +
`resources/views/masters/companies.blade.php` (+ `companyWorkspace` JS subclass with deactivate
semantics) · `app/Http/Controllers/CompaniesController.php` (+ `/companies`, `POST /company/switch`) ·
`app/Livewire/Concerns/GuardsActiveCompany.php` (on 30 components) · F11 split
(`app/Livewire/FeaturesScreen.php`).

**Commands** — `app/Console/Concerns/ResolvesActiveCompany.php` · `zerobook:company-create` ·
`zerobook:prove-multi-company` · `--company=` on all 20 prove-commands, required on `tally-import` +
the 4 return exporters.

---

## Out of scope (named, not stubbed) — for 12B/C

- **Company grouping + inter-company transaction tagging** — 12B.
- **Consolidation reporting** (group BS/P&L, eliminations) and any cross-company report — 12C. The one
  deliberate cross-company reader in 12A is the Companies screen's voucher-count column (drives the FY
  lock + the inactive tag), explicitly `withoutGlobalScope` and documented.
- **Copy/clone-a-company**, **per-company user permissions**, **multi-company GST aggregation** — out,
  per the spec.
- **Desktop sync client company-awareness** — the SERVER is now fully company-scoped (the session's
  active company drives push/pull, the change-log is stamped and filtered), but the uncompiled Tauri
  client (7C) still keeps one outbox/cursor per host; per-company outboxes and cursors on the desktop
  side land with the 7C compile pass. Documented, not stubbed.

Existing tenants: `php artisan tenants:migrate` (no central migration).
