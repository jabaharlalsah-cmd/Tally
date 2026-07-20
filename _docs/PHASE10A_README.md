# ZeroBook — Phase 10A: the TDS deduction engine

**Status: done and proven.** `php artisan zerobook:prove-tds` → **151 assertions, 0 failures.**
Full regression: **18/18** prove-commands green (17 prior + the new one), plus `prove-multi-tenant`
(47/0) which re-runs the battery inside two isolated tenants.

Phase 10B — the **Form 26Q FVU return file** — is *not* in this phase. It is gated on the current
TDS Return Preparation Utility's spec, exactly as Phase 9A was gated on the real GSTN artifacts.
See [Deferred to 10B](#deferred-to-10b).

---

## ⚠ Have a chartered accountant verify the rates before you use this on a real book

The seeded rates and thresholds are **reasonable defaults, not legal advice**. They change with
every Finance Act. **Nothing in the engine hardcodes a rate or a threshold** — they live in the
`tds_sections` table and are edited from *Masters → TDS Sections*. Before a customer books a
single rupee of TDS through ZeroBook, a CA must reconcile that table against the Finance Act in
force. This warning is repeated on the F11 screen and on the TDS Sections master itself.

---

## Step 0 — audit result

All prior prove-commands were re-run before a line of 10A was written. **17/17 green, regime
unchanged, nothing needed fixing.**

The prompt refers to 14 prior proofs; there are in fact **17** — Phase 9A added `prove-gstr1` and
`prove-gstr3b`, and Phase 9B added `prove-nepal-vat-return`. The nine pre-tenancy proofs run against
a provisioned tenant database via a `DB_DATABASE=tenant<slug>` override (the Phase 8B convention);
`prove-tally-import` takes `--tenant`; the rest provision their own throwaway tenants.

---

## The one structural fact this phase is built around

On **1 April 2026** the Income Tax Act 2025 replaced the old 194-series with a consolidated
**Section 393** for non-salary payments (and Section 392 for salary, which ZeroBook does not touch).
The rates and thresholds largely carried over; the *section code printed on a return* did not.

A live book therefore contains **both**: vouchers dated in FY 2025-26 belong to `194J`, vouchers from
FY 2026-27 to `393-194J`. So sections are **effective-dated by fiscal year**, the catalog holds both
eras, and the **voucher's own fiscal year** decides which section is legal. A repealed section stays
in the table (its historical vouchers still need it) but never reaches a picker, and the server
**refuses** a current-year voucher that tries to deduct under it.

```
FY 2026-27 live sections:   393-194A  393-194C  393-194H  393-194I-A  393-194I-B  393-194J  393-194Q
Still in the catalog:       194A  194C  194H  194I-A  194I-B ×2  194J  194Q      (all effective_to ≤ 2025)
```

`194I-B` appears **twice** with different date ranges. The section did not change — only its threshold
did (an annual ₹2,40,000 became a per-month ₹50,000 from FY 2025-26). `UNIQUE(code, effective_from)`
exists for exactly that.

---

## The section catalog as seeded

| code | description | rate | company rate | no-PAN floor | single | aggregate | window | once crossed | in force |
|---|---|---|---|---|---|---|---|---|---|
| `194A` / `393-194A` | Interest other than on securities | 10% | — | 20% | — | 50,000 | year | whole aggregate | ≤2025-26 / 2026-27→ |
| `194C` / `393-194C` | Payments to contractors | 1% | 2% | 20% | 30,000 | 1,00,000 | year | whole aggregate | ≤2025-26 / 2026-27→ |
| `194H` / `393-194H` | Commission or brokerage | 2% | — | 20% | — | 20,000 | year | whole aggregate | ≤2025-26 / 2026-27→ |
| `194I-A` / `393-194I-A` | Rent — plant & machinery | 2% | — | 20% | — | 50,000 | **month** | whole aggregate | 2025-26 / 2026-27→ |
| `194I-B` / `393-194I-B` | Rent — land & building | 10% | — | 20% | — | 50,000 | **month** | whole aggregate | 2025-26 / 2026-27→ |
| `194I-B` (older) | Rent — land & building | 10% | — | 20% | — | 2,40,000 | year | whole aggregate | ≤2024-25 |
| `194J` / `393-194J` | Professional / technical fees | 10% | — | 20% | — | 50,000 | year | whole aggregate | ≤2025-26 / 2026-27→ |
| `194Q` / `393-194Q` | Purchase of goods | 0.1% | — | **5%** | — | 50,00,000 | year | **excess only** | 2021-22..2025-26 / 2026-27→ |

Two columns capture the structural quirks so they stay **data**:

* `threshold_period` — the window the aggregate threshold is measured over. 194I is **monthly**.
* `deduct_basis` — once crossed, tax the **whole aggregate**, or (194Q) only the **excess** above it.
* `no_pan_rate` — the Section 206AA floor. `NULL` means the statutory 20%; the proviso to 206AA(1)
  caps it at **5% for 194Q**, so that exception is a column value, not another branch in the engine.

Section 194J's 2% rate for technical services and call centres is *not* seeded — add it as its own
section if you need it. The seeded `notes` on each row say so.

---

## The threshold state model

```
tds_deductee_ytd  (deductee_ledger_id, tds_section_id, fy_start)  UNIQUE
    paid_amount       running Σ of taxable base amounts paid this fiscal year
    deducted_amount   running Σ of TDS actually withheld
```

Thresholds are **per (deductee, section)**. The same vendor may be paid professional fees under
`393-194J` (₹50,000 threshold) *and* separately under a contract on `393-194C` (₹30,000 single /
₹1,00,000 annual). Neither aggregate touches the other; a deductee simply has two rows.

The state is written **inside the voucher's own post transaction**, alongside the ledger entries, so
they commit or roll back together. A concurrent post can never observe an aggregate that includes a
voucher which subsequently failed.

### `tds_deductions` — one row per TDS-engaged Payment, *including* the zero ones

A row is written even when nothing was deducted. Those zero rows are not noise:

* they are the audit trail of a below-threshold payment;
* they are how a **monthly-threshold** section (194I) reconstructs its month window;
* they are how **194C** later distinguishes the bills that individually exceeded ₹30,000;
* Form 26Q reports *amount paid*, not only *tax deducted*;
* they make `reverseFor()` uniform — alter and cancel never special-case.

`payment_amount` and `base_amount` are a deliberate pair:

| | meaning |
|---|---|
| `payment_amount` | the taxable base of **this** voucher (pre-GST). What 26Q calls "amount paid". Accumulates into `paid_amount`. |
| `base_amount` | the **cumulative** base the liability was computed on. Equal to `payment_amount` in the steady state; equal to the whole year-to-date aggregate on the voucher that first crosses the threshold; equal to the *excess* for 194Q. |

It always holds that:

```
deducted = ROUND(base_amount × rate) − already_deducted_this_window
```

---

## The aggregate catch-up rule, with the worked example

Nothing is withheld while the window's aggregate stays inside the threshold. **The moment it crosses,
tax is due on the entire aggregate** — the earlier below-threshold payments included. One line
produces both the one-time catch-up and the ordinary steady state:

```
deducted_now = round(aggregate × rate) − already_deducted_this_window
```

M/s Legal Advisors · PAN on file · `393-194J` · 10% · annual threshold ₹50,000:

| # | paid | aggregate | liability so far | already withheld | **withheld now** | posting |
|---|---|---|---|---|---|---|
| 1 | 40,000 | 40,000 | — *(below 50,000)* | 0 | **0** | `Dr Legal Fees 40,000 / Cr Bank 40,000` |
| 2 | 15,000 | **55,000** | 55,000 × 10% = **5,500** | 0 | **5,500** | `Dr Legal Fees 15,000 / Cr TDS Payable 5,500 / Cr Bank 9,500` |
| 3 | 20,000 | 75,000 | 75,000 × 10% = 7,500 | 5,500 | **2,000** | `Dr Legal Fees 20,000 / Cr TDS Payable 2,000 / Cr Bank 18,000` |

**Voucher 2 is the subtle one.** ₹5,500 is withheld from a ₹15,000 bill — more than 10% of it. The
₹4,000 that was never withheld on voucher 1 is *caught up here*, and the vendor receives ₹9,500. The
screen says so in plain words before you post:

> *Aggregate 55,000.00 this year crosses the 50,000.00 threshold — deducted on the full aggregate,
> catching up on 40,000.00 paid earlier with no deduction.*

`tds_deductee_ytd` after each: `40,000/0` → `55,000/5,500` → `75,000/7,500`. And
`7,500 == 75,000 × 10%` — the cumulative identity holds at every step.

### Section-specific quirks live in named methods

`computeDeduction()` dispatches on the **normalised** section code (`393-194J` → `194J`), so an era
change never touches the engine:

| method | rule |
|---|---|
| `deduct194J` / `deductGeneric` | the plain aggregate rule above |
| `deduct194C` | **two thresholds that do different things.** A single bill above ₹30,000 is taxed **on that bill**, even though the year is nowhere near ₹1,00,000; the smaller earlier bills stay untaxed. Once the annual aggregate *also* crosses, tax falls on the whole of it. Conflating the two over-deducts. |
| `deduct194I` | the aggregate window is the payment's **calendar month**, not the fiscal year — and it resets when the month does. |
| `deduct194Q` | tax falls only on the value **in excess** of ₹50 lakh. Buying ₹52 lakh withholds 0.1% of ₹2 lakh (₹200), not of ₹52 lakh (₹5,200). |

Every one of those reads its rates and thresholds from the `tds_sections` row. The method names the
quirk; the table supplies the numbers.

Proven: `35,000` on a fresh 194C deductee who already paid `20,000` withholds **₹350** (1% of the
qualifying bill), *not* ₹550 (1% of the 55,000 aggregate). Adding `50,000` then crosses the annual
line and withholds **₹700** — bringing the cumulative to ₹1,050 = 1% of ₹1,05,000.

---

## Section 206AA — no PAN

> No PAN supplied → **20%, or the section rate, whichever is higher.** Never lower.

```php
$rate = $section->rateFor($deductee->deductee_type);          // 194C: 1% individual, 2% company
if (blank($deductee->deductee_pan)) {
    $rate = max($rate, $section->noPanFloor());               // 20 by default; 5 for 194Q
}
```

`deductee_pan` is deliberately a **separate column** from the existing `ledgers.pan` (the party's
general / Nepal-VAT PAN). Only `deductee_pan` drives 206AA — clearing it on the ledger master is
exactly what makes the next payment deduct at 20%.

Proven: the same ₹60,000 professional fee withholds ₹6,000 with a PAN and **₹12,000 without one**.
And `393-194Q` without a PAN resolves to **5%**, not 20%.

---

## The GST base rule — and why the client cannot lie about it

> Under GST law, TDS is deducted on the taxable value **excluding GST** when GST is shown separately.

The server does not take the client's word for the base. It **derives** it from the voucher's own
lines:

```
base = Σ (Dr lines whose ledger has tax_type IS NULL)
```

Every GST/VAT/TDS duty ledger carries a non-null `tax_type`, so they drop out of the sum *by
construction* — there is no special case anywhere. The payload **declares** its base; the server
recomputes it and rejects any mismatch.

```
Dr  Consultancy Fees   1,00,000     ← non-duty debit  → in the base
Dr  Input IGST            18,000     ← tax_type='integrated' → excluded
Cr  TDS Payable           10,000     ← 1,00,000 × 10%, not 1,18,000 × 10%
Cr  Bank                1,08,000     ← total debits − TDS
```

A payload declaring `base_amount: 118000` is refused outright. **A TDS-deducting Payment books the
expense and its GST explicitly** — you cannot recover the pre-GST split from a party's balance, so
that is the shape the engine requires.

---

## The posting shape

```
Dr  Expense ledger        the full taxable amount (professional fees, rent, contract payment …)
[Dr Input GST duty]       optional, when the bill charged GST
Cr  TDS Payable           the deducted amount — a liability owed to the Revenue
Cr  Bank                  the NET the vendor actually receives  ( = Σ debits − TDS )
```

Balanced by construction: the bank leg gives up exactly what the TDS line takes. When nothing is
deducted there is **no third line** and the voucher is the ordinary two-line Payment it always was.

* **One posting path.** TDS Payments go through `VoucherScreen::post()` like every other voucher —
  same `validatePayload()`, same integer-paise `$dr !== $cr` balance gate, same numbering, same
  transaction. There is no parallel path.
* **No new voucher type.** Remitting TDS to the government is a plain Payment
  (`Dr TDS Payable / Cr Bank`).
* **The TDS Payable ledger is the one duty ledger with a `NULL` `tax_role`.** It is neither output
  nor input tax. `GstService::taxLedgerMap()` selects on a non-null `tax_role`, so the `NULL` keeps
  it completely invisible to the GST and VAT engines — it can never be injected as a tax line on an
  invoice, and it never appears in either regime's summary.

---

## Server authority

`TdsService::verifyPayload()` is the fifth after-hook in `VoucherScreen::validatePayload()`, beside
the existing GST / VAT / bill-wise / cost-centre hooks, and it follows their shape exactly (return
`?string`, add to a distinct error key). It:

1. refuses a `TDS Payable` credit on **any** voucher while the feature is off;
2. refuses a `TDS Payable` credit that arrives **without** a declaration — a crafted payload cannot
   manufacture a liability;
3. refuses TDS on anything but a **Payment**;
4. refuses a section **not in force** for the voucher's fiscal year;
5. re-derives the **base** from the non-duty debits and refuses a declared base that disagrees;
6. **recomputes the deduction** from the section rate, the deductee type, Section 206AA and the
   deductee's live threshold state — and refuses if the posted `TDS Payable` line differs by a single
   paisa, quoting both figures and the reason.

The client never sends a deducted amount. It sends only *who*, *under which section*, and *on what
base*.

> **Proven:** a payload carrying the correct base but a tampered ₹3,000 TDS line (deliberately kept
> balanced, so only the TDS authority can catch it — the balance gate cannot) is rejected under the
> `tds` error key, **not** `balance`. The voucher is not persisted, and the deductee's year-to-date
> state is unchanged.

### Alter and cancel

`persistAlter()` calls `TdsService::reverseFor($voucher)` **before** anything is rewritten: the old
deduction comes out of the running state and its record is deleted. `persist()` then recomputes
against a state that no longer contains this voucher.

`cancelVoucher()` → `$voucher->delete()` → `Voucher::booted()`'s `deleting` hook → `reverseFor()`,
which runs *before* the FK cascade removes the rows while their amounts are still readable (the same
discipline `OrderService::reverseFulfillment` uses for Phase 8B).

**Documented semantic:** `excludeVoucherId` subtracts the voucher's *own* contribution, so an altered
voucher is re-derived **as though it were the year's latest payment**. Chronologically re-deriving
every *subsequent* voucher is out of scope. This is precisely what the `excludeVoucherId` parameter
describes, and it is deterministic: proven by altering the ₹20,000 voucher to ₹30,000 → prior state
becomes `55,000/5,500`, new aggregate `85,000` ⇒ `8,500 − 5,500 = 3,000` withheld, YTD `85,000/8,500`,
and `8,500 == 85,000 × 10%`.

---

## The client (`resources/js/vouchers/screen.js`)

The **Deduct TDS** panel appears below whichever entry layout a Payment is using (double-entry or
single-entry) — the deduction is derived from the *debits*, not from the layout.

* Naming a deductee defaults its usual section and engages the deduction (F12 `tdsAuto`, **default
  on**: forgetting to deduct is a statutory default with interest, so the safe state is opt-out).
* **Zero network while typing.** The panel ships with the year's individual TDS payments —
  `{ "deducteeId:sectionId": [{voucher_id, date, payment, deducted}, …] }` — not just totals, because
  194I windows by month and 194C asks which prior bills individually exceeded the single threshold.
  With the rows, the client reproduces the server's arithmetic **exactly**.
* Live: taxable base (with the GST it excluded), rate (flagged when 206AA applies), TDS, net paid,
  and the plain-English reason.
* `buildPayloadLines()` rewrites the balanced two-sided Payment into the three-line shape: reduce the
  single non-duty credit leg by the TDS, append the `Cr TDS Payable` line. Accept refuses if there is
  not exactly one bank/cash credit line to deduct from.
* Re-opening a saved voucher **restores the gross** the user originally typed (strips the TDS line,
  adds it back to the bank leg) and re-derives the deduction from a state excluding this voucher.
* **Alt+T** toggles the deduction, and the right button bar reflects it: the context reads
  *"Payment Voucher · TDS"* whenever a deduction is engaged.
* After a post, the just-recorded deduction is folded back into the year-to-date cache, so a
  continuous-entry session stays accurate without a reload.

The server re-verifies all of it. The cache is a convenience, never an authority.

---

## The TDS Deduction Summary report

`/reports/tds-summary` · Gateway **3** · Go To keywords *tds, deduction, 26q, deductee, 206aa*.

Per section → per deductee: base paid, TDS deducted, still payable. Enter drills to that pair's
Payment vouchers, where each row shows `Paid 15,000.00 · computed on 55,000.00 · 10%` — surfacing the
catch-up rather than hiding it.

**How "still payable" is computed, honestly.** Remitting TDS is an ordinary Payment against the TDS
Payable ledger. One challan clears many deductions and carries **no section tag**, so a remittance
cannot be attributed to a section directly. It is allocated **FIFO by (voucher date, id)** across the
fiscal year's deductions — which is how a challan actually discharges a liability. The allocation
reconciles exactly, and `prove-tds` asserts the identity:

```
Σ row.outstanding  ==  the TDS Payable ledger's closing balance
```

both before *and* after a remittance is posted.

---

## Gateway integration

| where | what |
|---|---|
| **F11 → Company Features** | `Enable Tax Deducted at Source (TDS · India)`. Orthogonal to the GST/VAT regime choice — it is *not* part of their mutual exclusion, because an Indian company deducts TDS whether or not it is GST-registered. |
| **Masters hub → `T`** | TDS Sections (shown when TDS is on) |
| **Masters → Ledgers** | a `TDS Deductee Details` block (PAN, deductee type, default section), gated on `feature('tds')` |
| **Gateway → `3`** | TDS Deduction Summary — beside `1` GST Returns and `2` VAT Return |
| **Go To (Alt+G)** | *TDS Sections* and *TDS Deduction Summary* |

The **TDS Sections** master is what makes the phase future-proof: create, edit, or **expire** a
section (`Alt+E` sets `effective_to` to the end of the previous year, so it vanishes from every picker
while its historical vouchers keep pointing at it). A section that any voucher has deducted under
cannot be deleted — the screen says so and offers to expire it instead. Repealed sections are hidden
by default and revealed with `Alt+H`.

---

## `zerobook:prove-tds` — 151 assertions, 0 failures

Provisions a throwaway tenant, enables TDS **and** GST, and drives everything through
`VoucherScreen::post()`.

```
Rate table — Section 393 in force, the 194-series repealed
  [PASS] Live sections for FY 2026-27 are the 393-series only
  [PASS] Expired 194J is NOT in the live list = false
  [PASS] …but it still exists in the catalog (historical vouchers need it) = true
  [PASS] 194I-B carries two date ranges (annual → monthly) = 2
  [PASS] TDS Payable is tax_type=tds with a NULL tax_role (invisible to GST) = tds/null

Below threshold — ₹40,000 of a ₹50,000 annual threshold
  [PASS] V1 posts exactly two lines (no TDS line) = 2
  [PASS] V1 still writes a tds_deductions row (the audit trail) = 1
  [PASS] V1 row has no voucher_entry_id (no TDS line to point at) = NULL

Threshold crossed — the aggregate catch-up
  [PASS] V2 Cr TDS Payable = 55,000 × 10% (the WHOLE aggregate) = 5500
  [PASS] V2 Cr Bank = 15,000 − 5,500 (vendor short-paid by the catch-up) = 9500
  [PASS] V2 row base_amount = the aggregate it was computed on = 55000
  [PASS] V2 row payment_amount = this voucher only = 15000
  [PASS] The ₹4,000 never withheld on V1 is caught up here = 4000

Section 206AA — no PAN ⇒ 20%, not the section rate
  [PASS] V4 Cr TDS Payable = 60,000 × 20% = 12000
  [PASS] 194Q’s 206AA floor is 5%, not 20% (the proviso — data, not code) = 5

GST base — TDS falls on the taxable value, not the GST-inclusive dues
  [PASS] V5 party dues were 1,18,000 but TDS base is 1,00,000 = 100000
  [PASS] A base of 1,18,000 (GST-inclusive) is rejected = true

Server authority — a tampered TDS amount is rejected
  [PASS] Tampered TDS rejected by the TDS authority (not the balance gate) = true
  [PASS] …and the voucher was NOT persisted
  [PASS] A TDS Payable credit with no declaration is rejected = true
  [PASS] A repealed section on a current-year voucher is rejected = true

194C single-bill threshold taxes THAT BILL, not the aggregate
  [PASS] B2 35,000 > the 30,000 single threshold ⇒ 1% of 35,000 = 350
  [PASS] …NOT 1% of the 55,000 aggregate (the small bill stays untaxed) = false

194Q deducts on the EXCESS over the threshold
  [PASS] Q1 52,00,000: 0.1% of the 2,00,000 excess = 200
  [PASS] …NOT 0.1% of the whole 52,00,000 = false

194I aggregates PER MONTH and resets when the month does
  [PASS] R2 Apr aggregate 60,000 crosses ⇒ 10% of 60,000 = 6,000
  [PASS] R3 MAY is a new window ⇒ 30,000 is below threshold again, no TDS = 0

Deduction Summary — and Σ outstanding == TDS Payable closing
  [PASS] THE RECONCILIATION: Σ outstanding == TDS Payable ledger closing = 3590000
  [PASS] Reconciliation STILL holds after the remittance = 3040000
  [PASS] FIFO cleared Legal Advisors’ 393-194J deduction entirely = 0.00

TDS disabled (F11) — a Payment behaves exactly as before
  [PASS] No deduction row written · YTD untouched · TB still balances
```

Also proven: alter re-derivation, cancel reversal, per-(deductee, section) independence (one deductee
carrying two YTD rows that never interact), and a balanced Trial Balance throughout.

---

## Browser verification — and three pre-existing defects it uncovered

Phase 10A was driven end-to-end in a real browser against a tenant subdomain
(`http://tdsui.localhost:8777`, which Chrome resolves to loopback without a hosts entry, and which
`localhost` being a central domain makes resolvable as a subdomain).

Posting the three canonical payments in **one continuous-entry session** produced exactly the
documented ledger rows, `tds_deductions` rows and year-to-date state; altering the catch-up voucher
from ₹15,000 to ₹30,000 over HTTP re-derived ₹7,000 and left `95,000 / 9,500` (= 10% of 95,000) with a
balanced Trial Balance. **Zero console warnings, zero console errors** during a post.

Getting there required fixing three defects that **predate this phase** and had never been exercised,
because the prove battery drives `VoucherScreen::post()` in-process inside `tenant()->run()` and the
HTTP click-through was never done end-to-end:

1. **Livewire's `update` endpoint ran outside the tenant middleware stack** *(Phase 7B)*. `routes/tenant.php`
   wraps every screen in `[web, InitializeTenancyBySubdomain, PreventAccessFromCentralDomains]`, but
   Livewire registers its own POST endpoint — the one that receives *every* component action — from
   its service provider, outside that group. So the initial GET of a screen ran against the tenant
   database while **every subsequent commit ran against the central database**, where the accounting
   tables do not exist. Result: a 500 on the first `Ctrl+A`, on every tenant subdomain, for any
   voucher. Fixed in `AppServiceProvider::boot()` by re-registering the endpoint with the identical
   middleware stack.

2. **`movement: null` was rejected on every non-stock voucher** *(Phase 6C)*. The client always sends
   `movement: null` for a Payment/Journal/Sales, but the rule was `[requiredIf($isStock), 'array']`
   with no `nullable` — and `null` fails `array`. No voucher could post through the screen. The prove
   commands omit the key entirely, which is why it hid. Fixed with one word; a stock voucher still
   *requires* its movement (asserted).

3. **Livewire re-rendered the voucher screen after every post**, rewriting the root element's
   `x-data` attribute (bootData's `nextNumbers`/`balances` change on each post). Alpine tore the
   component down and re-initialised it mid-morph: 184 `line is not defined` warnings, a duplicated
   pushed context, and the discarded entry state. The screen is Alpine-driven and commit-only, so
   `post()`, `cancelVoucher()`, `bookQty()`, `referenceInvoice()` and `referenceOrder()` now call
   `skipRender()`. Because nothing re-renders, `post()` returns the just-recorded deduction so the
   client can fold it into its year-to-date cache — otherwise the *next* payment to the same deductee
   would be computed against a stale aggregate, the client would under-deduct, and the server would
   (correctly) refuse a voucher the user could not explain.

All 18 prove-commands remain green after these three fixes.

---

## Files

**Schema** — one tenant migration, one central migration, one phpMyAdmin script.

| file | |
|---|---|
| `database/migrations/tenant/2026_07_16_000001_add_tds_engine.php` | `tds_sections`, `tds_deductee_ytd`, `tds_deductions`, `company_features.tds`, ledger deductee columns, `tax_type` ENUM widened with `tds`, seeds |
| `database/migrations/2019_09_15_000006_add_tds_to_plan_features.php` | **central** — adds `tds: true` to every plan tier |
| `_docs/phase10a_schema.sql` | the phpMyAdmin equivalent; **verified** by rolling a tenant back to the pre-10A schema, applying the script, and diffing the resulting columns against the migration's (identical), then running the catch-up scenario on it |

> **Why the central migration is not optional.** `Plan::allows()` returns **false** for a feature key
> absent from the JSON, and `PlanGate::violation()` is the server-side security boundary the F11 save
> path calls. Without it, every tenant provisioned before Phase 10A would find TDS permanently locked —
> toggle disabled, save rejected. TDS is unlocked on **every** tier, exactly as GST and VAT are: it is
> statutory compliance, not a premium add-on.

**Engine** — `app/Services/TdsService.php`, `app/Models/{TdsSection,TdsDeduction,TdsDeducteeYtd}.php`,
`database/seeders/TdsSectionSeeder.php`, `TaxLedgerSeeder` (+ TDS Payable).

**Shared path** — `VoucherScreen` (after-hook, payload rules + normalisation, `persist`/`reverseFor`
in both write paths), `Voucher::booted()` (cancel hook), `Ledger`, `CompanyFeature`, `PlanGate`, `Shell`.

**Screens** — `partials/tds-deduct.blade.php`, `TdsSectionWorkspace` (+ blade + `masters/tdssection.js`),
`Reports\TdsSummary` (+ blades + `tdsSummary` in `reports/screen.js`), ledger + F11 blades,
`vouchers/screen.js`, `masters/store.js`, `config/store.js` (F12 `tdsAuto`), `vouchers.css` /
`masters.css` / `reports.css` (ZeroBook brand tokens only — amber for money being withheld).

**Proof** — `app/Console/Commands/ProveTdsCommand.php`.

---

## Deploying to an existing tenant

```bash
php artisan migrate            # central — plans.features gains "tds"
php artisan tenants:migrate    # each tenant — tables, columns, TDS Payable ledger, section catalog
npm run build
```

A browser-trial tenant is left provisioned: **`tdsui`** (`owner@tdsui.test` / `zerobook`), carrying
the three canonical payments — YTD `75,000 / 7,500`. Reach it at `http://tdsui.localhost:8777`.

---

## Deferred to 10B

**Form 26Q FVU generation is not built.** It is gated on the current **TDS Return Preparation
Utility**'s file specification, in exactly the discipline Phase 9A and 9B established:

> Demand the government's real artifact before writing a field name. If it is login-gated or
> unpublished, **stop and ask**. If it is public, fetch it, read it yourself, commit it to the repo,
> extract a page-cited fields JSON, and make the proof (a) refuse to run without it and (b) assert
> conformance against it.

In both prior compliance phases the real artifact **contradicted the prompt in about five places
each**. The FVU format is a fixed-width, pipe-delimited file validated by NSDL's own FVU binary, and
its record layout changes with each utility release. Guessing it would produce a file the utility
rejects — the one outcome worse than not shipping it.

Everything 10B needs is already recorded: `tds_deductions` carries the deductee, PAN, section, amount
paid, rate, tax deducted and fiscal year per voucher; `tds_deductee_ytd` carries the quarterly
aggregates; and the deductee's PAN and type live on the ledger.

**Also out of scope, as specified:** Form 16A certificates, TCS (the sales-side mirror — the same
engine with the rate table's roles swapped), salary TDS (Section 392 / old 192), and lower-deduction
/ NIL certificates under Section 197. TDS remittance needs no special voucher: a plain Payment against
the TDS Payable ledger already does it, and the summary report allocates it FIFO.

---

## Known limitations, stated plainly

* **Rates and thresholds are unverified defaults.** A CA must reconcile the catalog against the
  Finance Act before customer use. Said on the F11 screen, on the master screen, in the seeder, and
  here.
* **Altering a voucher re-derives it as the year's latest payment.** Subsequent vouchers are not
  chronologically re-derived. This is the semantic `excludeVoucherId` describes, and it is
  deterministic.
* **A TDS-engaged Payment treats its entire non-duty debit total as one taxable base.** To pay two
  expenses under two different sections, use two vouchers. The server enforces this rather than
  guessing an apportionment.
* **Remittance is allocated FIFO** because a challan carries no section tag. The allocation reconciles
  to the TDS Payable ledger exactly; it is not a claim about which challan the department applied to
  which deduction.
* **Interest and late-fee computation under 201(1A) / 234E is not implemented.**
