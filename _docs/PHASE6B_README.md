# ZeroBook — Phase 6B: Item-Invoice Mode + Stock Posting + Weighted-Average COGS

The money-critical inventory phase. Sales/Purchase invoices can now be entered as
**item invoices** (Stock Item · Qty · Rate lines); posting writes the **stock
ledger** in the *same transaction* as the money entries, and a **weighted-average
cost engine** locks the correct cost onto every OUT (sale) row at post time —
server-authoritatively, never from the client.

**One rule above all:** two numbers are never confused, anywhere.

| | Selling (revenue) | Cost (COGS) |
|---|---|---|
| Source | user-entered rate on the item line | weighted-average, computed by `StockService` |
| Stored in | `stock_entries.sale_rate` / `sale_value` (OUT rows) | `stock_entries.rate` / `value` |
| Flows to | the Sales ledger (revenue) + the invoice/print | the stock ledger only — *not* a P&L leg |
| Ever on the client? | yes (entered) | **never** |

There is **no Dr-COGS / Cr-Inventory ledger entry per sale**. Cost of Goods Sold
and Closing Stock are computed *report-level* figures, read from `stock_entries`
in Phase 6C. The `voucher_entries` Dr/Cr balance gate is **completely unchanged** —
items are not voucher entries and never touch it.

---

## Step 0 — audit & integration plan (done before building)

All six prior proofs passed and the regime was unchanged. Confirmed the real shape
of the code to **extend, not fork**: the single posting path
`VoucherScreen::post() → validatePayload() → persistNew/persistAlter`; the two tax
engines' `computeInvoiceTax()/verifyInvoicePayload()`; the 6A `StockService`
opening-only stub; and the 5A/5B/5E invoice client in `screen.js`.

---

## The one posting path, extended

`VoucherScreen::post()` now carries an **optional `items[]`** array. Inside the
*same* `DB::transaction` that writes the ledger entries + bill + cost allocations,
it calls `StockService::persistItems($voucher, $items)`. On alter, the voucher's
old `stock_entries` are deleted first, then re-posted (the OUT cost is
**re-locked** from the current ledger, excluding the voucher's own rows).

Server authority, all in the `validatePayload()` after-hook:
- **Direction** is derived from the voucher type (Sales = OUT, Purchase = IN) —
  never trusted from the client.
- **Amount** is recomputed `qty × rate` — a tampered amount is ignored.
- **Items ↔ ledger reconciliation:** `Σ (qty × rate)` must equal the taxable
  (non-party, non-tax) ledger revenue to the paise, so the stock revenue and the
  ledger revenue can never diverge.
- **Cost** is *never* in the payload.

### `StockService` — the weighted-average engine

- `fold($itemId, $asOf, $excludeVoucherId)` — the single kernel: opening +
  chronological movements `(date, id)` into a running `(qty, value, last_avg)`.
  An OUT draws at the average in force at that moment, so a sale leaves the average
  unchanged and only a purchase shifts it.
- `weightedAverageRate($itemId, $asOf, $excludeVoucherId)` = `value / qty` (falls
  back to the last average, then the opening rate — never divides by zero).
- `closingBalance($itemId, $asOf)` = `fold(...)` → `{qty, value}`. For an item with
  **no movement it returns the opening balance unchanged** (the 6A contract holds).
- `persistItems()` — IN: entered rate → `rate`/`value` (the cost), selling columns
  null. OUT: `rate`/`value` = the weighted-average cost, `sale_rate`/`sale_value` =
  the user's selling figures.
- **Concurrency (two-part, both required):** every distinct item's master row is
  `lockForUpdate()`-locked up front (in id order, deadlock-safe) to *serialise*
  concurrent writers — mirrors the Phase 5C bill lock. **And** the cost read of
  `stock_entries` is itself a `lockForUpdate()` (a *current* read) on the post path,
  because under InnoDB's default **REPEATABLE READ** the transaction's snapshot is
  pinned at its first read (the voucher-number `SELECT MAX`, before the item lock);
  a plain read would miss a purchase that committed while we waited for the lock and
  cost the sale at a stale average. The report path (`closingBalance`) reads the
  snapshot and takes **no** locks. *(This second half was added after the adversarial
  review — see below.)*

### Item-sourced tax (GST *and* VAT)

`computeInvoiceTax()` in **both** `GstService` and `VatService` now honours a
per-line `rate` override; `verifyInvoicePayload()` builds the taxable base **from
the Stock Items** (rate looked up server-side) whenever the payload carries
`items`, so a per-item rate beats the ledger rate and a tampered client tax is
rejected. Verified: an item at 18% on a 5% Sales ledger is taxed at **18%**.

---

## The client (`screen.js` + blade) — Item ⇄ Accounting invoice

Item invoice is an **extension** of the existing invoice-mode client, not a
parallel one. `showInvoice` still means "invoice layout"; it now partitions into
`showItemInvoice` / `showAcctInvoice`.

- **Default:** a fresh Sales/Purchase invoice opens as an **Item Invoice** when the
  company keeps any stock (Tally's default), else Accounting. An altered voucher
  re-opens in the mode it was captured in.
- **Toggle:** **`Ctrl+I`** (Tally's "Change Mode"), with a header button. Chosen
  because `Ctrl+V` already toggles As-Invoice/As-Voucher and `Alt+I` adds a line;
  `Ctrl+I` is free, mnemonic (*Item*), and not a common browser shortcut. It yields
  in a textarea so a narration is never eaten.
- **Item line:** Stock Item picker (**Alt+C** creates a stock item inline) · Godown
  (defaults to **Main Location**) · Qty · Rate (the **selling** rate) · Amount
  (auto). A single revenue-ledger row (defaults to the first Sales/Purchase Accounts
  ledger) receives `Σ` item amounts. Live totals + item-sourced tax, **0 network**.
- The tax base seam `taxBaseBySlab()` reads item rates in item mode, ledger rates in
  accounting mode — the one place both `_gstComputation`/`_vatComputation` group by.
- `buildItemsPayload()` sends only `{stock_item_id, godown_id, qty, rate}` — qty +
  **selling** rate. The cost is computed server-side.

Inline **Alt+C** stock-item create is the `CreatesStockItems` concern + a
`quick-stock-item` sub-screen (name + optional Stock Group / Unit + regime-relabelled
tax rate/HSN). A new item has **no opening stock** — its cost is built purely from
movement. (Creating a *new group/unit* is left to the full Stock Item master —
bounded inline scope.)

**Print:** an item invoice prints the **stock lines** (Item · HSN · Qty · Rate ·
Amount) with the **selling** rate only — the weighted-average cost never appears on
the document. Subtitle reads "Item Invoice". Accounting invoices are unchanged.

---

## The numeric proof — `php artisan zerobook:prove-item-invoice`

Posts through `VoucherScreen::post()` and asserts, to the paise/unit:

- Buy **100 @ 50** then **100 @ 70** ⇒ running **200 @ avg 60 = 12,000**.
- Sell **120 @ selling 90**: the OUT row locks **cost rate 60 / value 7,200** while
  the selling side records **sale_rate 90 / sale_value 10,800** — never confused;
  closing **80 @ 4,800**.
- Item-sourced tax **18%** overrides the **5%** Sales ledger (CGST 972 + SGST 972);
  a **balance-preserving** tamper is rejected **specifically by the GST authority**
  (the error bag carries `gst`, not `balance`) — proving item-tax authority in
  isolation, not by accident of the balance gate.
- **Negative stock is allowed**: selling 200 with 80 on hand posts, costing at the
  last average 60; closing qty **−120**. A **zero-opening item** then genuinely
  exercises the divide-by-zero guard: the first sale falls back to `opening_rate`
  (outer guard), a second sale folds a prior OUT that already drove qty ≤ 0 (inner
  guard) — cost **0**, no crash.
- **Altering** the sale re-locks the cost from current state. An intervening
  back-dated purchase makes the correct re-locked average a **discriminating 70**
  (P1+P2+P3 = 300 @ 21,000), so the assertion would fail on any stale/non-recomputed
  cost — not the coincidental 60 the original proof couldn't distinguish.
- A plain **accounting invoice writes zero stock rows**.
- **Trial Balance still balances** — the money side is untouched by stock.

---

## Acceptance — self-verified

- ✅ `zerobook:prove-item-invoice` — **every assertion passes** (numbers above).
- ✅ **Browser-driven** (127.0.0.1:8777): a Sales item invoice defaults to item mode,
  posts Dr Party 12,744 / Cr Sales 10,800 / CGST 972 / SGST 972, with an OUT stock
  row keeping **selling 90/10,800 separate from cost**; a Purchase item invoice
  writes an IN row at **cost 60 with no selling side**; **Alt+C** creates a stock
  item inline and selects it back; **Ctrl+I** toggles Item ⇄ Accounting; **no
  console errors**; the print shows item lines with the selling rate only.
- ✅ `npm run build` clean; **all six prior proofs still pass**, regime unchanged
  (gst=OFF, vat=OFF), DB restored to its pre-test state.

---

## Post-build adversarial review (money-critical hardening)

Because a wrong COGS silently corrupts Gross Profit later, the finished 6B diff was
put through a multi-agent adversarial review: 6 review dimensions (weighted-average
math, atomicity/concurrency, server authority/tax, client parity, regression,
proof-rigor), each finding then cross-examined by **two independent skeptics**
(refute-by-code + reproduce-the-number), then a synthesis pass that re-read the code.
It surfaced **two real defects** and **three proof-rigor gaps**; all are fixed.

1. **HIGH — stale-snapshot COGS (concurrency).** The item-master `lockForUpdate`
   serialised writers, but `fold()` read `stock_entries` **non-locking**. Under
   InnoDB REPEATABLE READ the snapshot is pinned before the lock, so a purchase that
   commits while a sale waits for the lock was invisible → the sale recorded a stale
   average (e.g. cost 50 instead of 60). **Fix:** the post-path cost read is now a
   `lockForUpdate()` *current* read that bypasses the snapshot (`StockService::fold`
   `$lock` param; report path unchanged, still lock-free).
2. **MEDIUM — cost-applicable revenue ledger made item invoices un-postable.** In
   item mode the single revenue ledger lives in `itemLedgerId` (outside `this.lines`),
   so `costTargets()` never offered it for cost-centre allocation; the server then
   rejected the post demanding an allocation the client never collected. **Fix:**
   `costTargets()` now includes the item revenue ledger; edit reconstruction re-homes
   its saved allocations to the `itemledger` key. **Verified in-browser:** a
   cost-applicable Sales ledger item invoice now opens the allocation sub-screen and
   posts, with North 3,000 + South 2,000 = 5,000 persisted against the revenue leg.
   *(This supersedes the earlier "not wired" limitation — it now works.)*
3. **MEDIUM (proof) — tamper test was confounded by the balance gate.** The old
   tamper was also out of balance, so it could pass even if the GST verifier were
   broken. **Fix:** the tamper is now balance-preserving and the proof asserts the
   rejection carries the `gst` error key and **not** `balance`.
4. **MEDIUM (proof) — divide-by-zero guard never exercised.** The negative test still
   had positive on-hand qty at cost time. **Fix:** a zero-opening item drives both
   fallback branches (cost 0, no crash).
5. **LOW (proof) — alter re-lock was non-discriminating** (expected 60 was
   unavoidable). **Fix:** an intervening back-dated purchase makes the correct value a
   distinct 70.

One finding (a claimed VAT `stripTaxLines()` gap) was **verified as a false positive
and dropped**: `taxLedgerMap()` has no GST-only filter, so `this.gst.tax_ledgers`
already contains the VAT duty-ledger ids and they *are* stripped. Independently
re-confirmed against the source.

---

## Known, documented limitations (by design — 6C or later)

- A **back-dated purchase** entered *after* a later sale does **not** retroactively
  recompute that sale's already-locked cost (cost is locked at post time). Altering
  the sale itself *does* re-lock it.
- **Negative stock** is permitted and costed at the last known average — not
  hard-blocked.
- No Stock Journal / Physical Stock / Stock Summary, and no P&L/Balance-Sheet
  wiring of Opening/Closing Stock + COGS — that is **Phase 6C**, which reads the
  `stock_entries` this phase writes.

---

## Files

**Schema**
- `database/migrations/2026_07_09_000001_add_sale_columns_to_stock_entries.php`
  (`sale_rate`, `sale_value`) + `_docs/phase6b_schema.sql`.

**Server**
- `app/Services/StockService.php` — weighted-average `fold`/`weightedAverageRate`,
  real `closingBalance`, `persistItems` (locking).
- `app/Livewire/VoucherScreen.php` — `items` validation + reconciliation + normalise
  + persist in-txn; `bootData` ships inventory caches + item-invoice defaults;
  edit rebuilds item rows.
- `app/Livewire/Concerns/CreatesStockItems.php` — inline Alt+C create.
- `app/Services/GstService.php` / `app/Services/VatService.php` — per-line rate
  override + item-sourced `taxableFromItems`.
- `app/Models/{StockEntry,Voucher}.php` — sale columns; `Voucher::stockEntries()`.
- `app/Http/Controllers/VouchersController.php` — item lines for the print (selling
  side only).
- `app/Console/Commands/ProveItemInvoiceCommand.php`.

**Client**
- `resources/js/vouchers/screen.js` — item mode, item rows, item-sourced tax base,
  `buildItemsPayload`, `Ctrl+I` toggle, item/godown/item-ledger pickers, Alt+C.
- `resources/views/livewire/voucher-screen.blade.php` — item table + toggle button.
- `resources/views/partials/{voucher-item-combo,voucher-godown-combo,quick-stock-item}.blade.php`.
- `resources/views/vouchers/print.blade.php` — item-line print branch.
