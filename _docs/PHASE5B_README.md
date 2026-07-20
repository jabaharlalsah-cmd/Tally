# ZeroBook — Phase 5B: GST (tax ledgers, auto tax on invoices, GST summary)

Adds the GST tax engine: a company GST profile, six duty ledgers, **automatic
CGST+SGST / IGST computation on Sales & Purchase invoices**, GST details on
masters, a **GST summary report**, and a tax breakup on the printed invoice.

The tax is **authoritative on the server** — the same `GstService` that computes
it re-verifies every posted invoice and rejects any tampered tax — and every GST
invoice still posts through the **one shared, balance-gated path**
(`VoucherScreen::post()`). Return filing (GSTR-1/3B) is a later phase.

---

## Step 0 — audit & where the profile lives

**5A audit → PASS.** `zerobook:prove-sales-purchase` (19 asserts) and
`zerobook:prove-balance` both pass; invoices post through `VoucherScreen::post()`
and the Trial Balance balances. No 5A fixes were needed.

**Company GST profile** lives on the single-row **`company_features`** table
(model `CompanyFeature`), extended with `company_gstin` + `company_state`. It is
edited on the **F11 Features** screen (revealed when GST is on) and surfaced to
the client as `window.ZB_GST` via `Shell::gstConfig()`. The **company state**
drives intra- vs inter-state on every invoice.

---

## Tax-ledger design

Six **reserved** ledgers under **Duties & Taxes** (`TaxLedgerSeeder`), Output and
Input kept **separate** so the GST summary can show output tax vs input tax (ITC)
unambiguously. Each carries two tags so the engine and the server verifier can
pick the exact ledger without name-matching:

| Ledger | `tax_role` | `tax_type` |
|---|---|---|
| Output CGST / SGST / IGST | `output` | central / state / integrated |
| Input CGST / SGST / IGST | `input` | central / state / integrated |

New ledger columns: `tax_type`, `tax_role`, `gst_rate` (on Sales/Purchase
nominal ledgers), `hsn_sac`, `gst_registration_type` (on parties). `gstin` +
`state` already existed. Migration
`2026_07_07_000005_add_gst_to_company_and_ledgers.php` + phpMyAdmin script
`_docs/phase5b_schema.sql`.

---

## The GST rules as implemented

- **Place of supply:** intra-state when `party.state == company.state`, else
  inter-state. If either state is blank → intra-state (the conservative default).
- **Tax type:** intra → **CGST + SGST**, each at half the rate; inter → **IGST**
  at the full rate.
- **Rate source:** the `gst_rate` on the Sales/Purchase **ledger** (e.g. Product
  Sales @ 18%). Designed as a single `rateForLedger()` lookup so an item-level
  rate can slot in at Phase 6 without a rewrite.
- **Computation (integer paise, identical on client + server):**
  `IGST = round(baseP × r / 100)`, `CGST = SGST = round(baseP × r / 200)`.
  Taxable lines are grouped **by rate slab**; each (tax-type, slab) is its own tax
  line, so mixed-rate invoices keep their slabs separable.
- **Party leg = Σ taxable + Σ tax** (to the paise) so the voucher balances.

**Derived double-entry**

| | intra-state | inter-state |
|---|---|---|
| **Sales** | Dr Party · Cr Sales · Cr Output CGST · Cr Output SGST | Dr Party · Cr Sales · Cr Output IGST |
| **Purchase** | Dr Purchase · Dr Input CGST · Dr Input SGST · Cr Party | Dr Purchase · Dr Input IGST · Cr Party |

---

## How tax is computed client-side and re-verified server-side

**Client (0 network).** In invoice mode the `voucherScreen` controller
(`resources/js/vouchers/screen.js`) reads each allocation ledger's `gst_rate` and
the party's `state` from the Phase 2 masters cache, decides intra/inter against
`window.ZB_GST.company_state`, and computes the tax with the exact paise formula
above. It shows the **CGST/SGST/IGST lines and the tax-inclusive total live**, and
`buildPayloadLines()` injects the tax lines (using the duty-ledger ids from
`ZB_GST.tax_ledgers`) between the ledger legs and the party leg.

**Server (authority).** `App\Services\GstService::verifyInvoicePayload()` runs
inside `VoucherScreen::validatePayload()`'s balance closure for every GST-enabled
invoice post. It splits the posted lines into party / taxable / tax, **recomputes
the expected tax** from the taxable amounts + ledger rates + party state, and
**rejects the voucher if any tax ledger's posted total ≠ the recomputed total**
(to the paise). Client tax is never blindly trusted; the paise balance gate still
applies on top. A manual "as Voucher" Sales/Purchase (no party) is left to the
user; GST off → no tax lines, behaves exactly as 5A.

---

## GST summary report

`/reports/gst-summary` (Livewire `Reports\GstSummary` + `GstService::summary()`,
which reuses `BalanceService::ledgerBalances()`). For the F2 period it shows, for
**Outward (Sales)** and **Inward (Purchase)** supplies: taxable value, CGST/SGST/
IGST, section totals, and **Net GST Payable = Output − Input (ITC)**. Each tax
line drills (`Enter`) to that duty ledger's Ledger Vouchers, reusing the Phase 4
drill. Reachable from Go To and the Gateway hub (letter **X**).

## Print tax breakup

The 5A invoice print now shows, for a GST invoice: each line's **HSN/SAC + rate**,
a **Taxable Value** subtotal, the **CGST/SGST/IGST** lines, the **tax-inclusive
Invoice Total**, the **party GSTIN and company GSTIN**, and amount-in-words on the
inclusive total. Non-GST invoices print exactly as before.

---

## Worked numeric proof — `php artisan zerobook:prove-gst`

Posts four GST invoices **through `VoucherScreen::post()`** (company state
Maharashtra) and asserts every leg, the server authority, the summary, and the
Trial Balance. Rolls back unless `--keep`.

```
Intra sale 10,000 @ 18% (MH → MH):  Dr Acme 11,800 · Cr Sales 10,000 · Cr Output CGST 900 · Cr Output SGST 900
Inter sale 10,000 @ 18% (MH → GJ):  Dr Zeta 11,800 · Cr Sales 10,000 · Cr Output IGST 1,800
Purchase   6,000 @ 18% (MH → MH):   Dr Purchase 6,000 · Dr Input CGST 540 · Dr Input SGST 540 · Cr Metro 7,080
Multi-rate sale 10,000@18 + 2,000@5 (MH): CGST 900+50, SGST 900+50 per slab, total 13,900

GST Summary:  Output 5,500 · Input (ITC) 1,080 · Net payable 4,420 · Taxable sales 32,000 · purchase 6,000
Trial Balance: Dr 44,580.00 = Cr 44,580.00  balanced=YES
```

**All 19 assertions PASS**, including:
- Intra legs (Dr Party 11,800; Cr Sales 10,000; Cr CGST 900; Cr SGST 900; **no IGST leg**).
- Inter legs (Dr Party 11,800; Cr **IGST** 1,800; **no CGST leg**) — proving intra/inter by `party.state vs company.state`.
- Purchase mirrors on the Dr side with Input CGST/SGST.
- **Server authority: a tampered payload (CGST posted as 800 instead of 900, still balanced) is rejected.**
- GST summary output 5,500 / input 1,080 / net 4,420 / taxable 32,000 / 6,000.
- Trial Balance balanced.

### Verified live in the browser
- **GST summary** renders Output CGST 1,850 / SGST 1,850 / IGST 1,800 (Total 5,500), Input 540/540/0 (Total 1,080), **Net GST Payable 4,420**; tax rows drill to the duty ledger.
- **Print** of the intra sale: line `Sales 18% · HSN/SAC 9983 · 18%`, Taxable 10,000, Output CGST 900, Output SGST 900, **Invoice Total 11,800**, party + company GSTIN, words "Indian Rupees Eleven Thousand Eight Hundred Only".
- **Fresh invoice, live:** MH customer → `Intra-state (CGST + SGST)` with CGST 900 + SGST 900, grand 11,800; switching the party to a Gujarat customer flips **live** to `Inter-state (IGST)` 1,800 — **0 network**; posting SALE-4 succeeds via the shared path.
- **F11** reveals the company GST profile (state Maharashtra, GSTIN) when GST is on.
- **Regression:** with GST off, `prove-sales-purchase` and `prove-balance` still pass; no console errors.

---

## Files

**New**
- `database/migrations/2026_07_07_000005_add_gst_to_company_and_ledgers.php`
- `database/seeders/TaxLedgerSeeder.php` (wired into `DatabaseSeeder`)
- `_docs/phase5b_schema.sql`
- `app/Services/GstService.php` — compute + server verify + summary
- `app/Livewire/Reports/GstSummary.php` + `resources/views/{reports,livewire/reports}/gst-summary.blade.php`
- `app/Console/Commands/ProveGstCommand.php`

**Extended**
- `app/Models/Ledger.php` — GST columns fillable/cast + `toCache()` (state/gst_rate/tax_type/tax_role)
- `app/Models/CompanyFeature.php` — `gstProfile()`
- `app/Livewire/VoucherScreen.php` — server GST verify in `validatePayload()`; `gst` boot data
- `app/Livewire/FeaturesScreen.php` (+ blade) — company GSTIN + state
- `app/Livewire/LedgerWorkspace.php` (+ blade) — gst_rate / hsn_sac / registration type (GST-gated)
- `app/Livewire/Concerns/CreatesLedgers.php` (+ `partials/quick-ledger`) — inline GST fields
- `app/Support/Shell.php` — `gstConfig()`, GST summary in nav + hub
- `app/Http/Controllers/{Reports,Vouchers}Controller.php` — GST summary route + print tax breakup
- `resources/js/vouchers/screen.js` — live tax computation + inject tax lines + strip on edit
- `resources/js/reports/screen.js` — `gstSummary` controller
- `resources/views/livewire/voucher-screen.blade.php` — live tax breakup + inclusive total
- `resources/views/vouchers/print.blade.php` — tax breakup + both GSTINs
- `resources/views/layouts/app.blade.php` — `window.ZB_GST`
- `resources/css/{vouchers,reports}.css` — tax rows / badge / summary styling
- `routes/web.php` — `reports.gst-summary`

---

## Not in 5B (later phases)
GSTR-1 / GSTR-3B return filing & JSON export; bill-wise details, Outstandings,
cost centres (5C); inventory / stock-item HSN & item-wise tax (Phase 6 — the rate
lookup is a single `rateForLedger()` seam ready for item rates); **Nepal VAT** —
the same tax-ledger engine can carry Nepal's single-rate VAT (one tax_type, full
rate, no intra/inter split) as a small later addition.
