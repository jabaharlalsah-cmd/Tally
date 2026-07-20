# Phase 13 — FIFO / LIFO general costing

The skipped phase, closed. `stock_items.costing_method` has existed since 6A but only `weighted_average`
was implemented. Phase 13 lights up `fifo` (oldest lot depletes first) and `lifo` (newest first) for
items that need specific-lot valuation — pharma, food, chemicals, anything where the running average
is not good enough. **Weighted-average items are completely unchanged: they take the same code path,
write no lots, and every one of the 30 prior proofs still passes byte-for-byte.**

Phase 13 does not build a new lot engine — it **generalizes the one 12C-1 already built** for
inter-company inventory. The same table + fold now serve both purposes.

---

## Step 0 — audit result

Before writing 13: the lot-critical subset of the battery (inter-company-lots, consolidation,
stock-journal, inventory-integration, item-invoice, sales-purchase, scenarios) re-run green; the full
suite was 30/30. **After 13: 31/31** — `zerobook:prove-fifo-lifo` adds **40 assertions across 11
sections**. The rename + generalization changed no weighted-average behaviour (proven by every prior
stock/consolidation proof passing unchanged).

**Adversarial self-review.** A multi-agent review (find → adversarially verify) surfaced, and this
build then fixed, **2 confirmed critical bugs** before delivery — each now guarded by a new proof
assertion:

1. **Cancel-path refold was dead after the rename.** `Voucher`'s `deleting` hook gated its lot-item
   capture on `Schema::hasTable('inter_company_stock_lots')` — a table the Phase-13 migration
   *renamed* to `stock_lots`, so the check was permanently false and **no cancel ever refolded lot
   state** (FIFO/LIFO `remaining_qty` + OUT costs, and 12C-1 provenance). The existing cancel proofs
   didn't catch it because they only cancel a voucher whose own lot cascade-deletes (where the cascade
   alone yields the asserted state). Fixed the guard to `stock_lots`; the proof now cancels a **sale**
   and asserts the lot it drew from is re-derived to full.
2. **A FIFO/LIFO master opening balance silently vanished.** `fifoLifoClosing` values a FIFO/LIFO item
   as `Σ lots` only, and an opening balance is never a lot — so any opening stock dropped out of the
   Balance Sheet. Rather than mis-value it, the fix **forbids a non-zero opening on a FIFO/LIFO item**
   at the source (model `saving` guard + form validation): opening tranches are entered as dated
   opening purchase vouchers, each a proper cost layer with its own date and rate. Weighted-average
   items keep their opening balance unchanged.

---

## The pivotal fact — ZeroBook is periodic-inventory

There is **no per-sale COGS posting to the general ledger**. A sale posts only Dr Debtor / Cr Sales
(+ tax). The OUT `stock_entries` row's `rate`/`value` is the **cost of goods**, and it feeds
**closing-stock valuation**, not the GL. COGS emerges periodically as `opening + purchases − closing`.

Consequences:
- FIFO/LIFO changes the OUT cost → changes **closing stock** → changes **Gross Profit**. It never
  touches the Trial Balance's GL side, so **TB still balances** structurally (`value = rate × qty`
  holds on every row).
- **Tax is unaffected.** GST/VAT/TDS compute on the sale rate, never on cost. Return files never
  carried cost. No tax service changes.

---

## Table generalization (A)

`inter_company_stock_lots` → **`stock_lots`**; model `InterCompanyStockLot` → **`StockLot`**; service
`InterCompanyLotService` → **`StockLotService`**. `source_company_id` / `source_voucher_id` /
`source_cost_paise` became **nullable** (a regular FIFO/LIFO lot has no groupmate source). A
**`costing_method`** column snapshots the lot's depletion order at write time (existing inter-company
rows default `'fifo'` — exactly what 12C-1's fold implements). Added index
`(stock_item_id, costing_method, remaining_qty)`. The self-referencing `parent_lot_id` FK (godown-
transfer genealogy) follows the rename automatically. Migration
`…2026_07_26_000001_generalize_stock_lots` + `_docs/phase13_tenant_schema.sql`.

**One row per IN movement of a FIFO/LIFO item** (source_* null); inter-company IN movements still
write lots with their source populated (12C-1). Weighted-average items write **no** regular lots.

---

## OUT-cost dispatch (B, C) — the branch point

`StockService::persistItems` (and the consumption / physical-shortage OUT paths) switch on the item's
`costing_method`:

| Method | OUT cost |
|---|---|
| `weighted_average` | `weightedAverageRate()` — the running average (unchanged) |
| `fifo` | consume lots **oldest-first** (`received_date, id` ASC), cost = `Σ(taken × lot rate) / out qty` |
| `lifo` | consume lots **newest-first** (`received_date, id` DESC), same weighting |

The consumed lots' `remaining_qty` is decremented in the same transaction, and the OUT row's
`rate`/`value` is rewritten to the derived cost (so `value = rate × qty`). **Over-depletion** (OUT qty
> Σ available lots — negative stock, which Tally permits) depletes everything available and costs the
uncovered units at the **item's opening rate**; it never blocks the sale. Godown transfers relocate
lots (child lots at the destination inherit the parent's `received_date`, so a later sale from the
destination still depletes the oldest tranche).

---

## Closing stock per method (D)

`StockService::closingBalance` / `totalClosingValue` branch:
- weighted-average → the existing running fold;
- FIFO/LIFO → **`Σ remaining_qty × rate`** over the item's lots, replayed to the as-of date in the
  item's own depletion order (LIFO replays newest-first — the replay MUST mirror the post-time order
  or a back-dated as-of mis-values). A company can mix all three methods; each item is valued
  independently and the Stock-in-Hand total is their sum.

---

## Scenario-awareness preserved (15C)

Stored `remaining_qty` is the **real books**: a provisional voucher never mutates it (`refoldItem` and
`consume` force `ScenarioContext::runWith([])` / real-only filters). The **read** path
(`remainingLotsFor`) is scenario-aware via `ScenarioContext::applyByVoucher` — so a provisional FIFO
purchase writes a lot that is **invisible to the default (real-books) depletion**, and participates
only when its scenario is selected in the report picker. (This also closed a latent 12C-1 gap where
the roots query wasn't scenario-filtered.)

---

## Costing-method lock (E)

`costing_method` is editable at creation and **locked once any `stock_entry` exists** — changing a live
item's method silently revalues history, so it is a migration, not a UI toggle. Enforced in three
layers: the Stock Item form renders the field **read-only** with the helper text once movements exist;
`StockItemWorkspace::saveAlter` forces the stored method on a locked item; and `StockItem::updating`
throws `CostingMethodLockedException` as the final server authority. Message: *"Costing method is
locked once stock movements exist — recreate the item with a new method if a change is required."*
The "recreate the item" workaround (delete all movements → change succeeds) is proven.

---

## Reporting (F, G)

- **Stock Summary** grows a **Costing Method** column per item (Weighted Average / FIFO / LIFO).
- **Lot Ledger** (new report, Reports → Inventory + Go To keywords *lot ledger, fifo, lifo, lots*):
  for a chosen FIFO/LIFO item, every lot with received date, receiving voucher (drillable), godown,
  original qty, remaining qty, rate and value; a weighted-average item shows *"No lots — this item
  uses weighted-average costing."*

---

## The worked scenarios (from `zerobook:prove-fifo-lifo`)

All three items buy **100 @ ₹50** then **100 @ ₹70** (no opening stock).

| Item | Method | Sale | OUT rate | OUT value | Remaining | Stock-in-Hand |
|---|---|---|--:|--:|---|--:|
| **WidgetA** | FIFO | sell 50 | **50** | **2,500** | lot1 = 50, lot2 = 100 | |
| | | sell 100 | **60** (50@50 + 50@70) | **6,000** | lot1 = 0, lot2 = 50 | **3,500** (50 × 70) |
| **WidgetC** | LIFO | sell 50 | **70** | **3,500** | lot1 = 100, lot2 = 50 | |
| | | sell 100 | **60** (50@70 + 50@50) | **6,000** | lot1 = 50, lot2 = 0 | **2,500** (50 × 50) |
| **WidgetB** | Weighted-avg | sell 150 | **60** | **9,000** | 50 units | **3,000** (50 × 60) |

**Balance-Sheet tie-out:** total Stock-in-Hand = 3,500 + 3,000 + 2,500 = **₹9,000**; the Balance Sheet
balances. WidgetB (weighted-average) wrote **0 lots**.

Also proven: the costing-method lock (refused + the recreate workaround); altering a FIFO purchase
(100 → 80) re-derives the dependent OUT depletions + closing by replay; cancelling a FIFO purchase
(lot cascade-deleted, OUTs replay against the remainder); a godown transfer preserving FIFO lot age
(a sale from Warehouse-2 still depletes the oldest lot at ₹50); the 15C scenario isolation (a
provisional FIFO purchase leaves the default `remainingLotsFor` count unchanged and participates only
under its scenario); and per-company lot scoping.

---

## Acceptance checklist → §

`DB_DATABASE=tenant<slug> php artisan zerobook:prove-fifo-lifo` (40 assertions, 11 sections):

| Criterion | § |
|---|---|
| FIFO worked scenario — every OUT cost + lot remaining + Stock-in-Hand | 1 |
| LIFO worked scenario | 2 |
| Weighted-average baseline unchanged + writes no lots | 3 |
| Balance-Sheet Stock-in-Hand ties out (₹9,000) + TB balances | 4 |
| Costing-method lock (refused + recreate workaround) | 5 |
| Alter a FIFO purchase — replay re-derives | 6 |
| Cancel a FIFO purchase (lot deleted) **and cancel a sale (lot un-depleted by refold)** | 7 |
| Godown transfer preserves FIFO lot age | 8 |
| Scenario isolation of a provisional FIFO purchase (15C) | 9 |
| Per-company lot scoping | 10 |
| FIFO/LIFO items reject a master opening balance | 11 |

**Browser-verified:** the Stock Item form shows the costing-method dropdown (read-only once locked);
Stock Summary shows the Costing Method column; the Lot Ledger lists an item's lots and shows the
weighted-average note. 0 console errors.

---

## Files

**Schema:** migration `…2026_07_26_000001_generalize_stock_lots` + `_docs/phase13_tenant_schema.sql`;
model `App\Models\StockLot`.

**Costing engine:** `App\Services\StockLotService` (writeLotForRegular, costRegularOut/depleteForCost,
fifoLifoClosing, scenario-aware remainingLotsFor, generalized refoldItem); `App\Services\StockService`
(persistItems dispatch, closingBalance branch, consumption/physical/transfer FIFO-LIFO paths).

**Lock:** `App\Models\StockItem` (updating observer + `hasStockMovements` + `COSTING_LOCKED_MESSAGE`);
`App\Exceptions\CostingMethodLockedException`; `App\Livewire\StockItemWorkspace` (dropdown + alter
lock).

**Reports:** `App\Livewire\Reports\StockSummary` (Costing Method column); `App\Livewire\Reports\LotLedger`
+ its wrapper/livewire blades; `ReportsController::lotLedger`; `routes/tenant.php`; `App\Support\Shell`
Go To entry.

**Proof:** `App\Console\Commands\ProveFifoLifoCommand`.

**Existing environments:** `php artisan tenants:migrate` + `npm run build`. No central migration.

---

## Scope — NOT in 13 (honest notes)

- **Mid-life method changes / weighted-average items backfilling lots** — locked once stock exists;
  recreate the item (documented, enforced three ways).
- **Opening stock on a FIFO/LIFO item** — per the periodic model, closing is `Σ lots`, so a FIFO/LIFO
  item is **required to start at zero opening** (enforced by a model `saving` guard + form validation —
  it is refused, never silently dropped). Seed opening stock as dated opening purchase vouchers, one
  per cost layer — the correct way to establish FIFO/LIFO tranches with their own date and rate.
- **Manual (specific) lot selection on OUT** — the user picking which batch to deplete (pharma batch
  selection) rather than server-computed FIFO/LIFO order; a genuinely different UX, deferred.
- **Lot-expiry tracking** — use-by dates for perishables; a separate pharma feature.
- **Standard-cost costing** — a fixed cost with variance to a standard-cost account; niche, deferred.

*Phase 13 closes the loop dropped between 12C and 14. The 8-through-15 tail plus 13 is now complete.*
