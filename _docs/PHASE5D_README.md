# ZeroBook — Phase 5D: Cost Centres (masters, allocation, breakup report)

Adds Tally's cost-centre workflow: the **cost-centre master**, a **cost-allocation
sub-screen** on cost-applicable ledger lines, and a **Cost Centre Breakup** report.
Gated by the F11 **"Cost centres"** switch (Phase 4) and the per-ledger
**cost_centres_applicable** flag (Phase 2). This closes Phase 5.

Cost allocations post through the **one shared, balance-gated path**
(`VoucherScreen::post()`), each cost-applicable line's allocations **sum to the
line amount to the paise** (re-validated on the server), and — critically — a cost
allocation is an **analytical tag only**: it never changes the accounting, so the
Trial Balance / Balance Sheet / P&L are byte-identical with or without it.

---

## Step 0 — audit & flag

**5C audit → PASS.** `prove-billwise`, `prove-gst`, `prove-sales-purchase`,
`prove-balance` all pass. `ledgers.cost_centres_applicable` (boolean) **already
exists** from Phase 2 — reused; added to `Ledger::toCache()` and given an
F11-gated toggle on the ledger create/alter forms (the alter form was also missing
the Phase-5C bill-by-bill toggle — fixed here so both persist on alter).

---

## Cost-centre rules as implemented

- **Cost-centre master** — Create (single + multiple), Display, Alter, Delete,
  keyboard-driven, with an optional **parent** cost centre (`zbSelect`, source
  `costCentres`). A single implicit **Primary** cost category is assumed; the
  schema leaves room for a `cost_category_id` later without a rewrite. Acyclic
  re-parenting is enforced server-side (like account groups); delete is blocked if
  the centre has sub-centres or is used in vouchers.
- **Applies to** any ledger with *cost_centres_applicable* on. When such a ledger
  is on a voucher line, the **cost-allocation sub-screen** opens at accept and the
  line amount **must be fully allocated** (0 remaining) before the voucher posts.
- **Allocation total = line amount to the paise** (client blocks; server
  re-checks). A line may be split across several cost centres.
- **Accounting unaffected** — cost allocations add no voucher entry; `BalanceService`
  never reads them. Only the cost-centre reports do.

---

## Posting on the shared path (server authority)

`app/Services/CostCentreService.php` mirrors `BillService`. Allocations attach to
each payload line as `cost_allocations: [{cost_centre_id, amount}]` and flow
through the **same** `VoucherScreen::post()`:

- **`validatePayload()`** runs in the existing after-hook (next to the GST + bill
  authorities): every cost-applicable line (feature on) must have allocations that
  **sum to the line amount to the paise**, with valid cost-centre ids — else the
  voucher is rejected. Supplied allocations are validated even if the feature is
  off. The paise balance gate + GST + bill authorities all still apply.
- **`persist()`** writes `cost_allocations` in the **same transaction** as the
  entries (linked to `voucher_entry_id`, via the entry ids `writeEntries()`
  returns). On **alter** the allocations are deleted and rewritten; on **cancel**
  they **cascade** away. One posting path.

The client (`resources/js/vouchers/screen.js`) keeps allocation **0-network**: at
accept it opens the sub-screen for each unallocated cost-applicable line (chaining
after any bill allocation, then posting), picking cost centres from a `costCentres`
cache seeded in `bootData`.

---

## Schema

`cost_centres` (name unique, `parent_id` self-FK) + `cost_allocations`
(`voucher_id` cascade, `ledger_id`, `voucher_entry_id` set-null, `cost_centre_id`
cascade, `amount`). Migrations `2026_07_07_000007/000008` + `_docs/phase5d_schema.sql`.

---

## Cost Centre Breakup report

`/reports/cost-centres` (`Reports\CostBreakup` + `CostCentreService::breakup()`).
Per cost centre, the ledger-wise allocated amounts and a total, then a grand total.
`Enter` drills a centre to **the vouchers behind it** (`/reports/cost-centre/{id}`,
reusing the Ledger-Vouchers list); `F2` sets the period. Reachable from Go To
(keywords: cost centre, breakup, allocation) and the Gateway hub (letter **O**);
the master is reachable from the Chart-of-Accounts hub (letter **C**, shown when
the feature is on) and Go To.

---

## Worked numeric proof — `php artisan zerobook:prove-costcentre`

Posts a cost-allocated Payment **through `VoucherScreen::post()`** and asserts the
split, the accounting-invariance, the server authority and the breakup. Rolls back
unless `--keep`.

```
Payment PYMT-1:  Dr Marketing Spend 6,000  (split 4,000 North + 2,000 South)  /  Cr Cash 6,000
  → two cost_allocations persisted, both linked to the Marketing entry
  → Marketing closing = 6,000  (UNCHANGED by the cost split — accounting unaffected)
  → Trial Balance: Dr 6,000 = Cr 6,000  balanced
  → Breakup: North 4,000 · South 2,000 · grand 6,000
```

**All 11 assertions PASS**, including: two allocations persisted and linked to the
line's entry; **Marketing closing == 6,000 (the cost split did not touch the
ledger)**; Trial Balance balanced; breakup North 4,000 / South 2,000 / grand 6,000;
the North drill finds the voucher; and a **mismatched allocation (700 ≠ 1,000 line)
is rejected** by the server.

### Verified live in the browser
- **Cost Centre master**: created "East Zone" **under** North Zone by keyboard —
  persisted (`path = North Zone ▸ East Zone`), cache updated ("✔ East Zone created").
- A **Payment** to the cost-applicable "Marketing Spend" opens the **cost-allocation
  sub-screen** at accept; **under-allocation is blocked** (3 of 5 rows → "2,000.00
  remaining"); splitting 4,000 North + 2,000 South and accepting **chains to the
  post** → PYMT-2 with two `cost_allocations` linked to the Marketing entry.
- **Cost Centre Breakup** renders North 4,000 (Marketing 4,000) · South 2,000
  (Marketing 2,000) · **grand 6,000**, "analytical — the ledger balances are
  unaffected"; **drill** North → both payments.
- **Regression:** with cost centres off, `prove-billwise` / `prove-gst` /
  `prove-sales-purchase` / `prove-balance` all pass; no console errors.

---

## Adversarial review + fixes

A 5-dimension adversarial multi-agent review (each finding independently
verified) ran over the 5D diff. Three dimensions were clean; **2 findings were
CONFIRMED and fixed**, 1 was refuted:

1. **[HIGH — normal use] Cost-allocation sub-screen: removing a MIDDLE row via `×`
   desynced the cost-centre combo from its row.** The `<template x-for>` keyed on
   the array index (`:key="i"`) while each row hosts a nested `zbSelect` (init-once);
   splicing a middle row shifted the rows but left the reused pickers showing the
   previous occupant's centre — so the posted centre could differ from what was
   displayed. **Fix:** each cost row now carries a stable `_uid`; the x-for keys on
   it and the picker id/container id derive from it, so a middle-row removal keeps
   every picker paired with its row. **Verified in the browser:** after removing the
   middle of North/South/Central, the combos correctly read North + Central,
   matching the row data (`paired: true`).

2. **[MEDIUM — crafted/stale payload] The per-ledger gate was not
   server-authoritative.** `CostCentreService::validatePayload` (and, identically,
   `BillService`) validated *supplied* allocations for sum/ids but did not **reject**
   allocations attached to a ledger that is not cost-applicable / not bill-wise — so
   a crafted payload (or a de-flag-then-alter sequence) could tag cost/bill data onto
   any ledger and surface it in the reports. (Accounting balances were never at risk
   — allocations post no entry.) **Fix:** both services now **reject** allocations on
   an ineligible ledger and require each allocation amount `> 0`; `persist()`
   re-checks eligibility (defence in depth); and the client no longer re-sends stale
   allocations for a ledger whose flag was cleared. **Verified:** crafted
   cost-on-non-applicable, negative-amount, and bill-on-non-bill-wise payloads are
   now rejected, while valid allocations still post.

*Refuted:* a claim that negative allocation amounts pass validation — the underlying
observation was real, so the `> 0` guard above was added anyway as cheap hardening.

All 5 numeric proofs still pass after the fixes; no console errors.

---

## Files

**New**
- `database/migrations/2026_07_07_000007_create_cost_centres_table.php`, `..._000008_create_cost_allocations_table.php`
- `_docs/phase5d_schema.sql`
- `app/Models/CostCentre.php`, `app/Models/CostAllocation.php`
- `app/Services/CostCentreService.php`
- `app/Livewire/CostCentreWorkspace.php` + `resources/js/masters/costcentre.js` + `resources/views/livewire/cost-centre-workspace.blade.php` + `resources/views/masters/cost-centres.blade.php`
- `app/Livewire/Reports/CostBreakup.php` + `resources/views/{reports,livewire/reports}/cost-breakup.blade.php` + `resources/views/reports/cost-centre-vouchers.blade.php`
- `resources/views/partials/cost-alloc.blade.php`
- `app/Console/Commands/ProveCostCentreCommand.php`

**Extended**
- `app/Models/Voucher.php` — `costAllocations()`; `app/Models/Ledger.php` — `cost_centres_applicable` in `toCache()`
- `app/Livewire/VoucherScreen.php` — cost validate + persist on the shared path; `costEnabled`/`costCentres` boot data + edit re-seed
- `app/Livewire/LedgerWorkspace.php` (+ blade) — cost-applicable (and bill-by-bill) toggles on create/alter
- `app/Http/Controllers/{Reports,Masters}Controller.php` — breakup / drill / master routes
- `app/Support/Shell.php` — Cost Centres master + Breakup in Go To + hubs
- `app/Services/BillService.php` — mirrored the review's per-ledger-gate + positive-amount hardening (5C)
- `resources/js/vouchers/screen.js` — cost-allocation sub-screen
- `resources/js/masters/store.js` (costCentres cache), `select.js` (costCentres source), `app.js` (register)
- `resources/js/reports/screen.js` — `costBreakup` controller
- `routes/web.php`, `resources/css/{vouchers,reports}.css`

---

## Not in 5D (later)
Multiple cost categories (single Primary now — schema seam left); Nepal VAT (5E if
wanted); inventory (Phase 6); budgets vs cost centres, cost-centre classes.
Inline cost-centre create during allocation was deferred (create in Masters first);
the picker is built so `createType` can be added later.
