# Phase 15B — Ratio Analysis

The "am I healthy?" report. Fifteen financial ratios distil an entire Balance Sheet and P&L into a
handful of numbers that answer specific questions — *Can I pay my short-term bills? Am I profitable?
Am I over-leveraged? Am I using my assets efficiently?* Every ratio is a **composition of
`BalanceService` figures**, so it is automatically correct whenever the Trial Balance is; nothing
re-sums vouchers. Each is period-aware, drills to the ledgers it aggregates, carries a prior-period
delta and a 12-point sparkline, and is coloured by transparent, per-company health bands.

---

## Step 0 — audit result

Before writing any 15B code the full battery was re-run: **28/28 prior `prove-*` commands pass**
(17 self-provisioning + the 11 single-company proofs against a throwaway tenant). Regime unchanged.
**After 15B: 29/29 green** — `zerobook:prove-ratios` adds **48 assertions across 11 sections**. No
posting path was touched; the only shared-code changes are the additive `ratio_analysis` F11 flag and
its key in the multi-company proof's expected flag set.

**Adversarial self-review.** A multi-agent review (find → verify) surfaced, and this build then fixed,
**14 confirmed issues** before delivery — most importantly: a **negative-equity health inversion**
(a negative Debt-to-Equity / ROE from negative equity used to colour *green*; now it computes but is
forced **red** and its delta favourability suppressed — an insolvent position can never read as
healthy); an **inventory-turnover basis mismatch** (average inventory's opening now includes the
StockService opening, matching the basis COGS uses); the **sparkline** now stays inside the current
fiscal year and its newest point honours the as-of cutoff; the drill-down carries the **live F2
as-of**; the interest heuristic is whole-word (`\binterest\b`); and the proof gained value assertions
for the previously-unasserted ratios + a real per-company scoping check.

---

## The ratio set — formula & `BalanceService` inputs

Every figure below is read from **one** `balanceSheet(from,to)` or `profitAndLoss(from,to)` call and
sign-normalised (BalanceService returns paise in Dr-terms: Assets/Expenses positive; Liabilities/
Income/Capital negative → negated to positive magnitudes). Named-group totals (Current Assets, etc.)
come from walking the `*_roots` tree by node name and reading `node['closing']`.

**Liquidity** (as of the date)
| Ratio | Formula | Inputs |
|---|---|---|
| Current Ratio | Current Assets ÷ Current Liabilities | `Current Assets` / `Current Liabilities` group closings |
| Quick Ratio | (Current Assets − Inventory) ÷ Current Liabilities | above less `Stock-in-Hand` |
| Cash Ratio | (Cash + Bank) ÷ Current Liabilities | `Cash-in-Hand` + `Bank Accounts` |

**Solvency / Leverage**
| Ratio | Formula | Inputs |
|---|---|---|
| Debt-to-Equity | Total Debt ÷ Equity | `Loans (Liability)` ÷ (`Capital Account` + retained earnings = `net`) |
| Debt-to-Assets | Total Debt ÷ Total Assets | `Loans (Liability)` ÷ `total_assets` |
| Interest Coverage | EBIT ÷ Interest | (`net` + interest) ÷ interest |

**Profitability** (period)
| Ratio | Formula | Inputs |
|---|---|---|
| Gross Profit Margin | Gross Profit ÷ Sales × 100 | `gross_profit` ÷ `Sales Accounts` |
| Operating Profit Margin | EBIT ÷ Sales × 100 | (`net` + interest) ÷ Sales |
| Net Profit Margin | Net Profit ÷ Sales × 100 | `net` ÷ Sales |
| ROE | Net Profit ÷ Equity × 100 | `net` ÷ Equity |
| ROA | Net Profit ÷ Total Assets × 100 | `net` ÷ `total_assets` |

**Efficiency** (period)
| Ratio | Formula | Inputs |
|---|---|---|
| Inventory Turnover | COGS ÷ Average Inventory | (`trading_expense` + Δstock) ÷ (opening+closing `Stock-in-Hand` ÷ 2) |
| Debtor Days | (Sundry Debtors ÷ Sales) × 365 | `Sundry Debtors` ÷ `Sales Accounts` |
| Creditor Days | (Sundry Creditors ÷ Purchases) × 365 | `Sundry Creditors` ÷ `Purchase Accounts` |

**Budget** (only when the 15A Budgets feature is on and a primary budget exists)
| Ratio | Formula | Inputs |
|---|---|---|
| Budget Variance % | (Actual − Budget) ÷ \|Budget\| × 100 | `BudgetService::summary` net position |
| Burn Rate | Operating-expense actual ÷ budget | `BudgetService::summary` expense pair |

**Interest** has no reserved group, so it is identified as **expense-nature ledgers whose name
contains "interest"** (the SME convention — interest is booked to an "Interest …" ledger), read via
`ledgerBalances`. Interest *income* (income nature) is excluded, so it never contaminates the figure;
when no interest ledger exists, Interest Coverage is "N/A".

---

## Divide-by-zero & negative handling (per ratio)

- **Zero denominator → `value = null`** (rendered "N/A") with a **reason** string — no `inf`, no
  crash. Current/Quick/Cash on zero Current Liabilities; D/E and ROE on **zero equity** ("Equity is
  zero — ratio not computable."); D/A and ROA on zero total assets; margins/debtor-days on zero
  sales; creditor-days on zero purchases; interest coverage on zero interest.
- **Negative but meaningful denominators still compute.** A company with **negative equity**
  (deep-loss position) produces a real negative D/E / ROE and is flagged **red** — not masked to N/A.
- **Negative numerators are valid signals.** A net loss produces a genuine negative margin (e.g.
  **−5%**), coloured red — never suppressed.

---

## Health thresholds

Bands are **per-company, transparent, and editable** (the Thresholds screen, F12 from the dashboard).
`green_min` / `amber_min` are boundaries read according to each ratio's intrinsic **direction**
(defined in `RatioService`, not the DB): a *higher-is-better* ratio is green at value ≥ green_min; a
*lower-is-better* ratio (Debtor Days, Debt-to-Equity) is green at value ≤ green_min. The same
direction drives **delta favourability** — a fall in a lower-is-better ratio is *favourable* (green),
a fall in a higher-is-better ratio is *unfavourable* (red). Industry-neutral defaults ship seeded;
the tool never asserts an absolute "unhealthy" — different industries have different norms.

Default Current Ratio bands: **green ≥ 1.5, amber 1.0–1.5, red < 1.0**.

---

## The worked scenario (from `zerobook:prove-ratios`)

Seeded (openings + P&L vouchers, GST off): Current Assets — Cash ₹100k, Bank ₹200k, Debtors ₹150k,
Stock ₹250k (₹700k); Fixed Assets ₹500k; Current Liabilities — Creditors ₹200k + other ₹100k
(₹300k); Long-term Debt ₹400k; Equity ₹500k (Capital ₹400k + retained ₹100k); Sales ₹1,000,000,
COGS ₹600,000, Operating Exp ₹200,000, Interest ₹40,000, Salaries ₹60,000, **Net ₹100,000**.

| Ratio | Value | | Ratio | Value |
|---|--:|---|---|--:|
| Current Ratio | **2.33** | | Gross Profit Margin | **40.00%** |
| Quick Ratio | **1.50** | | Net Profit Margin | **10.00%** |
| Cash Ratio | **1.00** | | ROE | **20.00%** |
| Debt-to-Equity | **0.80** | | ROA | **8.33%** |
| Debt-to-Assets | **0.33** | | Debtor Days | **54.75** |
| Interest Coverage | **3.50** | | | |

Plus proven: **N/A** on a zero-equity company's D/E (with reason); a loss-making company's **−5%**
net margin flagged red; the period delta (Current Ratio 4.0 → 2.33 = **−1.67 unfavourable**;
Debt-to-Equity 1.0 → 0.8 = **−0.2 favourable**); editing green_min 1.5→3.0 turns 2.33 **amber**; the
12-point sparkline data; the drill inputs + contributing ledgers; budget ratios present only with
15A on; the F11 gate; and per-company isolation.

---

## Acceptance checklist → where it's proven

`DB_DATABASE=tenant<slug> php artisan zerobook:prove-ratios` (48 assertions, 11 sections):

| Criterion | § |
|---|---|
| Every worked ratio value (11 assertions) | 1 |
| Health colours from default thresholds | 2 |
| Divide-by-zero — zero equity → N/A + reason, no inf/crash | 3 |
| Negative income → −5% net margin, red, not masked | 4 |
| Period delta — direction-aware favourability | 5 |
| Editing a threshold re-colours the ratio | 6 |
| Sparkline — ≤ 12-month trend data present | 7 |
| Drill — ratio → inputs → contributing ledgers (drillable) | 8 |
| Budget ratios appear only when 15A is on | 9 |
| F11 gate — menu + screens hidden when off | 10 |
| Per-company scoping | 11 |

**Browser-verified:** the Ratio Dashboard renders the four sections with values, deltas, sparklines
and health colours; drill → inputs → ledger vouchers; the Thresholds screen edits + re-colours; F11
shows/hides the menu. 0 console errors.

---

## Screens & keyboard integration

Three Livewire screens under `App\Livewire\Ratios`, each through a controller `shell()` + gate →
wrapper blade `@extends('layouts.app')` → `<livewire:ratios.*>` — inheriting the Phase-1 right button
bar + status bar:

- **Ratio Dashboard** (`reports.ratio-dashboard`) — four sections (+ Budget when on); ↑↓ move, Enter
  drill, F2 as-of date, **F12 → Thresholds**. Each ratio shows value (health-coloured), prior-period
  Δ (favourability-coloured), and a from-scratch inline SVG `<polyline>` sparkline (~120×30, single
  colour, no axes).
- **Ratio Drill-down** (`reports.ratio-drilldown/{ratio}`) — the ratio's traced input figures and its
  contributing ledgers, each drillable to vouchers via the existing `reports.ledger` drill.
- **Ratio Thresholds** (`reports.ratio-thresholds`) — edit the green/amber bands per ratio, F9 save,
  Restore defaults.

**Gateway.** Gated by the F11 `ratio_analysis` flag in `Shell::nav()` (Alt+G Go To palette, "Ratios"
section) and a Gateway hub tile. As with Budgets, every A–Z hub hotkey is claimed, so the hub tile is
arrow-navigable/clickable and the Go To palette is the primary keyboard surface; when both the
Budgets and Ratio hub tiles are present they share the `0` hotkey (fully reachable by arrows/click/Go
To). When the flag is off the menu entries disappear and the screens redirect to the Gateway.

---

## Files

**Schema (tenant) + `_docs/phase15b_tenant_schema.sql`:** migration `…2026_07_24_000001_add_ratio_analysis`
(`ratio_thresholds` + `company_features.ratio_analysis`); model `RatioThreshold`.

**Service:** `App\Services\RatioService` (the 15 ratios, figure extraction, health, trend/sparkline,
drill inputs, budget ratios).

**F11 gate:** `CompanyFeature` (`$casts` + `toFlags`), `Livewire\FeaturesScreen` (prop + mount + save),
`features-screen.blade.php` (`items[]`), the migration column.

**Screens:** `App\Livewire\Ratios\{RatioDashboard, RatioDrilldown, RatioThresholds}`; wrapper blades
`resources/views/reports/ratio-{dashboard,drilldown,thresholds}.blade.php`; Livewire views
`resources/views/livewire/ratios/{dashboard,drilldown,thresholds}.blade.php`; Alpine controllers
`resources/js/ratios/screens.js` (registered in `resources/js/app.js`).

**Routing / menu:** `ReportsController` (3 methods + `ratiosGate`), `routes/tenant.php` (3 routes),
`App\Support\Shell` (`ratiosEnabled` + `ratioNav` + hub tile).

**Proof:** `App\Console\Commands\ProveRatiosCommand`.

**Existing environments:** `php artisan tenants:migrate` + `npm run build`. No central migration.

---

## Scope — NOT in 15B (honest notes)

- **Industry benchmark comparisons** — comparing your ratio to external industry averages needs a
  curated benchmark data source/subscription; separate feature.
- **DuPont analysis** — the multi-factor ROE decomposition; advanced, addable later.
- **Cash-flow ratios** — depend on a cash-flow statement ZeroBook doesn't yet produce; deferred with
  that subsystem.
- **Sector-specific ratios** (bank capital adequacy, insurance loss ratios) — not general-purpose.
- **Consolidated (group) ratios** — sit downstream of 12C-2 consolidation; a natural later phase.
- **Trend beyond 12 periods**, **ratio-based alerts**, and **PDF/Excel export** — each belongs to its
  own subsystem (deep charting, alerting, general export), not this phase.

*15C (Scenarios — the provisional-voucher "what if" layer) completes the Phase 15 advanced-reporting
tail.*
