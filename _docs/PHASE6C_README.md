# ZeroBook — Phase 6C: Stock Journal + Physical Stock vouchers

The remaining stock-movement voucher types. **Stock Journal** (inter-godown
**Transfer**, and **Consumption**/issue) and **Physical Stock** (stock-take
variance reconciliation) are **pure quantity/value movements with no ledger side** —
they write rows to `stock_entries` only, never to `voucher_entries`, and they extend
the *same* weighted-average engine + locking discipline Phase 6B built and hardened.

---

## Step 0 — audit & integration plan (done before building)

All six prior proofs **plus** `prove-item-invoice` passed; regime unchanged; DB clean.
Confirmed against the **real current code** (which the 6B review evolved):
`weightedAverageRate(itemId, asOf, exclude, $lock=false)` with a post-path locking
read; `persistItems` locks item masters. The `vouchers.type` **enum** is widened by a
raw `ALTER … MODIFY` (mirrored from 5A). The balance gate (`lines required|min:2` +
`Dr>0`) **would reject a zero-ledger voucher** — so it is made conditional on
non-stock types (the careful fix below). `BillService`/`CostCentreService::persist`
no-op on empty lines.

---

## One posting path, extended (never forked)

`VoucherScreen::post()` is still the single entry. For a stock voucher the payload
carries `lines: []` and a `movement` object; inside the **same** `DB::transaction` as
every other voucher, `persistStockMovement()` dispatches to `StockService`. The
`voucher_entries` balance gate is **skipped only for stock types** — every accounting
voucher still needs its balanced pair.

### Zero-ledger-line balance gate (Step 0.4)

`validatePayload` computes `$isStock = type ∈ {stock_journal, physical_stock}`:
- `lines` rule becomes `['nullable','array']` for stock, still `['required','array','min:2']` otherwise.
- The after-hook, for stock, runs `validateStockMovement()` and **returns before** the
  P&L-guard, balance, GST/VAT, bill and cost checks — so `0 = 0` is trivially fine and
  no false rejection fires. The normalisation loop guards `?? []`.
- A stock payload's stray `lines`/`items` are **ignored**: `items` normalise only for
  invoice types, and the client sends `lines: []` — so no ledger row or item row can be
  smuggled onto a stock voucher.

### `StockService` — reuses `fold`/`weightedAverageRate`/`lockItems`, no second path

- `lockItems($ids)` — extracted from `persistItems`; the single item-master `lockForUpdate`
  gate every stock-writing path shares.
- `godownQuantity($item, $godown, $asOf, $exclude, $lock)` — per-**godown** running qty
  (opening counted only at the item's opening godown). Value/average stay **item-level**.
- `persistTransfer` — compute the item's weighted-average **once** (locked, current read),
  then post **OUT(source) + IN(dest) at that same rate**, both tagged `movement_type =
  'transfer'`. The item-level `fold()` **skips transfer rows**, so a transfer is
  value-neutral *by construction* — the item total qty/value and average are unchanged no
  matter how the average later shifts; only godown-level quantity moves (`godownQuantity`
  still counts them). *(This skip was added by the adversarial review — see below.)*
- `persistConsumption` — one **OUT** at the locked weighted-average (same call a sale uses).
- `persistPhysicalStock` — book = `godownQuantity` (locked); variance = counted − book;
  **excess → IN, shortage → OUT**, both valued at the **item-level weighted-average** so a
  stock-take never disturbs the average; **zero variance → no row**.

All post-path reads pass `$lock = true` (the locking/current read that bypasses the
REPEATABLE-READ snapshot — the exact concurrency fix from the 6B review); the report
path (`closingBalance`) stays lock-free.

---

## Physical Stock value-adjustment — design choice (honest flag)

Book quantity is **godown-level**; the adjustment is valued at the **item-level
weighted-average rate**, so a correction reconciles the counted quantity without
disturbing the average (an excess IN of 3 @ 40 onto 20 @ 40 leaves 23 @ 40, not a new
blend). This is an internally consistent, defensible choice. **Tally's own Physical
Stock value-adjustment mechanics are worth confirming against the actual product if
exact parity matters** — the structure here (variance row at the going average) is easy
to revisit if the real behaviour differs. A zero-variance stock-take records the voucher
header (audit trail) but no `stock_entries` row, and so is not itself reconstructable on
re-open (documented, degenerate case).

---

## Client — Stock Journal & Physical Stock screens (extend `voucherScreen`)

Reached via **Go To** and the Gateway's **Inventory Vouchers** section (letters **J** /
**K**); Tally gives these no function key, so menu/Go-To access is the faithful default —
no key-binding invented. They are new modes of the existing keyboard `voucherScreen`
(reusing its masters cache, item/godown pickers, **Alt+C** stock-item create, and the one
post path), not a parallel screen. `isStockVoucher` forces `single`/`showDouble`/`showInvoice`
false so only the movement layout renders.

- **Stock Journal:** a `Type of movement` select (Transfer / Consumption) → Stock Item →
  godown(s) → Quantity. Transfer shows source + destination (validated distinct); Consumption
  shows one godown. `Alt+C` creates a stock item inline. **0 network** until accept.
- **Physical Stock:** Stock Item → Godown → **Book Quantity** (the one permitted server touch
  — `$wire.bookQty()` on item/godown/date change) → Counted Quantity, with the **live variance**
  (excess/shortage) computed client-side as you type. Accept posts the movement.
- **Day Book:** both types appear with a movement summary (e.g. `Widget · 20 Nos · Main
  Location → Warehouse B`, `Widget · −5 Nos (stock-take) · Warehouse B`) and a `stock` marker
  instead of a rupee amount; **drilling in** re-opens the movement pre-filled (transfer =
  OUT/IN rows, physical = book + variance).
- **Print (deliverable F):** a **dedicated light stock slip** (`vouchers/print-stock.blade.php`)
  — item · godown · In/Out · qty · rate · value, with a note that it moves stock only and
  touches no ledger. Cost **is** shown here because a stock voucher is internal (no customer to
  shield it from, unlike a sales invoice). Kept out of the invoice print view (cleaner than
  branching it).

---

## The numeric proof — `php artisan zerobook:prove-stock-journal`

Posts through `VoucherScreen::post()` and asserts (28 checks, all pass):

- Buy 50 @ 40 into A ⇒ item 50 @ avg 40 = 2,000.
- **Transfer** 20 A→B ⇒ 2 rows both @ **40**; **A = 30, B = 20** (godown-level); item TOTAL
  qty **50** and value **2,000** and average **40** all **unchanged**; **zero voucher_entries**.
- **Consumption** 10 ⇒ OUT 10 @ 40 = **400**; item value → **1,600**; **zero voucher_entries**.
- **Physical shortage** (B book 20, count 15) ⇒ OUT **5 @ 40**; B → **15**.
- **Physical excess** (A book 20, count 23) ⇒ IN **3 @ 40**; A → **23**; average **unchanged (40)**.
- **Physical no-variance** ⇒ **no stock row**, no ledger row.
- All 5 stock vouchers **posted** (the balance gate accepts 0 = 0).
- **Trial Balance unaffected** (Dr = Cr, driven only by the seed purchase).

---

## Acceptance — self-verified

- ✅ `zerobook:prove-stock-journal` — **28/28**.
- ✅ **Browser-driven** (127.0.0.1:8777): Stock Journal defaults to Transfer, posts STKJ-1
  (OUT Main / IN Warehouse B @ 40, zero ledger rows); Physical Stock loads the **book qty
  (20)** for Warehouse B via the one server touch, shows live **shortage −5**, posts PHYS-1
  (OUT 5 @ 40); godown balances Main 30 / B 15; item total 45 @ 1,800; **Trial Balance
  Dr=Cr=2,000 unaffected**; the **Day Book** shows both with movement summaries; **drill →
  alter** reconstructs the transfer (Widget, 20, Main → Warehouse B); **no console errors**.
- ✅ `npm run build` clean; **all seven prior proofs still pass** (`prove-balance`, `-sales-purchase`,
  `-gst`, `-vat`, `-billwise`, `-costcentre`, `-item-invoice`), regime unchanged, DB restored.

---

## Post-build adversarial review (money-critical hardening)

The finished 6C diff went through the same multi-agent adversarial review as 6B
(6 dimensions × two independent skeptics per finding × a high-effort synthesis).
It found **two HIGH real defects** plus two rigor gaps — all fixed and re-proven.

1. **HIGH — a crafted stock payload could smuggle ledger lines into `voucher_entries`.**
   The balance gate is skipped for stock types, but the `$data['lines']` normalisation
   loop had **no `$isStock` guard** — so `post({type:'stock_journal', movement:{…},
   lines:[{ledger_id, dr_cr:'Dr', amount:1000}]})` wrote an unbalanced `VoucherEntry`
   onto a pure stock voucher and **permanently unbalanced the Trial Balance**. **Fix:**
   the ledger side is forced empty for stock types (mirroring the `items` gate). The
   proof now asserts an injected line is **dropped** and that **no stock voucher writes
   any `voucher_entries`** (including the two variance physicals).
2. **HIGH — a godown transfer was not value-neutral after a later rate change.**
   `fold()` values an IN from its stored (frozen) value but re-derives an OUT at the
   *live* average — correct for a sale/purchase, but for a transfer's OUT+IN pair the
   two legs only cancel while the average is unchanged. Altering (or back-dating) the
   source purchase's rate made the transfer **silently destroy value** (buy 50@40,
   transfer 20, alter to 50@44 ⇒ item showed 2,120 / avg 42.4 instead of 2,200 / 44),
   corrupting closing stock and the average going forward. **Fix:** stock rows now
   carry a `movement_type`; `fold()` **skips `transfer` rows entirely** — a transfer is
   value-neutral *by construction* (it moves only godown-level quantity, which
   `godownQuantity` still counts), immune to any later rate change and free of the
   sub-paise rounding residue. Reproduced before the fix, verified 2,200 / 44 after;
   the proof now includes this alter-then-check regression.
3. **MEDIUM (proof) — the TB check only asserted `balanced` (Dr==Cr), not the total**,
   and zero-ledger was never asserted on the two *variance* physicals — the exact blind
   spot that hid #1 for the physical path. **Fix:** the proof now asserts the TB total
   equals the two real purchases (2,200), exactly **4** `voucher_entries` exist, and
   every stock voucher wrote **zero** ledger rows.
4. **LOW — the live Book Quantity double-counted the voucher's own variance while
   altering** (display-only; the posted result was always correct). **Fix:** `bookQty()`
   takes an `excludeVoucherId`, threaded from the client on alter.

Nothing else survived: consumption at the locked average, the `$isStock`-scoped
balance-gate skip (accounting vouchers still fully gated), the single locking costing
path, the divide-by-zero fallback, and the average-undisturbed physical adjustment were
all verified correct.

---

## Scope — not in 6C (later)

- Multi-item Stock Journal, manufacturing / BOM — single item per voucher this phase.
- Multi-item Physical Stock (a full stock-take grid) — single item per voucher.
- **Stock Summary report + Opening/Closing Stock & COGS into the P&L / Balance Sheet — Phase 6D**
  (reads the `stock_entries` this phase and 6B write).
- Any ledger/money posting for these voucher types — by design, there is none.

---

## Files

**Schema**
- `database/migrations/2026_07_10_000001_add_stock_voucher_types.php` (enum widen).
- `database/migrations/2026_07_10_000002_add_movement_type_to_stock_entries.php` (transfer tag).
- `_docs/phase6c_schema.sql` (both).

**Server**
- `app/Services/StockService.php` — `lockItems`, `godownQuantity`, `persistTransfer`,
  `persistConsumption`, `persistPhysicalStock` (all reuse `fold`/`weightedAverageRate`/lock).
- `app/Livewire/VoucherScreen.php` — `$isStock` validation branch + `validateStockMovement`;
  movement normalise; `persistStockMovement`; `bookQty`; `rebuildEditMovement`; zero-line gate skip.
- `app/Models/Voucher.php` — `TYPES` + `STOCK_TYPES`; `toRow` `is_stock`/`stock_summary`; `stockSummary()`.
- `app/Http/Controllers/VouchersController.php` — stock-print early return.
- `app/Console/Commands/ProveStockJournalCommand.php`.

**Client**
- `resources/js/vouchers/screen.js` — stock modes (state `mv`; getters; `stockBalanced`;
  `buildMovementPayload`; `refreshBookQty`; `resetMovement`; edit reconstruction; accept branch;
  `single`/`showDouble`/`balanced` routing; `onComboPick` mv pickers).
- `resources/views/livewire/voucher-screen.blade.php` — Stock Journal + Physical Stock layouts.
- `resources/views/partials/{voucher-item-combo,voucher-godown-combo}.blade.php` — `$col` param.
- `resources/views/vouchers/print-stock.blade.php` — light stock slip.
- `app/Livewire/DayBook.php` + `resources/views/livewire/day-book.blade.php` — stock rows.
- `app/Support/Shell.php` — Inventory-Vouchers nav + Gateway letters J/K.
