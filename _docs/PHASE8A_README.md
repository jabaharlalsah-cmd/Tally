# ZeroBook — Phase 8A: Debit Notes & Credit Notes

**Goal:** the return / post-invoice-adjustment vouchers Tally users reach for
constantly — sales returns, purchase returns, rate revisions, discounts — posting
through the **one shared, balance-gated path** (`VoucherScreen::post()`). Every prior
invariant still holds: GST/VAT server authority, bill-wise reconciliation, cost-centre
gate, weighted-average COGS locking, Trial-Balance identity.

Debit & Credit Notes are the **mirror-opposites** of Purchase & Sales. Getting the
direction wrong silently misstates revenue or expense, so the direction is nailed
below and asserted to the paise by `zerobook:prove-notes`.

```bash
php artisan zerobook:prove-notes    # the worked numeric proof (45/45)
```

---

## Step 0 — audit + integration plan

**Audit — all twelve prior proves pass.** `prove-multi-tenant` = 47/0 (the ten
accounting proves inside two tenants + isolation), `prove-sync` ✓. After 8A they are
**still 47/0 + ✓** — no regression from the shared-path changes.

**Built against the real, current shapes:**
- `vouchers.type` is a **DB ENUM** → widened (raw `MODIFY`) to add `credit_note`,
  `debit_note`. `Voucher::TYPES` + `INVOICE_TYPES` (notes are party-centric invoices)
  extended; new `NOTE_TYPES`, `stockDirection()`, `movementTypeFor()` helpers.
- `VoucherScreen::post()` / `validatePayload()` — **unchanged path**; Notes ride it.
  Only additions: `reference_voucher_id` plumbing + the item direction now derives
  from `Voucher::stockDirection()` (4 types, not a sales/else binary).
- `GstService`/`VatService::computeInvoiceTax()` — extended role/side for 4 types
  (the only change; `verifyInvoicePayload()` recognises Notes automatically via
  `INVOICE_TYPES` and re-verifies exactly as for invoices).
- `StockService::persistItems()` — the credit-note IN cost now comes from the
  referenced sale's original OUT cost (never the client rate).
- `resources/js/vouchers/screen.js` — **extended, not forked** (party side, tax role,
  labels, reference picker).
- `vouchers.reference_voucher_id` added (nullable self-FK, set-null on delete).

---

## The direction — the Dr/Cr mapping (get this exactly right)

| | Party | Revenue ledger | Tax (GST) | Tax (VAT) | Stock |
|---|---|---|---|---|---|
| **Sales** | **Dr** party | Cr Sales | Cr **Output** CGST/SGST/IGST | Cr Output VAT | OUT @ w-avg |
| **Credit Note** (customer / sales return) | **Cr** party | Dr **Sales Return** | Dr **Output** (reverse) | Dr Output VAT | **IN** @ original sale cost |
| **Purchase** | **Cr** party | Dr Purchase | Dr **Input** CGST/SGST/IGST | Dr Input VAT | IN @ entered rate |
| **Debit Note** (supplier / purchase return) | **Dr** party | Cr **Purchase Return** | Cr **Input** (reverse) | Cr Input VAT | **OUT** @ current w-avg |

A Note **reverses** its invoice: same duty ledgers (Output for customer-side, Input
for supplier-side) but on the **opposite side**, so the party's dues drop/rise by the
full **tax-inclusive** amount. Implemented once, in `computeInvoiceTax()`:
`role = in ['sales','credit_note'] ? 'output' : 'input'`; `side = match(sales:Cr,
credit_note:Dr, purchase:Dr, debit_note:Cr)`. The client derives the same side from
`invoicePartySide()` (the opposite), so client and server never diverge — and the
server re-verifies the tax to the paise and rejects a tampered Note.

---

## Cost preservation — the subtlest point

A **sales return must not distort the running weighted average.** So a Credit Note's
IN row is costed at the **original sale's locked cost**, looked up server-side from
the referenced sale's `stock_entries` OUT row — never the client's rate and never a
made-up figure (`StockService::salesReturnCost()`):

* **With a reference** → the original OUT `rate` (what the stock left at).
* **Without a reference** (a free-standing credit) → the current weighted average
  (the documented fallback).

The client's `sale_rate`/`sale_value` on the IN row carry the **credited-back (refund)
amount**, kept separate from cost — the same cost≠selling discipline as a sale.

A **Debit Note** (purchase return) sends stock OUT at the **current** weighted average
— the honest cost of what's leaving, exactly like a sale.

---

## The worked numeric proof — `zerobook:prove-notes` (45/45)

Widget averaging **60** (buy 100@50, buy 100@70); GST intra-state @ 18%.

```
Sales invoice — sell 20 @ 100
  Dr Cust 2,360 / Cr Sales 2,000 / Cr Output CGST 180 / Cr Output SGST 180  (balanced)
  stock OUT: qty 20, cost 60, value 1,200, sale_rate 100 · running 180 @ 10,800 (avg 60)

Credit Note (Sales Return) — ref the sale, 5 units back
  Dr Sales Return 500 / Dr Output CGST 45 / Dr Output SGST 45 / Cr Cust 590  (balanced)
  stock IN: qty 5, cost 60 (FROM THE ORIGINAL SALE), value 300, sale_rate 100
  running 185 @ 11,100 → AVERAGE STILL 60.00 (the return did NOT distort it)  ← the proof
  party balance dropped by exactly 590 · Trial Balance still balances

Purchase 10 @ 55, then Debit Note ref it, 3 units back
  purchase → running 195 @ 11,650 (avg ≈ 59.74)
  Dr Supp 194.70 / Cr Purchase Return 165 / Cr Input CGST 14.85 / Cr Input SGST 14.85
  stock OUT: qty 3 @ the CURRENT running average 59.74

GST summary   Output tax 270 (360 sale − 90 credit-note reversal) · Input dropped 29.70
Free-standing Credit Note (no ref) → IN costed at the current average (documented fallback)
Bill-wise      Credit Note "Against Ref INV-2" reduces the sale bill's pending 1,180 → 708
Cost-centre    a cost-applicable Note line REQUIRES its allocation, and posts once given
VAT (13% flat) Sale Cr Output VAT 52 → Credit Note Dr Output VAT 13 (reversal), party Cr 113
Tamper         a Credit Note with a doctored tax amount is REJECTED by the GST authority
```

`prove-notes` provisions a throwaway tenant, runs entirely through
`VoucherScreen::post()`, and tears down.

---

## Key bindings

**Ctrl+F8 → Credit Note**, **Ctrl+F9 → Debit Note** (Tally's stable bindings). Both are
`Ctrl+F*` combos the browser does not reserve, and neither collided with an existing
engine binding — a grep confirmed only bare `f8`/`f9` (Sales/Purchase) were bound.
Reachable from the Gateway's Vouchers section and Go To (search *notes*, *returns*,
*credit note*, *debit note*).

---

## UI + reports

* The voucher screen is the **same invoice screen** with two visible differences: a
  **prominent "Credit Note" / "Debit Note" heading** and an optional **"Against
  invoice" picker** at the top. Picking the original does **one** server lookup
  (`referenceInvoice()`) that pre-fills the party + item lines (the user then adjusts
  quantities). Party scope is Debtors for a Credit Note, Creditors for a Debit Note.
  Item-invoice and accounting modes both work (Ctrl+I / Ctrl+V), continuous entry on
  Ctrl+A. **Entry is 0-network** — only accept, drill, and the reference pre-fill (a
  single lookup) touch the server.
* **Notes register** (`/reports/notes-register`, Go To) — a chronological list of just
  Debit & Credit Notes, filterable by type / party / period, drillable to the voucher.
  Every other report (Day Book, Trial Balance, P&L, GST/VAT Summary, Outstandings)
  picks Notes up automatically — they are ordinary vouchers on the shared path.
* **Print** reuses the invoice print with the heading swapped to "Credit Note" /
  "Debit Note" and the "Against Invoice" reference shown; tax breakup + amount-in-words
  unchanged.

---

## Schema

Tenant migration `database/migrations/tenant/2026_07_12_000001_add_debit_credit_notes.php`:
enum widen + `reference_voucher_id` + (guarded) return-ledger seed. Fresh tenants also
get the return ledgers via `DatabaseSeeder → ReturnLedgerSeeder`. Existing tenants:
`php artisan tenants:migrate`. phpMyAdmin equivalent: `_docs/phase8a_notes_schema.sql`.

---

## Files delivered / modified

```
database/migrations/tenant/2026_07_12_000001_add_debit_credit_notes.php
database/seeders/ReturnLedgerSeeder.php   (+ registered in DatabaseSeeder)
app/Console/Commands/ProveNotesCommand.php
app/Livewire/NotesRegister.php
resources/views/livewire/notes-register.blade.php
resources/views/reports/notes-register.blade.php
_docs/phase8a_notes_schema.sql · _docs/PHASE8A_README.md

modified (surgical, no parallel path):
app/Models/Voucher.php                 TYPES/INVOICE_TYPES/NOTE_TYPES/stockDirection/movementTypeFor/referenceVoucher
app/Services/GstService.php            computeInvoiceTax role/side for 4 types
app/Services/VatService.php            computeInvoiceTax role/side for 4 types
app/Services/StockService.php          persistItems credit-note IN cost + salesReturnCost() + movementTypeFor
app/Livewire/VoucherScreen.php         reference_voucher_id, stockDirection dir, note defaults/groups, referenceInvoice() lookup
app/Http/Controllers/ReportsController.php + routes/tenant.php    /reports/notes-register
app/Http/Controllers/VouchersController.php + print.blade.php     Note print title + Against-Invoice line
app/Support/Shell.php                  Go To / Gateway entries (Credit Note, Debit Note, Notes Register)
resources/js/engine/engine.js          Ctrl+F8 / Ctrl+F9 bindings
resources/js/vouchers/screen.js        party side, tax role, note labels/heading, reference picker
resources/views/livewire/voucher-screen.blade.php   note banner + reference picker
```

No schema beyond the one migration. No stubs, no TODOs. All prior proves green.
