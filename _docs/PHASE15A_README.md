# Phase 15A — Budgets

The "am I on budget?" report. An SME owner or CA sets target amounts per ledger or group for a
fiscal year, the system tracks actual postings against those targets, and variance reports show
where the business is over or under. It is a **read-mostly overlay on the balance engine** — it
stores only the *targets*; every *actual* comes from `BalanceService`, so a budget is automatically
correct whenever the accounting is correct, and there is no "budget says X, Trial Balance says Y"
drift.

---

## Step 0 — audit result

Before writing any 15A code, the full battery was re-run: **27/27 prior `prove-*` commands pass**
(17 self-provisioning + the 10 single-company proofs against a throwaway tenant, per the documented
invocation). Regime unchanged, tenant torn down. **After 15A: 28/28 green** —
`zerobook:prove-budgets` adds **64 assertions across 16 sections**. No posting path was touched; the
only changes to shared code are the additive `budgets` F11 flag, a `sink` prop on the master-select
picker (backward-compatible, default `wire`), and the `budgets` key added to the multi-company
proof's expected flag set.

**Adversarial self-review.** A multi-agent review pass (find → verify) surfaced, and this build then
fixed, **9 confirmed correctness bugs** before delivery: actuals now clamp to the budget's fiscal year
(a report window spilling into an adjacent FY can no longer inflate variance); a ledger budgeted both
directly and inside a budgeted parent group is counted once in roll-ups; the variance report's total
is a meaningful net (revenue − expense), not a mixed-nature sum; a revision effective after the FY end
is rejected rather than overwriting the final month; revisions must be chronological; even allocation
and the editor grid sum exactly for any annual; and the group-descendant memo is company-scoped. All
are pinned by the proof (§12–16).

---

## The budget data model

```
budgets            id, company_id (BelongsToCompany), name, fiscal_year_start (int, = vouchers.fy_start),
                   is_primary, created_by_user_id, notes
budget_lines       id, budget_id, ledger_id | account_group_id  (XOR — a DB CHECK + service guard),
                   annual_target (decimal ₹), allocation_method (even|custom|seasonal), notes
budget_line_periods id, budget_line_id, month (1-12 fiscal ordinal), target_amount (decimal ₹),
                   revised_from (date | null)          ← the revision boundary
budget_revisions   id, budget_id, revised_at, revised_by_user_id, notes   ← audit log
company_features.budgets  bool, default false          ← the F11 gate
```

- A **budget** is a named container for a fiscal year, scoped to a company. Multiple can coexist
  ("Original" + "Revised"); exactly **one is `is_primary`** at a time — `Budget::makePrimary()`
  clears the flag on every other budget in the same company (scoped, never cross-company) and sets
  it here. Variance/summary default to the primary.
- A **line** targets *either* a ledger *or* a group. The XOR is enforced by a MySQL `CHECK`
  (`(ledger_id IS NULL) <> (account_group_id IS NULL)`) **and** in `BudgetService`; duplicate
  ledger/group lines are blocked by a per-budget unique index + a service check.
- **Amounts:** targets are stored as `decimal(18,2)` rupees (matching `ledgers.opening_balance`) and
  converted to **integer paise** (`×100`) only at the comparison edge — the same paise/Dr-terms space
  `BalanceService` computes in, so variance has no float drift.
- **Children carry no `company_id`** — they are reached only through their (company-scoped) parent
  budget and cascade-delete with it.

---

## Allocation methods

`BudgetService::allocate()` resolves a line's annual target into 12 fiscal-month amounts (month 1 =
the company's FY-start month, April by default), summing **exactly** to the annual:

- **even** — `annual / 12`, with the rounding remainder spread over the earliest months.
- **custom** — the user's explicit 12 month amounts (the line's `annual_target` becomes their sum).
- **seasonal** — 12 percentage **weights** resolved against the annual; the rounding drift lands on
  the largest month so the total is exact. A built-in India-retail curve
  (`SEASONAL_TEMPLATE = [6,6,7,7,8,10,12,12,10,8,7,7]`, festival-heavy Sep–Dec) is the editor's
  one-click; a caller may pass its own weights.

The **resolved per-month amounts are stored** (`budget_line_periods`), so variance can be summed at
any period granularity without re-running allocation.

---

## The revision mechanism (the crux — history never changes)

A budget is revised from an **effective date** forward. `reviseBudget($budget, $newLines,
$effectiveFrom)`:

1. writes a `budget_revisions` audit row;
2. for each revised line, resolves the new 12-month allocation and inserts **new**
   `budget_line_periods` rows carrying `revised_from = effectiveFrom` **only for the months from the
   effective fiscal month forward** — the original (`revised_from NULL`) rows are never touched.

The **effective target for a month** is the row with the **greatest `revised_from ≤` that month**
(NULL = the original, treated as earliest). So:

- months **before** the effective boundary keep their original targets — **historical variance is
  unaffected**;
- months **from** the boundary use the revised targets;
- multiple revisions on different dates layer correctly (the latest applicable wins);
- re-revising on the **same** date is idempotent (its rows are replaced).

The variance report shows **Original Target**, **Current Target** (post-revision) and **Actual** side
by side, and marks revised lines.

> **Proven:** Marketing budgeted ₹50,000/month, revised to ₹40,000/month effective 1-June. June
> variance uses ₹40,000 (current) vs ₹50,000 (original); **April–May still reads ₹100,000**, exactly
> as before the revision — not retroactively cut to ₹80,000.

---

## Variance & the favorable convention

For a period range, per line: `Actual = BalanceService` (ledger → `ledgerBalances[id]['net']`;
group → the `tree()` node's rolled-up `net`, aggregating all descendant ledgers), **sign-normalised
per the target's nature** exactly like `profitAndLoss` (Income × −1, Expenses × +1, Assets × +1,
Liabilities × −1) so everything reads as a positive magnitude. `Target = Σ` effective month targets.
`Variance = Actual − Target`, `Variance % = Variance / Target × 100`.

**Favorable** = Income/Assets up (`actual ≥ target`); Expenses/Liabilities down (`actual ≤ target`).
Shown as **green/red text colour only** — no other formatting change.

A ledger/group with **no budget line** shows **"No target"** (target `null`), never `0` — untracked
and zero-budgeted are different things.

---

## The worked scenario (from `zerobook:prove-budgets`)

Budget "FY 2026-27": **Sales** ₹1,200,000 even (₹100k/mo), **Marketing** ₹300,000 custom
(₹50k Apr–Sep, ₹0 Oct–Mar), **Direct Expenses group** ₹500,000 even. Actuals posted: Sales ₹250k
(Apr+May), Marketing ₹40k Apr + ₹60k May, Rent ₹120k + Electricity ₹30k (both Direct Expenses, Apr),
Sales Export ₹30k (untracked).

| Line (Apr–May) | Target | Actual | Variance | % | Verdict |
|---|--:|--:|--:|--:|---|
| Sales (Income) | 200,000 | 250,000 | **+50,000** | +25% | favorable |
| Marketing (Expenses) | 100,000 | 100,000 | 0 | 0% | favorable |
| Direct Expenses (group) | 83,333 | **150,000** (Rent+Electricity) | +66,667 | — | aggregates descendants |
| Sales Export (Income) | **No target** | 30,000 | — | — | untracked |

Revise Marketing → ₹40k/mo from June; post ₹45k June. **June:** target 40,000 vs actual 45,000 =
**+5,000 unfavorable**. **April–May Marketing target still 100,000.** Summary as of 30-Jun: revenue
budget 300,000 / actual 250,000; expense budget 140,000 / actual 145,000; net budget 160,000 / actual
105,000.

---

## Acceptance checklist → where it's proven

`DB_DATABASE=tenant<slug> php artisan zerobook:prove-budgets` (64 assertions, 16 sections):

| Criterion | § |
|---|---|
| F11 gate — off: no menu + screens redirect; on: 4 menu items + screens render | 11 |
| Create budget — even (100k/mo) + custom (50k Apr-Sep) allocation persists to periods | 1 |
| Variance Apr–May — Sales +50k/+25% favorable; Marketing 0 | 2 |
| Group budget — Direct Expenses actual aggregates Rent + Electricity | 3 |
| No-budget ledger shows "No target" (null), not 0 | 4 |
| Drill from a variance figure to the underlying vouchers | 5 |
| **Revise in June — Apr–May unchanged; June +5k unfavorable; revision logged** | 6 |
| Seasonal allocation sums exactly to the annual, non-flat | 7 |
| Primary flag exclusivity — a new primary clears the old | 8 |
| Duplicate ledger line rejected | 9 |
| Per-company isolation — Company A budgets invisible in Company B | 10 |

**Browser-verified:** the F11 toggle shows/hides the Budgets menu; Budget List, Editor (grid +
ledger/group pickers), Variance (green/red, drill) and Summary render on a tenant subdomain. 0
console errors.

---

## Screens & keyboard integration

Four Livewire screens under `App\Livewire\Budgets`, each mounted through a wrapper blade that
`@extends('layouts.app')` — so they inherit the Phase-1 right button bar + bottom status bar + the
keyboard engine automatically:

- **Budget List** (`reports.budget-list`) — every budget; ↑↓ move, Enter revise, N new, P primary,
  Del delete.
- **Budget Editor** (`reports.budget-editor`) — the grid: rows are ledger/group targets added via the
  `zbSelect` master picker, columns are the 12 fiscal months, cells are the targets; method + annual
  per row; F9 save. Create mode → `createBudget`; revise mode (an effective date) → `reviseBudget`.
- **Budget vs Actual** (`reports.budget-variance`) — target/actual/variance/% per line, green/red,
  drillable to the underlying vouchers via the existing `reports.ledger` full-page drill.
- **Budget Summary** (`reports.budget-summary`) — revenue/expense budget-vs-actual and projected vs
  actual net profit, F2 as-of date.

**Gateway.** The Budgets destinations are gated by the F11 flag in `Shell::nav()` (the Alt+G Go To
palette, grouped under a "Budgets" section) and a Gateway hub tile. Because every A–Z hub hotkey is
already claimed, the hub tile uses the `0` hotkey and is arrow-navigable + clickable; the four screens
are fully reachable from the Go To palette. When the flag is off the menu entries disappear and the
screen routes redirect to the Gateway (screens inaccessible).

---

## Files

**Schema (tenant) + `_docs/phase15a_tenant_schema.sql`:** migration
`…2026_07_23_000001_add_budgets` (`budgets`, `budget_lines` [+ XOR CHECK + unique], `budget_line_periods`,
`budget_revisions`; `company_features.budgets`); models `Budget`, `BudgetLine`, `BudgetLinePeriod`,
`BudgetRevision`.

**Service:** `App\Services\BudgetService` (create / revise / variance / summary + allocation + the
seasonal template).

**F11 gate:** `CompanyFeature` (`$casts` + `toFlags`), `Livewire\FeaturesScreen` (prop + mount + save),
`resources/views/livewire/features-screen.blade.php` (`items[]`), the migration column.

**Screens:** `App\Livewire\Budgets\{BudgetList, BudgetEditor, BudgetVarianceReport, BudgetSummary}`;
wrapper blades `resources/views/reports/budget-{list,editor,variance,summary}.blade.php`; Livewire views
`resources/views/livewire/budgets/{list,editor,variance,summary}.blade.php`; Alpine controllers
`resources/js/budgets/screens.js` (registered in `resources/js/app.js`); `sink` prop on
`resources/views/components/master-select.blade.php`.

**Routing / menu:** `ReportsController` (4 methods + the F11 gate), `routes/tenant.php` (4 routes),
`App\Support\Shell` (`budgetsEnabled` + `budgetNav` + the hub tile).

**Proof:** `App\Console\Commands\ProveBudgetsCommand`.

**Existing environments:** `php artisan tenants:migrate` (tenant migration) + `npm run build` (the new
Alpine module). No central migration.

---

## Scope — NOT in 15A (honest notes)

- **Rolling forecasts** — a 12-month-forward projection that updates each month. A budget is the
  *plan*; a forecast is the *revised expectation*. Different feature, its own phase.
- **Departmental / per-cost-centre budgets** — cost centres exist (5D), but per-centre targets add
  another dimension. Addable later without a data-model change.
- **Capital budgeting** (NPV / IRR / payback on long-term investments) — a genuinely different
  subsystem despite the shared name.
- **Group budgets** (aggregated across companies in a group) — depends on 12C-2 group reporting; the
  data model already supports a later clean addition.
- **Budget approval workflow** — an enterprise feature the SME target market doesn't need.
- **Import budgets from Excel** — a productivity add-on for later.

*15B (Ratio Analysis) and 15C (Scenarios) complete the Phase 15 advanced-reporting tail.*
