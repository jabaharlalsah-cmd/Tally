# ZeroBook — Phase 5A: Sales (F8) / Purchase (F9) + "as Invoice" mode + printable invoice

Adds Tally's **Sales** and **Purchase** vouchers, the party-centric **"as Invoice"**
entry mode (alongside the existing "as Voucher" double-entry), and a **printable
invoice** with amount-in-words in the Indian lakh/crore system. Inventory/stock
items are Phase 6, GST is Phase 5B — so invoice mode here is the **accounting
(ledger) invoice** only.

Every sale and purchase posts through the **same balanced double-entry path**
built in Phases 3–4 — `VoucherScreen::post()` — with the same server integer-paise
balance gate. There is **no second posting route.**

---

## Step 0 — audit, perf fix, schema decision

### Phase 4 audit → PASS
`php artisan zerobook:prove-balance` passes all 7 assertions unchanged:

```
Total Dr = 120,000.00   Total Cr = 120,000.00   balanced=YES
P&L: Income 20,000 − Expenses 5,000 = Nett Profit 15,000
BS:  Assets 115,000 = Liabilities 100,000 + Nett Profit 15,000  balanced=YES
[PASS] ×7 — ALL ASSERTIONS PASSED
```
No Phase 4 fixes were required.

### Applied performance fix — index-friendly date filters
`app/Services/BalanceService.php` used `whereDate('vouchers.date', …)` in
`netByLedger()` (period + prior-movement queries) and `ledgerVouchers()`.
`whereDate()` wraps the column in SQL `DATE()`, which prevents the optimiser from
using the index on `vouchers.date`. Since the column is a **DATE**, the bounds
were replaced with plain range comparisons on the raw column:

```php
$q->where('vouchers.date', '>=', $from->toDateString());
$q->where('vouchers.date', '<=', $to->toDateString());
```

These are exact, sargable bounds (no `DATE()` wrapper), so the `vouchers.date`
(and `[type,date]`) indexes are usable on large books. **Re-ran the numeric proof
after the change — identical results** (TB 120,000 = 120,000, all 7 assertions
pass). Correctness was already verified in Phase 4; this is purely a speed change.

### `vouchers.type` column decision — it is an ENUM → migrated
The Phase 3 migration defines `type` as `enum('contra','payment','receipt','journal')`.
An enum cannot store new values without an `ALTER`, so Phase 5A widens it to
include `sales` and `purchase`. (Had it been a string column, no change would have
been needed.) `Voucher::TYPES` is extended with `sales` (F8) and `purchase` (F9).

---

## Integration plan (reuse, not rebuild)

| Concern | Reused / extended |
|---|---|
| Posting + balance gate | **`VoucherScreen::post()`** → `validatePayload()` (integer-paise Dr==Cr) → `persistNew()/persistAlter()`. Invoice metadata rides as **optional nullable header fields** on the *same* method. |
| Entry loop | The `voucherScreen` Alpine controller (`resources/js/vouchers/screen.js`) — type switching, continuous entry, Alt+C, picker event-sink, Enter chaining, Ctrl+A. |
| Party / ledger pick | Phase 2 `zbSelect` combobox + masters cache. Party uses combo id `vparty`; allocation lines reuse `vline-<uid>`. |
| Inline create | Alt+C → existing quick-ledger sub-screen, now **pre-filling the natural group** (Sundry Debtors/Creditors for the party, Sales/Purchase Accounts for the allocation). |
| Reports | Sales→Income (Sales Accounts), Purchase→Expense (Purchase Accounts), party→Debtor/Creditor flow into Day Book, Trial Balance, P&L and Balance Sheet automatically — they are ordinary balanced vouchers. |
| Current balance | `BalanceService` closings for the line/party display. |

---

## Schema additions

Migration `database/migrations/2026_07_07_000004_add_sales_purchase_and_invoice_meta_to_vouchers.php`:

1. `ALTER TABLE vouchers MODIFY type ENUM('contra','payment','receipt','journal','sales','purchase')`.
2. Nullable header columns (existing F4–F7 vouchers unaffected):
   - `party_ledger_id` — nullable FK → `ledgers` (`ON DELETE SET NULL`)
   - `reference_no` — nullable string (supplier/reference invoice number)
   - `reference_date` — nullable date

A ready-to-run phpMyAdmin script mirroring the migration is in
**`_docs/phase5a_schema.sql`**.

`Voucher` model: `TYPES` + `INVOICE_TYPES` constants, `party_ledger_id/reference_no/
reference_date` fillable+cast, `partyLedger()` relation, `isInvoice()` helper, and
the invoice fields added to `toRow()`.

---

## Sales / Purchase field map

**As-Invoice (default for Sales/Purchase):**

| Field | col | Notes |
|---|---|---|
| Party A/c name | `party` | `zbSelect` (`vparty`); customer (Sales) / supplier (Purchase). Alt+C creates under Sundry Debtors/Creditors. |
| Ref / Supplier Inv. No. | `refno` | text; supplier's invoice number (Purchase especially) |
| Ref Date | `refdate` | date |
| Ledger allocation lines | `ledger` + `amount` | one or more Sales/Purchase ledger lines; Alt+C creates under Sales/Purchase Accounts |
| Narration | `narration` | shared |

**As-Voucher:** the existing double-entry table (Dr/Cr, particulars, debit, credit).

### How invoice mode derives the balanced entries
Client-side in `buildPayloadLines()`, each allocation amount is rounded to paise,
and the **party leg is made the exact sum** of those rounded lines so the two
sides balance to the paise (the server re-checks in integer paise):

- **Sales invoice:** `Dr Party (total)` · `Cr` each Sales ledger line
- **Purchase invoice:** `Dr` each Purchase ledger line · `Cr Party (total)`

The payload (with `party_ledger_id`, `reference_no`, `reference_date`) is posted
through **`VoucherScreen::post()`** — the one shared path. `validatePayload()`
only keeps the invoice metadata for `sales`/`purchase` types; all other types
pass `null`, so the single path stays clean.

---

## Key bindings (registered on the Phase 1 engine)

| Key | Action | Where | Browser-safety |
|---|---|---|---|
| **F8** | Sales (open / switch type) | global | Not reserved in a normal tab. Alt+F8 alternate + Go To + Gateway "Sales (F8)" hub item. Added to `HARD_BLOCK` so any default is neutralised. |
| **F9** | Purchase (open / switch type) | global | As above (Alt+F9 alternate, Go To, hub item "9"). |
| **Ctrl+V** | As Invoice / As Voucher toggle | voucher context | Ctrl+V is the browser **paste** key. It is bound **only in the voucher context** (paste is unaffected on every other screen) and carries a **`yieldInTextarea`** flag: while a narration textarea has focus the key is handed back to the browser so a paste is never eaten. Elsewhere on the screen it toggles the layout. The **F12 option** and the on-screen **"As Invoice/As Voucher" button** are the always-works alternates. |
| **Alt+P** | Print current invoice / voucher | voucher context + Day Book | Alt+P is not browser-reserved; also surfaced as a **Print button** in the right button bar (menu/alternate per Phase 1 policy) so it never dead-ends. |
| Enter / Ctrl+A / Alt+C / F2 / Alt+I / Alt+R | (reused) chaining / accept / inline create / date / add-remove line | voucher context | unchanged |

`Ctrl+V` toggling **preserves the entry**: Invoice→Voucher expands the derived
party + allocation legs into explicit Dr/Cr lines; Voucher→Invoice lifts a matching
party-side line back into the header. It is fixed while altering a saved voucher.

Engine change: `_prep()`/dispatcher gained a `yieldInTextarea` capability (a
repurposed clipboard combo yields to a focused textarea). This is the only
behavioural change to the Phase 1 dispatcher and is inert for every existing key.

---

## Printable invoice

- Route **`GET /vouchers/{voucher}/print`** → `VouchersController@print` →
  standalone Blade `resources/views/vouchers/print.blade.php` (its own inlined
  print CSS, not the app shell).
- **Layout:** ZeroBook brand header + company identity; document title
  ("Sales Invoice" / "Purchase Bill" / "… Voucher"); party block (mailing name,
  address, state, GSTIN, PAN); voucher no + date + reference no/date; the ledger
  allocation lines with a Total; **amount in words**; narration; signatory block.
  A non-invoice voucher prints the full Dr/Cr line table instead.
- **Amount in words** — `App\Support\Money::inWordsIndian()` (Indian lakh/crore),
  e.g. `1,23,456.75 → "Indian Rupees One Lakh Twenty Three Thousand Four Hundred
  Fifty Six and Seventy Five Paise Only"`. `Money::indianFormat()` does the
  `12,34,567.50` grouping.
- **Print CSS:** `@media print` hides the on-screen toolbar, removes the sheet
  chrome, and forces brand colours (`print-color-adjust: exact`); `@page` sets
  margins. Reachable via **Alt+P** (voucher screen prints the just-accepted /
  altered voucher; Day Book prints the highlighted row) and the on-page
  **Print (Ctrl+P)** button.

---

## Gateway integration

- **Gateway hub:** "Sales (F8)" and "Purchase (F9)" rows with hot-letters `8`/`9`.
- **Go To (Alt+G):** "Sales Voucher" and "Purchase Voucher" under the Vouchers
  section (keyword-searchable: invoice, bill, customer/supplier, debtor/creditor).
- **Right button bar:** F8 Sales / F9 Purchase appear in the Vouchers group; the
  voucher screen bar reflects the active mode ("As Invoice/As Voucher" + Print).

---

## Numeric proof — `php artisan zerobook:prove-sales-purchase`

Posts a Sales invoice and a Purchase invoice **through `VoucherScreen::post()`**
(the exact UI endpoint) and asserts the reports. Rolls back unless `--keep`.

```
Sales    SALE-1  Dr Acme Retail / Cr Product Sales      10,000  ref=INV-2045
Purchase PURC-1  Dr Raw Material Purchase / Cr Metro Supplies 6,000  ref=BILL-9981
Trial Balance:  Total Dr 16,000.00 = Total Cr 16,000.00   balanced=YES
Profit & Loss:  Income 10,000 − Expenses 6,000 = Nett Profit 4,000
Balance Sheet:  Assets 10,000 (debtor) = Liabilities 6,000 (creditor) + Profit 4,000  balanced=YES
```

All 19 assertions PASS, including: invoice metadata persisted on the shared path
(`party_ledger_id`, `reference_no`, `isInvoice`); the correct legs (customer Dr
10,000; supplier Cr 6,000; sales ledger Cr 10,000; purchase ledger Dr 6,000);
income/expense/profit; debtor→Assets, creditor→Liabilities; TB & BS balanced; and
the **shared balance gate rejecting an out-of-balance invoice**.

### Verified live in the browser
- Gateway shows Sales (F8) / Purchase (F9) in the hub and Vouchers bar — no console errors.
- Sales screen opens **as Invoice** (party field, double-entry table hidden, mode chip "as Invoice").
- Keyboard invoice entry posted **Dr Acme Retail 7,500 / Cr Product Sales 7,500** as `SALE-2`, persisted with `ref=INV-TEST-1`, then **continuous entry** (number 2→3, fields cleared).
- **Print** `/vouchers/29/print`: "SALES INVOICE", Bill-To Acme Retail + GSTIN, ref no/date, line 7,500.00, Total ₹ 7,500.00, **"Indian Rupees Seven Thousand Five Hundred Only"**.
- **Ctrl+V** Invoice→Voucher showed `Dr Acme Retail 3,000 / Cr Product Sales 3,000` (balanced); Voucher→Invoice lifted the party back — **entry preserved both ways**.
- Purchase invoice posted **Dr Raw Material Purchase 4,200 / Cr Metro Supplies 4,200** (`PURC-2`).
- Day Book listed all four (SALE-1, PURC-1, SALE-2, PURC-2); **Trial Balance 27,700.00 = 27,700.00, "✓ balanced"** live.
- **Regression:** Payment still uses single-entry (F12) and is excluded from invoice; Sales excluded from single-entry; Ctrl+V is a no-op with a hint on non-Sales/Purchase types.
- **F12** voucher config lists both "single-entry" and "Enter Sales/Purchase as Invoice (Ctrl+V)", the latter reflecting the live mode.

---

## Files

**New**
- `database/migrations/2026_07_07_000004_add_sales_purchase_and_invoice_meta_to_vouchers.php`
- `_docs/phase5a_schema.sql`
- `app/Support/Money.php` — Indian amount-in-words + grouping
- `app/Console/Commands/ProveSalesPurchaseCommand.php` — 5A numeric proof
- `resources/views/vouchers/print.blade.php` — standalone printable invoice
- `.claude/launch.json` — local preview server config (dev tooling)

**Extended**
- `app/Services/BalanceService.php` — index-friendly date range filters
- `app/Models/Voucher.php` — sales/purchase types, invoice metadata, `partyLedger()`, `isInvoice()`
- `app/Livewire/VoucherScreen.php` — invoice metadata through the shared post path; invoice-group + edit boot data
- `app/Http/Controllers/VouchersController.php` — `print()` action
- `app/Support/Shell.php` — Gateway hub + Go To Sales/Purchase entries
- `routes/web.php` — `vouchers.print` route
- `resources/js/vouchers/screen.js` — invoice mode, Ctrl+V toggle (+ preservation), Alt+P, party/allocation derivation; Day Book Alt+P print
- `resources/js/engine/engine.js` — F8/F9 (+Alt+F8/F9) globals; `yieldInTextarea` dispatcher capability
- `resources/js/engine/keys.js` — F8/F9 in `HARD_BLOCK`
- `resources/js/config/store.js` — F12 exposes the As-Invoice toggle
- `resources/views/livewire/voucher-screen.blade.php` — invoice layout + mode toggle
- `resources/views/livewire/day-book.blade.php` — Alt+P print
- `resources/css/vouchers.css` — invoice-mode styling

---

## Not in 5A (later phases)
GST / tax computation (5B); bill-wise details, Outstandings, cost centres (5C);
inventory / stock items / item-invoice mode (6). Invoice mode here is accounting
(ledger) only — noted on the entry screen.
