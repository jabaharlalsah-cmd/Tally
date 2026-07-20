# ZeroBook — Phase 7A: The Tally-Data Migration Tool

**Goal:** let an existing Tally user switch to ZeroBook **without losing their
history** — read a Tally XML export (masters + vouchers) and import it into ZeroBook,
**verifiably correct** (a mis-imported opening balance or a lost voucher corrupts a
customer's books silently) and **fast enough** for a real multi-year book.

It is an **artisan command**, server-side only — the first ZeroBook subsystem that
isn't screen-driven:

```bash
php artisan zerobook:tally-import <file.xml> --dry-run   # preview, writes nothing
php artisan zerobook:tally-import <file.xml>             # commit (all-or-nothing)
php artisan zerobook:prove-tally-import                  # the numeric proof below
```

---

## Step 0 — audit result (before any 7A code)

**All nine prior `prove-*` commands were re-run and PASS** on PHP 8.3.30 / Laravel
13.18.1:

```
✅ prove-balance         ✅ prove-costcentre            ✅ prove-stock-journal
✅ prove-sales-purchase  ✅ prove-item-invoice          ✅ prove-inventory-integration
✅ prove-gst             ✅ prove-vat                   ✅ prove-billwise
```

They **still pass after 7A landed** (10/10 including `prove-tally-import`) — the
bulk-mode flag changes nothing on the interactive path.

**No real customer export was available.** Per the Step 0 gate this was surfaced as a
decision; the operator chose to proceed against a **research-grade synthetic sample**
that models the *documented* Tally XML, with a parser tolerant of both ERP 9 and
TallyPrime variants. ⚠️ **A real export must still confirm the parser before a
production migration** — see *Caveats* below.

---

## Architecture

Everything lives under `app/Services/TallyImport/` plus two commands. **No schema
changes** — the importer only writes into existing ZeroBook tables.

| File | Role |
|------|------|
| `TallyXmlParser.php` | Streams the XML → a compact in-memory model (masters + vouchers + "not imported" concepts). |
| `Resolver.php` | Maps every Tally master to a ZeroBook id; reuses seeded masters, creates the rest in dependency order. |
| `VoucherImporter.php` | Sorts vouchers chronologically, maps types, builds payloads, posts through the shared gate, collects rejections. |
| `TallyImporter.php` | Orchestrates parse → resolve → import → report inside **one transaction**; dry-run / commit / rollback. |
| `ImportReport.php` | The customer-facing summary (identical shape for dry-run and real). |
| `BulkMode.php` | The import-only performance flag (below). |
| `ResolveException.php` | A missing-reference error, turned into a named rejection. |
| `Console/Commands/TallyImportCommand.php` | `zerobook:tally-import {file} {--dry-run}` (+ global `-v`). |
| `Console/Commands/ProveTallyImportCommand.php` | `zerobook:prove-tally-import {--keep}`. |

### Four non-negotiable rules (all honoured)

1. **One transaction, rolled back on any error.** A complete verified book, or the DB
   you started with — never anything between.
2. **Dry-run is required.** `--dry-run` parses, resolves, validates and reports
   **without writing anything** — the review a CA does before committing.
3. **Every voucher posts through `VoucherScreen::post()`** — the same balance gate,
   GST/VAT authority, bill-wise sum check and cost-centre check the UI uses. **No
   bypass.** Data that violates an invariant is *reported and rolled back*, never
   massaged.
4. **Import in strict chronological order** — weighted-average COGS depends on it.

---

## The parser — streaming, and tolerant

**Streaming, not DOM.** A real multi-year export is hundreds of MB; a full
SimpleXML/DOM tree would be multiple GB and exhaust PHP's memory. The parser drives an
**`XMLReader`** node-by-node and, when it lands on a master or a `<VOUCHER>`, expands
**only that one element** into a throwaway DOMDocument, reads its fields into a compact
array, and drops it. Peak memory is one element's subtree plus the accumulated compact
model — **never the raw XML**.

> The compact voucher records are retained (not re-streamed) because the importer must
> sort them into strict chronological order. For the SME books this targets (tens of
> thousands of vouchers) the compact model is small; an external merge-sort for
> pathological books is a documented future optimisation.

**Tolerant of Tally version drift.** The same datum is accepted in its ERP 9 and
TallyPrime shapes (verified against both):

| Datum | Variants handled |
|-------|------------------|
| Ledger legs | `<ALLLEDGERENTRIES.LIST>` **and** `<LEDGERENTRIES.LIST>` |
| Inventory | `<ALLINVENTORYENTRIES.LIST>` **and** `<INVENTORYENTRIES.LIST>` |
| Dr/Cr | `<ISDEEMEDPOSITIVE>` (Yes = Dr) **cross-checked with** the sign of `<AMOUNT>` (negative = Dr); falls back to the sign alone when the flag is absent |
| Names | a `NAME="…"` attribute **or** a `<NAME.LIST><NAME>` child |
| Invoice revenue leg | a top-level ledger entry (accounting view) **or** nested in `<ACCOUNTINGALLOCATIONS.LIST>` (invoice view) — de-duplicated so it is counted once |
| Qty / rate | `"60 Nos"`, `"50.00/Nos"`, `"1,000.00"` all parse to their number |

> **The single most version-sensitive assumption is the sign convention: negative
> `<AMOUNT>` / `<OPENINGBALANCE>` = Debit.** It is applied consistently and is the
> first thing to confirm against a real export.

---

## The resolver — reuse, never duplicate

Builds a `name → id` map for every entity type **once**, before voucher parsing, so
per-line lookups in the streaming loop are O(1) and never touch the DB.

* **Account groups** — resolved by name honouring Tally's parent chain (a child may be
  exported before its parent, so parents are created recursively). Where a name matches
  one of ZeroBook's **seeded 28** (Sundry Debtors, Sales Accounts, …) the existing id
  is **reused**; only genuinely custom groups are created, inheriting nature from their
  parent.
* **Ledgers** — by name; carry the **opening balance with correct Dr/Cr direction**,
  GSTIN / state / registration type / PAN, GST rate & HSN, and the F11 flags
  (bill-by-bill, cost-centres-applicable). The reserved **Cash / Profit & Loss / 8
  GST-VAT duty ledgers** are reused by name — a reused ledger still picks up its Tally
  opening and party details, but its group, reserved status and tax role are left
  untouched.
* **Stock items / groups / units / godowns** — by name; carry opening qty / rate /
  value / godown. The reserved **`Main Location`** godown is reused, not duplicated.
* **Cost centres** — by name (imported flat; Tally's cost *category* layer is reported
  as not-imported).
* The company's **bill-by-bill** and **cost-centre** F11 features are switched on when
  the imported data uses them (never off; GST/VAT are left to the operator so an import
  never silently changes a tax regime).

---

## Chronological order — the subtlest correctness point

Each sale's cost is locked at the running **weighted average at the moment it is
posted**, from the movements already in the table. So vouchers **must** be posted in
`(voucher_date, then Tally's within-day file sequence)` order. The importer sorts
explicitly (never trusting the file's order) and asserts the posted sequence is
non-decreasing in date.

**The proof is built to fail if this breaks.** `prove.xml` emits the Sale (05 Apr)
*before* the Purchase (03 Apr) in the file:

```
Widget opening:            40 @ 25  = 1,000
Purchase 03 Apr (60 @ 50):+60       = 3,000   → running 100 @ 40
Sale     05 Apr (30 out):           cost locked at the running average = 40
```

A naive importer that posted in file order would post the Sale first and lock its cost
at the **opening-only 25** (value 750). The proof asserts the OUT cost is **40**
(value **1,200**) — only true if the Purchase was posted first. ✔

---

## Bulk-mode — a performance flag that cannot leak

A one-time migration is a **single process inside a single transaction**: no concurrent
posters to race. `App\Services\TallyImport\BulkMode` lets the shared posting path skip
**only** the concurrency guards:

* `StockService` skips the per-item `lockForUpdate()` and the `FOR UPDATE` "current
  read" in the weighted-average fold. Inside one transaction a plain read already sees
  every row this transaction inserted, so the average is computed correctly; the lock
  only ever protected against *other* committers, of which there are none.
* `VoucherScreen::post()` skips the `Voucher::nextNumber()` SELECT-MAX + duplicate-key
  retry loop, and the nested per-voucher transaction. The importer assigns numbers from
  an in-memory per-`(type, financial-year)` cursor (preferring Tally's own number when
  it is a clean, free integer), so there is nothing to race and nothing to retry.

It **never** touches correctness — the balance gate, GST/VAT authority, bill-wise and
cost-centre checks all run exactly as on the interactive path.

**Lifecycle — it cannot persist.** There is deliberately **no public setter**. The only
way to turn it on is `BulkMode::run($closure)`, which restores the previous state (`false`
at top level) in a `finally` **even if the import throws**. A future maintainer cannot
accidentally leave it enabled. The proof asserts `BulkMode::isActive() === false` after
every import, and the nine prior `prove-*` commands (which run on the interactive path)
still pass — the flag genuinely does not survive the import transaction.

---

## The worked import proof

`zerobook:prove-tally-import` runs the **real importer** against the synthetic samples
and asserts every figure. **All assertions pass.** Highlights:

```
A. DRY-RUN of prove.xml — full preview, zero writes
   TB Dr 53,700 = Cr 53,700 [BALANCED] · Net 500 · Stock-in-Hand 2,800
   Balance Sheet balanced · opening-diff 1,000 (= opening stock)
   groups 3 created / 6 reused · ledgers 5 created / 1 reused (Cash) · godown 1 reused
   → DRY-RUN wrote NOTHING: every table's row count identical before and after ✔

B. BROKEN export (Dr ≠ Cr) — rejected by name ("Journal"), whole import rolled back ✔

C. LARGE export — 300 vouchers (reverse date order) imported in 3.1 s
   (10.4 ms/voucher, streaming + bulk-mode path) · flag does NOT persist after ✔

D. REAL import of prove.xml — committed, then inspected:
   • Account groups = 28 seeded + 3 custom (no duplicates); Main Location not duplicated
   • North Sales ▸ Sales Division ▸ Sales Accounts (custom two-level nest resolved)
   • Cash reused, carried opening Dr 31,000 · Rajan Capital Cr 51,000 · ABC Retail Dr 20,000
   • ABC Retail carried GSTIN + state; Modern Traders bill-by-bill; Trade Purchases cost-centres
   • Every voucher round-trips to the paise (Sales Dr/Cr 2,700; Purchase 3,000; Payment 5,000; Journal 1,000)
   • Sale OUT cost = 40 / value 1,200  ← weighted-avg AFTER the purchase = chronological order held
   • Widget closing 70 @ 2,800 · 3 bill refs + cost allocations carried
   • Committed TB balanced (Dr=Cr=53,700) · Net 500 · Stock-in-Hand 2,800 · BS balanced
```

### Why the Balance Sheet shows a "Difference in opening balances" of ₹1,000

This is **faithful Tally behaviour, not a bug**, and worth understanding:

* ZeroBook's Trial Balance (like Tally's default) is **ledger-only** — opening stock
  lives on the stock items, off-ledger. So the TB balances **iff the ledger openings
  net to zero**, which the sample's do (Dr 20,000 + Cash 31,000 = Cr 51,000). ✔
* The opening stock (Widget ₹1,000) is an asset with **no matching capital opening** in
  the source. Tally shows exactly this as a **"Difference in Opening Balances"** on the
  Balance Sheet, and ZeroBook reproduces it to the rupee (₹1,000, liability side). The
  Balance Sheet still balances, now with Stock-in-Hand ₹2,800 shown as an asset.

If a customer's Tally file financed its opening stock via a padded Capital opening (so
its ledger openings net to *minus* the stock value), ZeroBook's ledger-only TB will
surface that as an opening difference instead — **the importer reports it so the CA can
reconcile.** This is called out in *Caveats*.

---

## Tally concepts NOT imported (reported, never silently dropped)

The summary lists every concept encountered that ZeroBook has no home for, with a
count, so the customer knows what to re-key or ignore:

| Concept | Why |
|---------|-----|
| **Stock categories** | ZeroBook has no stock-category axis (items live under stock groups). |
| **Cost categories** | ZeroBook cost centres are flat; the category layer is dropped (centres still import). |
| **Payroll / Attendance** (pay heads, employees, attendance types) | A materially separate subsystem. |
| **Budgets, Scenarios / optional vouchers** | Not modelled. |
| **Price levels / price lists** | Not modelled. |
| **Foreign currencies (multi-currency)** | ZeroBook is single-currency. |
| **Voucher-type definitions** | Mapped by name — the vouchers themselves *are* imported. |
| **TDS/TCS nature-of-payment, GST classifications, tax units** | Out of 7A scope. |
| **Standalone Stock Journal / Physical Stock vouchers** | Reported per-voucher and skipped (re-key after migration); they carry no ledger side, so skipping cannot unbalance the TB. |
| **Interest parameters, voucher classes, bank-reconciliation dates, batches/expiry, BOM, compound/alternate units** | Out of 7A scope. |

---

## Caveats before a production migration

1. **Confirm the parser against a REAL export first.** The synthetic sample validates
   the *design*, not a specific customer's Tally version. Check the **sign convention**
   (negative amount = Debit) and the ledger/inventory tag names against the real file.
2. **GST/VAT re-verification.** If the source company used GST/VAT, enable the matching
   regime in ZeroBook before import: every invoice then re-runs ZeroBook's tax
   authority and **rejects** any tax that doesn't reconcile to the paise (the intended
   strict behaviour). Left off, tax lines import as plain balanced ledger postings.
   Tax-authority mismatches are the most common real-world rejection — fix in Tally and
   re-export.
3. **Opening-stock financing.** If the source financed opening stock via capital, the
   imported ledger-only Trial Balance will show that as an opening difference — expected
   (see above); reconcile with the CA.
4. **Standalone inventory vouchers** (Stock Journal, Physical Stock) are reported and
   skipped in 7A — re-key the handful that exist after migration.

---

## Customer quickstart

See **`_docs/tally-samples/README.md`** for step-by-step *"how to export from Tally
(ERP 9 & Prime) and import into ZeroBook."* In short: export **All Masters** + a
full-period **Day Book** as **XML**, then:

```bash
php artisan zerobook:tally-import company.xml --dry-run   # review the summary
php artisan zerobook:tally-import company.xml             # commit if clean
```

---

## Files delivered

```
app/Services/TallyImport/BulkMode.php
app/Services/TallyImport/TallyXmlParser.php
app/Services/TallyImport/Resolver.php
app/Services/TallyImport/VoucherImporter.php
app/Services/TallyImport/ResolveException.php
app/Services/TallyImport/ImportReport.php
app/Services/TallyImport/TallyImporter.php
app/Console/Commands/TallyImportCommand.php
app/Console/Commands/ProveTallyImportCommand.php
_docs/tally-samples/prove.xml
_docs/tally-samples/broken.xml
_docs/tally-samples/README.md
_docs/PHASE7A_README.md

modified (bulk-mode wiring only; interactive path unchanged):
app/Livewire/VoucherScreen.php      — bulk path in persistNew(), supplied number, no retry loop
app/Services/StockService.php       — skip lockForUpdate()/FOR-UPDATE reads under BulkMode
```

No migrations. No `TODO`s, stubs or placeholders.
