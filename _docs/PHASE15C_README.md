# Phase 15C — Scenarios (provisional "what-if" vouchers)

Tally's provisional-voucher layer. A **scenario** is a named container of proposed transactions —
"what if we win this ₹10 lakh order?", "what if rent rises 20%?" — that you post as ordinary
vouchers but keep **out of the real books** until you decide. Reports gain a *view-with* mode; the
Impact report shows real vs real+scenario; and **promotion** is a one-way, transactional flip that
folds a scenario into the real books forever.

The hard part is not the container — it is that **every report must become scenario-aware without
corrupting the real books**. 15C touches more read paths than any prior feature, yet a report with no
scenario selected is **byte-identical** to pre-15C. That invariant is the spine of this document.

---

## Step 0 — audit result

Before writing 15C the battery was re-run: **29/29 prior `prove-*` commands pass**. **After 15C:
30/30 green** — `zerobook:prove-scenarios` adds **50 assertions across 14 sections**. The regression
guarantee is structural: every scenario-aware read defaults to `vouchers.scenario_id IS NULL`, so on
any book with no tagged voucher the new predicate is a tautology and the SQL result is unchanged. The
existing proofs (which never tag a voucher) confirm this empirically.

**No parallel posting path.** Provisional vouchers are written by the *same* `VoucherScreen::post` →
`writeVoucherGraph` as real ones — the only difference is a `scenario_id` column value. Nothing about
the accounting engine forks.

**Adversarial self-review.** A multi-agent review (find → adversarially verify) surfaced, and this
build then fixed, **5 confirmed real bugs** before delivery — each a way a provisional voucher could
have leaked into the real books:

1. **`TdsService::qualifyingPriorPaise` annual branch** read `tds_deductions` filtered by fiscal year
   only — a provisional payment's deduction row inflated the single-bill-threshold aggregate for a
   *real* voucher's TDS, so the real voucher was rejected as a mismatch. Now `whereNull('vouchers.
   scenario_id')` on both branches.
2. **`SyncService::pull` (incremental)** loaded changed vouchers with no scenario filter (only
   `snapshot` had it), so a provisional voucher's change-log row leaked it to desktop mirrors as real.
   Now `whereNull('scenario_id')` on the incremental find too.
3. **Promotion recomputed TDS.** Re-running `persist()` recomputed the deduction against the now-real
   state, which could diverge from the voucher's frozen Cr TDS Payable line and break the
   `tds_deductions ⇄ ytd ⇄ general-ledger` identity. Promotion now rolls the **existing** deduction
   into ytd (`TdsService::rollYtdForPromotedVoucher`) — GL-consistent, never a recompute.
4. **`ScenarioContext::fromSession`** returned raw session ids without re-validating, so a
   since-deactivated / cross-company id silently folded provisional vouchers into a report whose
   banner said "real books". Now intersected with the current company's active scenarios.
5. **Voucher ALTER** derived its write context from the client-sent `scenario_id`, so altering a real
   voucher with a scenario picked in the dropdown recomputed its COGS against provisional stock. The
   alter context now follows the voucher's **stored** tag, never the payload.

---

## The mechanism — one process-scoped context, one choke point

`App\Support\ScenarioContext` is a process-scoped holder mirroring `ActiveCompany`:

- **`selected(): array`** — the scenario ids in view. `[]` = real books. Resolution order: an explicit
  in-process **override** (set by `runWith`) wins; otherwise it falls back to the **report picker's
  session** (`session('scenario.selected')`), guarded so that outside a started web session (CLI
  proofs, queued sync jobs, the desktop sync API) it is always `[]`. This one rule makes every report
  honour the picker across the initial GET, a Livewire `wire:model` update, and `wire:navigate` — with
  **zero middleware** — while keeping the real books the default everywhere else.
- **`apply($query, 'vouchers')`** — `[]` → `WHERE vouchers.scenario_id IS NULL`; otherwise
  `WHERE scenario_id IS NULL OR scenario_id IN (…)`. Used wherever a query already reaches `vouchers`.
- **`applyByVoucher($query, 'x.voucher_id')`** — a correlated `EXISTS` against `vouchers` for tables
  that reference a voucher but don't themselves carry `scenario_id`.
- **`runWith(?array $ids, fn)`** — run a body with a forced selection (restoring the previous override
  after). `null` inherits the current selection (the `?array $scenarioIds = null` service-parameter
  contract). **`runWith([])` forces the real books regardless of the picker** — the lever the
  return-file and impact paths pull.

Because the selection lives in the context (not a threaded parameter), it **propagates through nested
service calls for free**: `GstService`/`VatService`/`RatioService`/`BudgetService` all funnel through
`BalanceService::netByLedger`, so making that one method scenario-aware makes them all scenario-aware.

---

## THE CATALOG — every voucher-reading query, and how it was made scenario-aware

This is the exhaustive map the acceptance rests on. Three treatments: **widen** (`apply`/
`applyByVoucher` — the read honours the selection), **inherit** (delegates to a widened method, no
edit needed), and **real-only** (`whereNull` — statutory/threshold/sync state that must *never* see a
provisional voucher).

### Balance & derived reports — the choke point
| Service · method | Treatment | Note |
|---|---|---|
| `BalanceService::netByLedger` | **widen** (`apply`) | the single Dr-terms choke point |
| `BalanceService::ledgerVouchers` | **widen** (`apply` via tap) | ledger drill |
| `balanceSheet` / `profitAndLoss` / `tree` / `ledgerBalances` / `ledgerClosings` | **inherit** | all funnel through `netByLedger` |
| `GstService` · `VatService` · `RatioService` · `BudgetService` (summaries) | **inherit** | delegate to `netByLedger` |

### Inventory
| `StockService::fold` | **widen** | the stock-movement choke |
| `StockService::godownQuantity` | **widen** | per-godown quantity |
| `closingBalance` / `totalClosingValue` / `weightedAverageRate` | **inherit** | fold through `fold`/movement reads |
| `StockItemMovement` (Livewire) | **widen** | item movement report |

### Bill-wise / receivables-payables
| `BillService::bills` | **widen** | open-bill aggregation |
| `BillService::billVouchers` | **widen** (`whereHas`) | bill drill |
| `BillService::outstandings` | **inherit** | delegates to `ledgerClosings` |

### Cost centres · Day Book · orders · forex · groups
| `CostCentreService::breakup` / `centreVouchers` | **widen** | |
| `DayBook::rows` | **widen** | the register |
| `OrderService::outstanding` | **widen** | SO/PO reconciliation |
| `ForexService::bookedRateForBill` / `billPendingPaise` | **widen** (`applyByVoucher`) | reaches only `voucher_entries` |
| `InterCompanyLotService` (4 sites) | **real-only** (`whereNull`) | consolidation lots read real books only |
| `GroupConsolidationService` (3 sites) | **real-only** (`whereNull`) | group reports are actuals |

### TDS — real-books-only threshold state
A provisional payment computes its TDS against **real** prior state and never mutates it:
| `TdsService::clientLedgerCache` · `qualifyingPriorPaise` · `priorStateForMonth` · `summary` · `remittedPaise` · `deducteeVouchers` | **real-only** (`whereNull`) | 6 read joins |
| `TdsService::persist` | ytd roll-forward **gated** on `scenario_id === null` | a provisional voucher records its `tds_deductions` row but does **not** touch `tds_deductee_ytd` |
| `TdsService::reverseFor` | ytd subtract **gated** on `scenario_id === null` | mirror — reversing a provisional never subtracts from real ytd |

### Return files — actuals-only + REFUSE
Statutory files must never contain a what-if, and must refuse rather than silently drop one:
| `GstReturnService::gstr1` / `gstr3b` | **REFUSE** if a provisional voucher is in-period; body wrapped `runWith([])`; `documents`/`buildDocs` `whereNull` | |
| `Form26qExporter::gather` | **REFUSE** (pushes an error, returns `[]`); `deductions`/`challans` `whereNull` | |
| `NepalVatReturnService::return` | **REFUSE** if provisional in-period; `buildDocumentCounts` `whereNull` | |

### Sync — provisional never leaves the server
| `SyncService::snapshot` | **real-only** (`whereNull('scenario_id')`) | provisional vouchers are never pushed to desktops |

### The write path (unchanged posting, one new column)
| `VoucherScreen::validatePayload` | accepts `scenario_id` (honoured only when the F11 flag is on + it's a scenario of this company; else dropped → voucher stays real) |
| `VoucherScreen::writeVoucherGraph` | stamps `scenario_id` on the created voucher |
| `VoucherScreen::post` | wraps the persist in `runWith([$sid])` so the cost / TDS / bill reads *during the write* see real + this-scenario history |
| `nextNumber` | **unfiltered** (counts all vouchers) — one sequence shared by real + provisional avoids number collisions when a scenario is later promoted |

---

## Promotion — one-way, transactional, TDS-aware

`ScenarioService::promote(Scenario, ?userId)` runs entirely inside one DB transaction:

1. **Re-validate every voucher** (`validateForPromotion`): the books still balance, every ledger it
   touches still exists, and any TDS section it used is still in force. This catches the world changing
   since the provisional voucher was created (a deleted ledger, an expired section). **If any voucher
   fails, the whole transaction rolls back and nothing changes** — with per-voucher reasons surfaced
   via `ScenarioPromotionException`.
2. **Flip each voucher to real**: `scenario_id → null`, stamp `promoted_from_scenario_id`.
3. **Re-run TDS**: for a promoted TDS-deducting payment, delete the provisional `tds_deductions` row
   *while still tagged* (so `reverseFor` correctly skips ytd), then `TdsService::persist` against the
   now-real state — which recomputes the deduction and rolls `tds_deductee_ytd` forward **exactly
   once**. No duplicate deduction row.
4. **Log** to `scenario_promotions` (denormalised name + count, survives scenario deletion).
5. **Deactivate** the now-empty scenario (drops out of pickers; its audit trail survives).

**Never reversible** — there is no un-promote. The `promoted_from_scenario_id` stamp is the only trace.

---

## Screens & keyboard integration

The picker + three management screens, each through a controller `shell()` + `scenariosGate` →
wrapper blade → `<livewire:*>`, inheriting the Phase-1 right button bar + status bar.

- **Scenario picker + inclusion banner** (`App\Livewire\ScenarioPicker`) — injected by the app layout
  on **every** report (not the scenario-management screens themselves), gated by the F11 flag. Toggle a
  scenario chip → the selection is written to the session and the report reloads with it folded in; a
  prominent amber banner declares "viewing **with scenarios** … not your real books" whenever any is
  selected.
- **Scenario Master** (`reports.scenario-master`) — create (F9), activate/deactivate, and **typed-
  delete** (type the exact name; deletes the scenario *and* its provisional vouchers). Shows each
  scenario's provisional-voucher count.
- **Scenario Manager** (`reports.scenario-manager`) — pick a scenario, review its provisional
  vouchers, and **promote** (type `PROMOTE` to confirm the irreversible flip). On failure the per-
  voucher reasons are listed and nothing changed.
- **Scenario Impact** (`reports.scenario-impact`) — real vs real+scenario for six headline figures
  (net profit, income, expenses, assets, liabilities, stock) with signed/coloured deltas, the per-
  ledger closing deltas (drillable to ledger vouchers), and the scenario's voucher list. F2 as-of,
  ↑↓/Enter to drill.

**Voucher entry** — when the flag is on, the entry screen shows a **Scenario** selector (default
"— Real books —"). Picking one turns the voucher provisional: a subtle amber tint + a banner make the
what-if state unmistakable, and the tag is sticky across consecutive new entries. Re-opening a
scenario voucher re-shows its tag.

**Gateway.** `Shell::scenariosEnabled` gates `scenarioNav` (Alt+G Go To — "Scenarios" section) and a
Gateway hub tile. When the flag is off the menu entries vanish and the screens redirect to the
Gateway.

---

## Acceptance checklist → where it's proven

`DB_DATABASE=tenant<slug> php artisan zerobook:prove-scenarios` (50 assertions, 14 sections):

| Criterion | § |
|---|---|
| F11 gate — `enabled()` reflects the flag | 0 |
| **Byte-identical default** — provisional excluded from P&L / BS | 1 |
| View-with — context widens the read, then reverts | 2 |
| Multiple scenarios combine additively | 3 |
| Impact report — real vs +scenario delta, drillable, voucher count | 4 |
| Bill-wise — provisional bill hidden by default, shown under context | 5 |
| Inventory — provisional stock movement excluded by default | 6 |
| Return files **refuse** with a provisional in-period (GSTR-1 + 26Q) | 7 |
| TDS — provisional computes a deduction but does **not** roll ytd; promotion rolls the frozen (GL-consistent) amount once, never recomputed | 8 |
| Promotion — one-way; real books updated; scenario logged + deactivated | 9 |
| Promotion is **transactional** — a broken voucher rolls back the whole promotion | 10 |
| Sync excludes provisional vouchers — **snapshot AND incremental pull** | 11 |
| Scenarios are company-scoped | 12 |
| Report picker filters stale / inactive / cross-company selections | 13 |

**Browser-verified:** the picker toggles a scenario and the Balance Sheet / P&L recompute with the
banner; a provisional voucher posts under a scenario (tint + banner) and is absent from the default
report; the Impact report shows the delta; promotion folds it in and closes the scenario; the F11
flag shows/hides everything. 0 console errors.

---

## Files

**Schema (tenant) + `_docs/phase15c_tenant_schema.sql`:** migration
`…2026_07_25_000001_add_scenarios` (`scenarios`, `scenario_promotions`, `vouchers.scenario_id` +
`promoted_from_scenario_id`, `company_features.scenarios`); models `Scenario`, `ScenarioPromotion`,
`Voucher` (fillable + `scenario()` relation).

**Support / services:** `App\Support\ScenarioContext`; `App\Services\ScenarioService` (CRUD, session
picker, `promote`, `impactReport`); `App\Exceptions\ScenarioPromotionException`.

**Scenario-aware reads (the catalog):** `BalanceService`, `StockService`, `StockItemMovement`,
`BillService`, `CostCentreService`, `DayBook`, `OrderService`, `ForexService`, `TdsService`,
`GstReturnService`, `Form26qExporter`, `NepalVatReturnService`, `SyncService`,
`InterCompanyLotService`, `GroupConsolidationService`.

**Write path:** `App\Livewire\VoucherScreen` (validate + normalise + tag + `runWith` on post +
bootData scenarios); `resources/js/vouchers/screen.js` (selector state + payload field);
`resources/views/livewire/voucher-screen.blade.php` (selector + tint + banner).

**F11 gate:** `CompanyFeature` (`$casts` + `toFlags`), `Livewire\FeaturesScreen` (prop + mount +
save), `features-screen.blade.php` (`items[]`), the migration column, `ProveMultiCompanyCommand`
expected-flags set.

**Screens:** `App\Livewire\ScenarioPicker` + `App\Livewire\Scenarios\{ScenarioMaster, ScenarioManager,
ScenarioImpact}`; wrapper blades `resources/views/reports/scenario-{master,manager,impact}.blade.php`;
Livewire views `resources/views/livewire/scenario-picker.blade.php` +
`resources/views/livewire/scenarios/{master,manager,impact}.blade.php`; Alpine controllers
`resources/js/scenarios/screens.js` (registered in `resources/js/app.js`); layout injection in
`resources/views/layouts/app.blade.php`.

**Routing / menu:** `ReportsController` (3 methods + `scenariosGate`), `routes/tenant.php` (3 routes),
`App\Support\Shell` (`scenariosEnabled` + `scenarioNav` + hub tile).

**Proof:** `App\Console\Commands\ProveScenariosCommand`.

**Existing environments:** `php artisan tenants:migrate` + `npm run build`. No central migration.

---

## Scope — NOT in 15C (honest notes)

- **Nested scenarios** (a scenario built on top of another) — the context takes a flat id list; a
  hierarchy needs a resolution model, deferred.
- **Auto-suggested scenarios**, **scheduled/recurring scenarios**, **scenario templates**, and
  **cross-company scenarios** — each a feature in its own right; out of scope.
- **Scenario export/import** — belongs with the general export subsystem.
- **Un-promote / promotion reversal** — deliberately impossible; promotion is a one-way commit by
  design (correcting a promoted voucher is an ordinary alter/cancel on the now-real voucher).

*15C completes the Phase 15 advanced-reporting tail (15A Budgets · 15B Ratio Analysis · 15C
Scenarios).*
