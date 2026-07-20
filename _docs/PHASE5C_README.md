# ZeroBook — Phase 5C: Bill-wise Details + Outstandings (Receivables / Payables)

Adds Tally's bill-by-bill tracking on party ledgers — the **bill-allocation
sub-screen** (New Ref / Against Ref / Advance / On Account) — and the
**Receivables / Payables (Outstandings)** reports with aging and a
**reconciliation with the ledger balances**.

Gated by the F11 **"Maintain bill-by-bill"** switch (Phase 4) and the per-ledger
**maintain_bill_by_bill** flag (Phase 2). Allocations ride the **one shared,
balance-gated path** (`VoucherScreen::post()`); the server re-validates that each
bill-wise line's allocations sum to the line amount to the paise.

---

## Step 0 — audit & flag

**5B audit → PASS.** `zerobook:prove-gst`, `zerobook:prove-sales-purchase` and
`zerobook:prove-balance` all pass; invoices post through `VoucherScreen::post()`,
the Trial Balance balances, and the server GST authority rejects tampered tax.

**Ledger flag confirmed:** `ledgers.maintain_bill_by_bill` (boolean) already
exists from Phase 2 and is editable (F11-gated) on the ledger form — no change
needed. Added it to `Ledger::toCache()` so the client knows which ledgers open
the allocation sub-screen.

---

## Bill-wise rules as implemented

- **Applies to** any ledger with *maintain_bill_by_bill* on (typically Sundry
  Debtors / Creditors). When such a ledger is used on a voucher line — the party
  leg of a Sales/Purchase **invoice**, or any party line on a Payment/Receipt —
  the **allocation sub-screen** opens at accept and the amount **must be fully
  allocated** (0 remaining) before the voucher posts.
- **Allocation types** (`bill_allocations.ref_type`): **New Ref** (opens a new
  bill = the invoice no, with an optional due date), **Against Ref** (settles an
  existing open bill picked from the party's open bills), **Advance**, **On
  Account**.
- **Bill = (ledger_id, ref_name)**; its **pending** is the SIGNED sum of its
  allocations in **Dr-terms paise** (Dr = +, Cr = −), the sign taken from the
  voucher entry each allocation belongs to. Pending 0 ⇒ **closed** (drops off
  Outstandings). New Ref adds on the party's natural side; Against Ref on the
  opposite side reduces it.
- **Reconciliation invariant (enforced by construction):** because every
  bill-wise line is fully split across allocations,
  `Σ(bill pending) = Σ(entries)`, so
  **`ledger closing (Dr-terms) = Σ bill pending + opening balance`**. The opening
  is the only unbilled part, surfaced as **"on account / opening (unbilled)"** —
  the outstanding can never silently diverge from the balance.

---

## Posting on the shared path (server authority)

`app/Services/BillService.php` is the single bill engine. Allocations are attached
to each payload line as `allocations: [{ref_type, ref_name, amount, due_date}]`
and flow through the **same** `VoucherScreen::post()`:

- **`validatePayload()`** (in the existing after-hook) calls
  `BillService::validatePayload()`: for every bill-wise line (feature on), the
  allocations must be present and **sum to the line amount to the paise**, with
  valid ref types/names — else the voucher is rejected. Any allocations supplied
  are validated regardless of the flag. The paise balance gate and GST authority
  still apply on top.
- **`persist()`** writes `bill_allocations` in the **same transaction** as the
  entries, each linked to its `voucher_entry_id`. On **alter** the allocations are
  deleted and rewritten with the entries; on **cancel** they **cascade** away with
  the voucher. One posting path, no parallel save.

The client (`resources/js/vouchers/screen.js`) keeps the loop **0-network**: it
computes the bill-wise targets, opens the sub-screen for each unallocated one at
accept (chaining to the next, then posting), and the **Against-Ref picker** reads
the party's open bills from a `openBills` cache seeded in `bootData` (and updated
locally after each post for continuous entry).

---

## Schema

`bill_allocations` (migration `2026_07_07_000006_create_bill_allocations_table.php`
+ `_docs/phase5c_schema.sql`): `voucher_id` (cascade), `ledger_id` (cascade),
`voucher_entry_id` (nullable, set-null), `ref_type` enum(new/against/advance/
onaccount), `ref_name`, `amount` decimal, `due_date` nullable; indexed on
`(ledger_id, ref_name)` and `voucher_id`.

---

## Outstandings reports

`Reports\Outstandings` (`mode` receivable → Sundry Debtors / payable → Sundry
Creditors) at **`/reports/receivables`** and **`/reports/payables`**. Per party,
each open bill's **ref, date, due date, original, pending and overdue days**;
party subtotals and a grand total. `Enter` drills to **the vouchers behind that
bill** (`/reports/bill/{ledger}?ref=…`, reusing the Ledger-Vouchers list). `F2`
sets the as-on date. The status line proves the reconciliation
(*bills pending + on-account = closing*). Reachable from Go To (keywords:
outstanding, receivable, payable, bills, due, overdue) and the Gateway hub
(letters **R** / **Y**).

Basic **aging**: overdue days = today − due date for an open bill (an "overdue"
tag flags it). Interest and aging buckets are out of scope (later).

---

## Worked numeric proof — `php artisan zerobook:prove-billwise`

Drives the whole lifecycle **through `VoucherScreen::post()`** and asserts the
pending math, the allocation authority, the reconciliation and the Trial Balance.
Rolls back unless `--keep`.

```
(1) Sales   INV-100  Dr Nimbus 10,000  → New Ref INV-100 (due 25-Jul)   pending Dr 10,000
(2) Receipt          Cr Nimbus  6,000  → Against INV-100                 pending Dr  4,000
(3) Receipt          Cr Nimbus  2,000  → Advance ADV-1                   pending Cr  2,000
(4) Purchase BILL-9  Cr Orbit   5,000  → New Ref BILL-9 4,000 + On Account 1,000

Customer (Nimbus) closing Dr 2,000  ==  Σ bill pending (4,000 − 2,000)
Supplier (Orbit)  closing Cr 5,000  ==  Σ bill pending (4,000 + 1,000)
Receivables: pending + on-account = closing  → reconciles
Payables:    pending + on-account = closing  → reconciles
Trial Balance: Dr 15,000 = Cr 15,000  balanced
```

**All 13 assertions PASS**, including: INV-100 pending Dr 4,000 (New Ref reduced by
Against); ADV-1 advance Cr 2,000; BILL-9 Cr 4,000 + On Account Cr 1,000; a
**mismatched allocation (500 ≠ 1,000 line) is rejected**; both parties'
**closing == Σ pending** (reconciliation); Receivables & Payables reconcile; TB
balanced.

### Verified live in the browser
- A Sales invoice for a bill-wise customer opens the **allocation sub-screen** at
  accept (default New Ref = invoice no, full amount, remaining 0); accepting the
  allocation **chains to the post** and persists the `bill_allocation` linked to
  the **party-leg** entry (ref INV-777, due 01-Aug).
- **Under-allocation is blocked**: 3,000 of 5,000 → sub-screen stays open, flash
  "Allocate the full amount — 2,000.00 remaining".
- The **Against-Ref picker** for the Dr party lists the opposite-side open bill
  (advance ADV-1 Cr 2,000) and auto-fills 2,000 — **0 network**.
- **Receivables**: Nimbus INV-100 (original 10,000, pending 4,000, due 25-Jul) +
  ADV-1 2,000, subtotal 2,000, **"✓ Reconciles with ledger balances · pending
  2,000 + on-account 0 = closing 2,000"**. **Payables**: Orbit BILL-9 4,000 + On
  Account 1,000 = 5,000, reconciles.
- **Drill** INV-100 → the two vouchers behind it (Sales SALE-1 New Ref Dr 10,000;
  Receipt RCPT-1 Against Ref Cr 6,000).
- **Regression:** with bill-wise off, `prove-gst` / `prove-sales-purchase` /
  `prove-balance` all pass; no console errors.

---

## Files

**New**
- `database/migrations/2026_07_07_000006_create_bill_allocations_table.php`
- `_docs/phase5c_schema.sql`
- `app/Models/BillAllocation.php`
- `app/Services/BillService.php`
- `app/Livewire/Reports/Outstandings.php` + `resources/views/{reports,livewire/reports}/outstandings.blade.php` + `resources/views/reports/{receivables,payables}.blade.php`
- `resources/views/reports/bill-vouchers.blade.php`
- `resources/views/partials/bill-alloc.blade.php`
- `app/Console/Commands/ProveBillwiseCommand.php`

**Extended**
- `app/Models/Voucher.php` — `billAllocations()` relation
- `app/Models/Ledger.php` — `maintain_bill_by_bill` in `toCache()`
- `app/Livewire/VoucherScreen.php` — allocations in payload validation + persist (writeEntries returns entry ids), `openBills`/edit-allocations boot data
- `app/Http/Controllers/ReportsController.php` — receivables / payables / bill drill
- `app/Support/Shell.php` — Receivables/Payables in Go To + hub
- `resources/js/vouchers/screen.js` — bill targets, allocation sub-screen, Against picker, cache update
- `resources/js/reports/screen.js` — `outstandings` controller
- `routes/web.php` — receivables / payables / bill routes
- `resources/css/{vouchers,reports}.css` — sub-screen + Outstandings styling

---

## Not in 5C (later phases)
Cost centres (5D); Nepal VAT (5D or its own); inventory (Phase 6); interest on
overdue bills, aging buckets beyond overdue-days, multi-currency bill tracking.
