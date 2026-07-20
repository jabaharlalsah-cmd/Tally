# ZeroBook — Phase 8B: Inventory-Workflow Vouchers

**Goal:** the six order-to-delivery vouchers Tally users live in — **Sales Order,
Purchase Order, Delivery Note, Receipt Note, Rejections In, Rejections Out** — posting
through the **one shared path** (`VoucherScreen::post()`) with **ZERO accounting
impact**. None of them writes a `voucher_entries` row, so the Trial Balance, P&L and
Balance Sheet are provably untouched. Orders are pure **commitments** (no stock);
Delivery/Receipt Notes and Rejections **move real stock** at the server-computed
weighted-average cost, but still post no ledger entry.

Two things are easy to get wrong, so both are enforced server-side and asserted to the
unit by `zerobook:prove-order-flow`:

1. **Reconciliation** — an order is partly delivered over several notes; `delivered_qty`
   must always equal Σ its fulfillments, and you **cannot deliver more than ordered**.
2. **The double-stock safeguard** — a Sales/Purchase invoice that references a
   Delivery/Receipt Note must **not** move the stock again (the note already did).

```bash
php artisan zerobook:prove-order-flow    # the worked numeric proof (all assertions pass)
```

---

## Step 0 — audit + integration plan

**Audit — all thirteen prior proves pass, before and after 8B.** The four
tenant-architecture proves run directly (`prove-notes`, `prove-sync`,
`prove-multi-tenant`, and the new `prove-order-flow`); the nine pre-tenancy proves
(`prove-balance`, `prove-sales-purchase`, `prove-gst`, `prove-billwise`,
`prove-costcentre`, `prove-vat`, `prove-item-invoice`, `prove-stock-journal`,
`prove-inventory-integration`) run against a provisioned tenant's database
(`DB_DATABASE=tenant<slug>`), since after the 7B restructure business data lives only in
tenant DBs. **All green — no regression from the shared-path changes.**

**Built against the real, current shapes:**
- `vouchers.type` is a **DB ENUM** → widened (raw `MODIFY`, all existing values kept) to
  admit the six new types. `Voucher::TYPES` extended; new consts `ORDER_TYPES`,
  `STOCK_WORKFLOW_TYPES`, `INVENTORY_WORKFLOW_TYPES`; `stockDirection()` /
  `movementTypeFor()` extended for the four stock-moving types.
- `VoucherScreen::post()` / `validatePayload()` — **the same path**. A new `$isWorkflow`
  branch requires item lines, forces the ledger side empty, and returns *before* any
  balance/tax/bill/cost check runs (there is no ledger side to satisfy).
- `writeVoucherGraph()` / `persistAlter()` — three routes: **Orders** →
  `OrderService::persistOrderLines()` (no entries, no stock); **stock-workflow** →
  `StockService::persistItems()` + `OrderService::applyFulfillment()` (no entries);
  **accounting** → the existing path, now guarded by the double-stock safeguard.
- `StockService::persistItems()` — a **Rejection In** brings stock back at the original
  locked cost (like a Credit Note), so it never distorts the running average.
- `reference_voucher_id` (the self-FK added in 8A) is reused to chain Delivery/Receipt
  Note → Order and Sales/Purchase invoice → Delivery/Receipt Note.

---

## The six vouchers — direction & effect (get this exactly right)

| Voucher | Key | Party | Stock | Accounting | Reconciles |
|---|---|---|---|---|---|
| **Sales Order** | Alt+F6 | Customer (Debtor) | — none — | **none** | creates `order_lines` |
| **Purchase Order** | Alt+F7 | Supplier (Creditor) | — none — | **none** | creates `order_lines` |
| **Delivery Note** | Alt+F8 | Customer | **OUT** @ w-avg cost | **none** | fulfils a Sales Order |
| **Receipt Note** | Alt+F5 | Supplier | **IN** @ entered (provisional) rate | **none** | fulfils a Purchase Order |
| **Rejections In** | Ctrl+F5 | Customer | **IN** @ original locked cost | **none** | (opt.) ref a Delivery Note |
| **Rejections Out** | Ctrl+F6 | Supplier | **OUT** @ current w-avg | **none** | (opt.) ref a Receipt Note |

`stockDirection()` sends goods **OUT** on Sales/Debit-Note/**Delivery Note**/**Rejection
Out** and **IN** on Purchase/Credit-Note/**Receipt Note**/**Rejection In**. A Delivery
Note draws its cost at the weighted average in force (a sale never shifts the average); a
Receipt Note uses the **entered rate** as a provisional cost (**Approach A** — the actual
price is confirmed later on the Purchase invoice); a Rejection In comes back at the
original locked cost so the average is undistorted.

---

## Reconciliation — `order_lines` / `order_fulfillments`

An order writes one `order_lines` row per item (`ordered_qty`, `delivered_qty = 0`,
`rate`, `amount`). Each Delivery/Receipt Note that references the order adds an
`order_fulfillments` row per matched item and pushes `delivered_qty` up. **`delivered_qty`
is always exactly Σ its fulfillments**, so:

* **pending** = `ordered_qty − delivered_qty` (the Order Outstanding figure);
* **over-delivery is refused** — `OrderService::applyFulfillment()` takes a *locking*
  read of the order lines and throws (rolling the whole voucher back) if this note would
  push the total past `ordered_qty`;
* **alter** a note → reverse its fulfillments (re-sum the affected lines) then re-apply;
* **cancel** a note → the `Voucher::deleting` hook calls `reverseFulfillment()` *before*
  the FK cascade, so `delivered_qty` rolls back deterministically and the order becomes
  outstanding again.

A fully-delivered order drops off the Orders Outstanding report; the order voucher itself
is never deleted (audit trail).

---

## The double-stock safeguard — the paranoid part

If a Sales invoice references a Delivery Note (or a Purchase invoice a Receipt Note), the
goods **already moved** on the note. The invoice must post **accounting only** and write
**zero** `stock_entries`. Both halves are implemented:

* **Client half** (`resources/js/vouchers/screen.js` + Blade) — a Sales/Purchase invoice
  offers an *"Against Delivery/Receipt Note"* picker; choosing one pre-fills the delivered
  lines and shows a **"Stock already moved — this invoice will not move stock again"**
  banner.
* **Server half** (`VoucherScreen`) — `referencesStockMovement()` detects the reference
  and **skips `persistItems()`** for that invoice; then `assertNoDoubleStock()` — the
  belt-and-suspenders assertion — throws (rolling the transaction back) if *any*
  `stock_entries` row exists for such an invoice, so a future refactor can never silently
  reintroduce double stock.

The proof posts a Sales invoice referencing a Delivery Note and asserts
`StockEntry::where('voucher_id', $invoice)->count() === 0`, the on-hand quantity and the
weighted average **unchanged** — while a *control* invoice with no reference still moves
stock exactly as before (the safeguard is targeted, not global).

---

## The worked numeric proof — `zerobook:prove-order-flow`

Widget averaging **60** (buy 100@50, buy 100@70); GST intra-state @ 18%.

```
Sales Order 20 @ 100        order_lines: ordered 20, delivered 0, pending 20
                            0 stock entries · 0 ledger entries · on-hand still 200 · TB balances
Delivery Note (ref SO) 12   stock OUT qty 12 @ cost 60 (=720) · 0 ledger entries
                            delivered_qty 12, pending 8 · 1 fulfillment linked · on-hand 188 · avg still 60
Over-deliver 10 more        REFUSED (12+10 > 20) · voucher rolled back · delivered_qty still 12 · on-hand 188
Delivery Note (ref SO) 8    delivered_qty 20, pending 0 · order drops OFF the outstanding report · on-hand 180
Sales invoice (ref DN#1)    Dr Cust 1,416 / Cr Sales 1,200 / Cr Output CGST 108 / Cr Output SGST 108
   *** SAFEGUARD ***        stock entries written = 0 · on-hand UNCHANGED 180 · avg UNCHANGED 60 · TB balances
Control (unreferenced) inv  writes its stock entry (1) — the safeguard is targeted, not global · on-hand 175
Alter DN#1 12 → 10          delivered_qty 18 (10+8), pending 2 · one fulfillment (replaced) · on-hand 177
Cancel DN#1                 fulfillments gone · delivered_qty 8, pending 12 · on-hand 187 (10 restored)
Rejections Out 3            stock OUT qty 3 @ 60 · movement_type purchase_return · on-hand 184
Rejections In 2             stock IN qty 2 @ 60 (NOT the client rate) · sales_return · avg still 60 · on-hand 186
Purchase Order 50 @ 45      0 stock / 0 ledger · pending 50
Receipt Note (ref PO) 30    stock IN qty 30 @ 45 (provisional, Approach A, =1,350) · received 30, pending 20
                            on-hand 216 · avg → 57.9167 (the 30 @ 45 blended in)
Purchase invoice (ref RN)   Dr Purchase 1,350 / Cr Supp 1,593 · *** stock entries = 0 *** · on-hand UNCHANGED
Reports & pickers           SO outstanding pending 12 (=1,200) · PO outstanding pending 20 (=900)
                            referenceOrder(PO) pre-fills pending 20 · bootData ships the pickers
Global invariants           EVERY workflow voucher wrote 0 ledger entries · EVERY order wrote 0 stock · TB balances
```

`prove-order-flow` provisions a throwaway tenant, runs entirely through
`VoucherScreen::post()` (and the real `OrderService` / report / picker code), and tears
down.

---

## Key bindings

Tally's own inventory-voucher keys — **verified free** before binding: a grep showed
`Alt+F6`/`Alt+F8` were only *hidden* "muscle-memory" alternates for Receipt/Sales
(removed, replaced by their authentic Order/Delivery meanings); `Alt+F5`/`Alt+F7` and
`Ctrl+F5`/`Ctrl+F6` were unbound.

| Alt+F5 Receipt Note · Alt+F6 Sales Order · Alt+F7 Purchase Order · Alt+F8 Delivery Note · Ctrl+F5 Rejections In · Ctrl+F6 Rejections Out |
|---|

All six also appear in the Gateway (E/M/N/U + Orders Outstanding on Q) and in Go To
(search *order*, *delivery*, *receipt*, *rejection*, *challan*, *grn*, *outstanding*).

---

## UI + reports

* The voucher screen is the invoice screen’s item grid **without** the ledger/tax/revenue
  parts: a prominent **heading + sub-label** (so a Delivery Note is never mistaken for an
  invoice), an optional **reference picker** (Delivery/Receipt Note → its Order, showing
  each order’s **pending** quantity; picking one pre-fills the outstanding lines), the
  party (Debtor for Sales-side, Creditor for Purchase-side), and the item grid. Entry is
  **0-network** — only accept, drill, and the reference pre-fill (a single lookup) touch
  the server. Alt+I add item, Alt+R remove, Ctrl+A post & continue.
* **Sales / Purchase Orders Outstanding** (`/reports/orders-outstanding?scope=sales|purchase`,
  Go To + Gateway **Q**) — every order still awaiting delivery, its open lines
  (ordered / delivered / **pending** / rate / pending value) and total, drillable to the
  order. Reads the reconciliation cache, never the ledger.
* **Print** — a dedicated light document (`print-workflow`): Orders show
  ordered/delivered/pending; Delivery/Receipt Notes & Rejections show the in/out movement.
  The heading swaps to the voucher’s own name and every footer states that it posts
  nothing to the ledgers.
* Day Book lists all six (they are ordinary vouchers on the shared path); the accounting
  reports correctly **ignore** them (zero entries).

---

## Schema

Tenant migration
`database/migrations/tenant/2026_07_13_000001_add_inventory_workflow_vouchers.php`:
enum widen + `order_lines` + `order_fulfillments`. `reference_voucher_id` already exists
(Phase 8A) and is reused. Existing tenants: `php artisan tenants:migrate`. phpMyAdmin
equivalent: `_docs/phase8b_workflow_schema.sql`.

---

## Files delivered / modified

```
delivered:
database/migrations/tenant/2026_07_13_000001_add_inventory_workflow_vouchers.php
app/Models/OrderLine.php · app/Models/OrderFulfillment.php
app/Services/OrderService.php                 persistOrderLines / applyFulfillment / reverseFulfillment / outstanding
app/Console/Commands/ProveOrderFlowCommand.php
app/Livewire/OrdersOutstanding.php
resources/views/livewire/orders-outstanding.blade.php
resources/views/reports/orders-outstanding.blade.php
resources/views/vouchers/print-workflow.blade.php
_docs/phase8b_workflow_schema.sql · _docs/PHASE8B_README.md

modified (surgical, no parallel path):
app/Models/Voucher.php               6 TYPES + ORDER/STOCK_WORKFLOW/INVENTORY_WORKFLOW consts + stockDirection/movementTypeFor/orderLines/deleting hook
app/Livewire/VoucherScreen.php       $isWorkflow validation + normalization; writeVoucherGraph/persistAlter routing; referencesStockMovement/assertNoDoubleStock; referenceOrder + picker lists + bootData
app/Services/StockService.php        persistItems IN cost now covers rejection_in
app/Http/Controllers/ReportsController.php + routes/tenant.php     /reports/orders-outstanding
app/Http/Controllers/VouchersController.php                       workflow print branch
app/Support/Shell.php                Go To (6 vouchers + 2 reports) + Gateway (E/M/N/U/Q)
resources/js/engine/engine.js        Alt+F5/F6/F7/F8, Ctrl+F5/F6 bindings (old hidden alternates removed)
resources/js/vouchers/screen.js      workflow getters/init/payload/reference pickers + double-stock client skip
resources/views/livewire/voucher-screen.blade.php   workflow section + invoice double-stock picker/banner
```

No stubs, no TODOs. Six new voucher types, **zero** lines of `voucher_entries`. All
fourteen proves green; `npm run build` clean.
