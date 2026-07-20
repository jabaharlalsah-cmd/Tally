# ZeroBook — Phase 12C-1: FIFO lots for inter-company stock

**Status: done and proven.** `php artisan zerobook:prove-inter-company-lots` → **38 assertions, 0 failures.**
Full cold regression: **23/23** prove-commands green (22 prior + the new one; `prove-balance` and
`prove-inventory-integration` additionally re-run *inside* the lot tenant, proving every valuation
number byte-identical with the whole lot lifecycle behind it). The phpMyAdmin script
`_docs/phase12c1_schema.sql` was verified **byte-identical** to the migration (provision → revert via
`down()` → apply script → `SHOW CREATE TABLE` diff = 0). An adversarial multi-agent review (4 lenses ×
2-refuter verification) ran over the finished build — see "Adversarial review" below.

12C-1 answers the question weighted-average pooling erases: *of the units on company B's shelf, how
many came from group-internal purchases, from whom, and at what cost to the group?* That trace is the
prerequisite for 12C-2's audit-grade unrealised-profit elimination. **Provenance, never valuation** —
the weighted-average engine is untouched, the Trial Balance does not move a paisa, and ungrouped
tenants write no rows.

---

## Step 0 — audit result

All 22 prior prove-commands were re-run at the close of 12B (the true-final post-fix regression):
**22/22 green**, zero changes since. The five `StockEntry::create` sites in `StockService`, the 12B tag
lifecycle, and the `withoutGlobalScope + explicit company_id` cross-company read pattern (12B's
`reciprocalLedgerId`) were read before building and are reused, not reinvented. `BelongsToCompany` /
`ActiveCompany`: untouched.

---

## The lot data model

One new table, nothing else changed (**no existing table gained a column**):

`inter_company_stock_lots` — one row per inter-company IN `stock_entries` row, plus child rows from
godown transfers. `company_id` (receiving side, `BelongsToCompany`), `stock_item_id`, `godown_id`,
`voucher_id` + **`stock_entry_id`** (FK **CASCADE** — the load-bearing choice: altering a voucher
deletes its stock rows, which auto-cleans its stale lots before the rewrite), `parent_lot_id`
(transfer genealogy), `source_company_id`, `source_voucher_id` (nullable, best-effort),
`original_qty` / `remaining_qty` (DECIMAL 15,4), `source_cost_paise` (nullable — the groupmate's
per-unit cost when identified), `received_rate_paise` (the transfer price), `received_date` (the FIFO
key). Indexes: `(stock_item_id, remaining_qty)` for consumption, `(company_id, stock_item_id,
received_date)` for the FIFO scan.

## Write & deplete rules

**Lots are written** when the active company is grouped AND the voucher is a stock-IN type —
*Purchase, Receipt Note, Credit Note, Rejections In* — whose **party ledger is linked to a groupmate**
(the same trigger as 12B's tag, evaluated per voucher; workflow vouchers carry no tag, so the trigger
deliberately reads the party link, not the tag). IN from an ungrouped supplier writes nothing.
`received_rate_paise` is the IN row's rate — for a Purchase the entered transfer price; for a return
IN, the original locked cost (the 6B contract, which *is* what that inventory re-entered the books at).

**Every OUT depletes FIFO** — Sales, Delivery Note, Rejections Out, Debit Note, Stock Journal
consumption, and a Physical Stock *shortage* (units left; an *excess* IN is of unknowable origin —
non-inter-company by definition, no lot). Order: `(received_date, id)` within the OUT row's godown; a
godown-less OUT depletes across godowns in the same order. **The OUT's cost is already final before
the hook runs — the running weighted average, exactly as 6B posted it** (asserted to the paise:
₹124.1667 pooled average while lots depleted 70→0 and 50→30). Excess beyond Σ remaining is simply
non-inter-company inventory leaving — a normal case, never an error (proven with mixed inventory).

## The correctness spine — replay

`remaining_qty` is a **pure function** of (root lots + the item's chronological OUT/transfer events).
So the service has two modes:

- **incremental** — `depleteOnOut()` / `transferLots()` at post time (cheap);
- **`refoldItem()`** — the universal repair: delete child lots, reset roots to `original_qty`, replay
  every OUT and transfer of the item in `(voucher.date, stock_entries.id)` order.

**Alter**: `persistAlter` captures the voucher's old item ids, the FK cascade removes stale lots with
the stock rows, the rewrite recreates them, and `refoldItems(old ∪ new)` replays — proven: a 100→80
alter after a 30-unit sale re-derives to exactly 80/50. **Cancel**: the `deleting` hook captures the
item ids while readable, the FK cascade removes the voucher's lots with its stock rows, and the
`deleted` hook refolds — dependent depletions re-apply to whatever remains or become non-inter-company.
This is the spec's "replay from the point of change" (replaying from the start is equivalent and
simpler). **Documented limitation** (the 6B-style honest boundary): a *back-dated OUT interleaved with
godown transfers* may allocate its depletion incrementally slightly differently than a strict
chronological replay would — per-item totals are always correct, and the next refold (any alter/cancel)
or any as-of read normalises it, because…

## …the as-of read replays too

`remainingLotsFor(item, godown, asOf)` — **the 12C-2 period-end query** — replays lots and events to
the date **in memory**, mutating nothing. It is therefore self-healing: even if live `remaining_qty`
were stale (e.g. a group was dissolved and later re-formed, freezing depletion in between), the
period-end answer is recomputed from `stock_entries` ground truth. Proven: as-of 23-Jun returns
[40, 60] before the two later sales.

## Cross-company source identification (best-effort, never guessed)

The one cross-company read — the established `withoutGlobalScope('company') + explicit
where('company_id', …)` pattern. Match the counterparty's OUT rows (`sale` / `purchase_return`
movements) where the voucher's party is **the reciprocal ledger** (12B's mirror trace), the item has
the **same name** (item ids are per-company; the name is the only cross-company identity — documented
interpretation of the spec's "same stock item"), the **same qty**, **dated on or before** the receipt.
Exactly one candidate → `source_voucher_id` + `source_cost_paise` (the OUT row's rate = the
counterparty's weighted-average cost — precisely the group's cost basis 12C-2 needs). Zero or several →
**null**, and the lot surfaces as **UNMATCHED** in the report and the 12C-2 query, rather than silently
misvaluing. Proven both ways: the unambiguous case records A's sale id and cost ₹100 against transfer
price ₹120; two identical candidate sales → null/null.

## Godown transfers — the split-row design

A Stock Journal transfer **moves lot allocation with the goods**: FIFO remaining qty at the source
godown splits into **child lots** at the destination — `parent_lot_id` genealogy, full provenance
copied, and crucially an **inherited `received_date`**, so the child keeps the parent's FIFO position
(a transfer never makes old inventory look new). Chosen over updating `godown_id` in place because a
*partial* transfer needs two rows anyway, and children keep the replay pure (refold deletes children
and re-derives them from the event stream). Proven: 100 into Main, transfer 40 → parent 60@Main +
child 40@WH-2 dated the original receipt; per-godown sales deplete each side independently (60→10,
40→20).

## The worked numeric scenario (`prove-inter-company-lots`, 38/0)

A (Apex Supply) buys Widget 100 @₹100 outside and sells 100 @₹120 to grouped B (Bravo Retail):

| Step | stock_entries (unchanged behaviour) | Lot trace (new) |
|---|---|---|
| B posts Purchase 100 @120 | IN qty 100 rate 120 | lot 100/100, source A, **source voucher = A's sale**, source cost **₹100**, price **₹120** |
| B sells 30 outside | OUT **@120 running average** | lot 100→70 |
| A sells 2×50 @130, B buys 50 | IN 50 @130 | lot 50/50, source **null** (2 candidates) → **unmatched** |
| B sells 90 | OUT **@124.1667 pooled average** (to the paise) | FIFO: 70→0, then 50→30 |
| B buys 20 outside, sells 40 | normal | 30 IC deplete + 10 non-IC — **no error** |
| Alter Purchase 100→80 (after a 30 sale) | 6B behaviour | replay → **80/50** |
| Cancel the Purchase | cascade | lots gone, trace refolded |
| Transfer 40 Main→WH-2, sell per godown | 6C behaviour | parent 60→10, child 40→20, FIFO date inherited |

Both companies' Trial Balances balance throughout; `prove-balance` + `prove-inventory-integration`
re-run **green inside the same tenant**; an ungrouped company's item purchase writes **zero** lot rows.

## The Lot Provenance report

`/reports/lot-provenance` (Gateway **6**, Go To: lot / provenance / inter-company inventory) — remaining
lots per item and godown as of a date (the same in-memory replay 12C-2 uses), source company, transfer
price, source cost, a per-unit unrealised hint where matched, an explicit **unmatched** count, drill to
the receiving voucher. The source voucher is shown as an identifier with a "switch company (F1) to
open" note — a cross-company drill link would correctly 404 under the 12A binding scope, by design.
Browser-verified live (group badge, lot row, drill, honest "source unmatched"), **0 console errors**.

## Adversarial review — found and FIXED before delivery

A 4-lens multi-agent hunt (lot lifecycle, valuation invariance + performance, isolation/scope, source
matching edges; 17 raw findings, two adversarial refuters each, several probe-confirmed on live
throwaway data) confirmed **five real defects — all fixed and locked into the proof as regression
assertions**:

1. **(HIGH) the replay depleted lots with OUTs dated before the lot's receipt** — a June sale ate a
   July lot on any refold (probe: a byte-identical re-save corrupted 50→20), and the as-of query had
   the same flaw. Fix: **the date gate** — depletion and transfers only touch lots with
   `received_date <=` the event's own date, in the incremental path, the refold replay, *and* the
   in-memory as-of replay (which also fixes back-dated OUTs incrementally, beyond the original spec).
   Proof now asserts the exact probe case: the lot **stays 50**.
2. **(HIGH) workflow-note alters skipped the refold** — `persistAlter`'s Delivery/Receipt-Note branch
   returned before the lot capture+repair: a no-op delivery-note alter double-depleted (70→40) and a
   no-op receipt-note alter resurrected the full lot (70→100) — the *mainline* inter-company receiving
   flow. Fix: the item-id capture moved to the head of `persistAlter` (covers every branch) and the
   workflow branch refolds before its return. Proof: both no-op alters now hold at 70; a real 30→20
   alter replays to 80.
3. **(MEDIUM) the as-of query lost the destination godown of transfer-split portions** — the report
   showed both splits at the source godown and counted the same unmatched lot once per split. Fix: the
   return shape carries each entry's **effective godown**; the report uses it and counts distinct
   unmatched roots. Proof asserts `[WH-2×40, Main×60]`.
4. **(LOW) cancel ran un-transactionally and refold took no item lock** — a failure between the FK
   cascade and the refold could strand a half-repaired trace; a concurrent sale could interleave with
   a replay. Fix: both cancel paths wrap `delete()` in a transaction; `refoldItem` takes the
   `StockItem` row lock (the 6B lock discipline).
5. **(PERF, contested-confirmed) the OUT-path group check ran per row** — `app()` returns a fresh
   `InterCompanyService` per call, so grouping was re-queried per OUT row, denting the per-voucher
   cost promise. Fix: `persistItems` resolves enablement once per voucher and pre-gates the hook.

One further finding was accepted as a **12C-2 design input rather than a defect**: return-leg lots
(Credit Note / Rejections In from a groupmate) carry *our original cost* as `received_rate_paise`
while their matched source cost is the counterparty's (marked-up) average — a naive
`received − source` markup on them is meaningless, so the report suppresses the hint for return-leg
lots and 12C-2 must treat them separately (they are un-sales, not purchases). Also documented: after a
group dissolves, live `remaining_qty` freezes (hot-path gating) — harmless because every consolidation
read uses the as-of replay, which recomputes from `stock_entries` ground truth.

---

## Files

**Schema** — `database/migrations/tenant/2026_07_22_000001_add_inter_company_stock_lots.php` ·
`_docs/phase12c1_schema.sql` (byte-diff verified).

**Domain** — `app/Services/InterCompanyLotService.php` (the authority) ·
`app/Models/InterCompanyStockLot.php` (scoped).

**Hooks** — `app/Services/StockService.php` (persistItems IN/OUT, persistTransfer, persistConsumption,
persistPhysicalStock shortage — all inside the existing shared transaction) ·
`app/Livewire/VoucherScreen.php` (persistAlter: capture + refold) · `app/Models/Voucher.php`
(deleting captures, deleted refolds — the cancel lifecycle).

**Report** — `app/Livewire/Reports/LotProvenance.php` + blades + route + Shell entries.

**Proof** — `app/Console/Commands/ProveInterCompanyLotsCommand.php` (31/0).

---

## Out of scope (named, not stubbed)

- **The consolidation reports** — 12C-2 (it consumes `remainingLotsFor()` and the unmatched list).
- **General FIFO costing** — `stock_items.costing_method` still means weighted-average for everything;
  per-item FIFO costing is a different feature with its own UX.
- **Full chronological re-fold on back-dated entry** — the back-dated-OUT-between-transfers allocation
  nuance above; totals always right, self-healing on any refold or as-of read (the 6B-style boundary).
- **Auto-mirror vouchers** — both sides are still posted manually; matching is best-effort over what
  exists.
- **Interactive lot selection** — FIFO order is server-derived; manual lot picking is deferred.
- **Import performance in grouped books** — the Tally importer writes lots too when importing into a
  grouped company (correctness first); its per-row source matching is a cross-company query per IN row,
  acceptable for typical imports, optimizable later.

Existing tenants: `php artisan tenants:migrate` (no central migration).
