# ZeroBook — Phase 5E: Nepal VAT (a second, mutually-exclusive tax regime)

Adds Nepal's flat-rate **VAT** as a company-level tax regime **mutually exclusive
with GST**, with its own **Tax Invoice** print format and a **VAT Summary** report.
It mirrors the verified `GstService` architecture (same posting path, same
duty-ledger pattern, same server-side authority) but is simpler: **one flat rate,
no intra/inter-state split, one VAT line per slab.**

Every VAT invoice posts through the **one shared, balance-gated path**
(`VoucherScreen::post()`), and VAT is **authoritative on the server** — never
trusted from the client — exactly as GST.

---

## Step 0 — audit & findings

**All prior proofs PASS** (`prove-costcentre`, `prove-billwise`, `prove-gst`,
`prove-sales-purchase`, `prove-balance`). **Phase 6A (inventory) is not present**
in this codebase — the prompt's sequencing assumed it. No blocker: 5E's rate
reuse lives on **ledgers** (`gst_rate`/`hsn_sac`), which exist; the rate lookup is
built as a single seam (`rateForLedger()`) so **6B extends it to `stock_items`**
without a rewrite.

**Confirmed by reading the real GST code and mirrored precisely:**
- `ledgers.tax_type` is a **DB enum** → migrated to `enum('central','state','integrated','vat')`.
- `gst_rate`/`hsn_sac`/`gstin` are **reused and relabelled**, never duplicated.
- `TaxLedgerSeeder` is a `[name,role,type]` list → **extended** with Output/Input VAT.
- The GST server check `app(GstService)->verifyInvoicePayload()` sits in
  `VoucherScreen::validatePayload()`'s after-hook and no-ops when GST is off — so
  the VAT check is added **alongside** it; each no-ops when its regime is inactive.

---

## Mutual exclusivity (GST ⇄ VAT)

GST and VAT are exclusive at the company level. On the **F11 Features** screen,
turning one on turns the other off with a visible message ("VAT turned off — GST
and VAT are mutually exclusive"), enforced client-side in the toggle **and**
server-side in `FeaturesScreen::save()` (a both-true payload is rejected).
`CompanyFeature` gains a `vat` boolean + a `company_pan` (the VAT tax-id analogue
of `company_gstin`, edited on F11 when VAT is on; the GST company-state field is
hidden under VAT).

---

## Field reuse & relabeling (same columns, contextual labels)

| Concept | Column | Under GST | Under VAT |
|---|---|---|---|
| Rate | `ledgers.gst_rate` | "GST rate (%)" | "VAT rate (%)" (defaults to 13) |
| HS/SAC | `ledgers.hsn_sac` | "HSN / SAC" | "HS Code" |
| Party tax-id | `ledgers.gstin` | "GSTIN" | "PAN (VAT)" |
| Party state | `ledgers.state` | shown (intra/inter) | hidden (no state concept) |
| Registration type | `ledgers.gst_registration_type` | shown | hidden (GST-only) |
| Company tax-id | `company_features.company_gstin` / `company_pan` | GSTIN + state | PAN |

The ledger create/alter forms and the inline quick-create all relabel via the
active regime; the underlying columns are unchanged.

---

## VAT duty ledgers + `VatService`

`TaxLedgerSeeder` adds two reserved ledgers under **Duties & Taxes**: **Output VAT**
(`tax_role=output, tax_type=vat`) and **Input VAT** (`input, vat`).

`app/Services/VatService.php` mirrors `GstService`:
- `enabled()`, `companyPan()`, `rateForLedger()` (the same `gst_rate` seam),
  `taxLedgerId('output'|'input')`, `computeInvoiceTax($type, $taxable)` →
  `VAT_paise = round(base × rate / 100)`, one VAT line per rate slab.
- `verifyInvoicePayload($payload)` — recompute the expected VAT from the taxable
  lines + ledger rate, compare to the posted Output/Input VAT line(s) to the paise,
  **reject on mismatch**. Wired into the shared after-hook alongside GST; a no-op
  (returns null immediately) unless VAT is enabled and it is a party-set invoice.
- `summary($from, $to)` — Output VAT, Input VAT, Net Payable = Output − Input.

(Cross-regime fix: `GstService::summary()` now filters to the GST tax types, so the
VAT duty ledgers never leak into the GST summary — caught by re-running `prove-gst`.)

---

## Derived double-entry & client integration

- **Sales:** `Dr Party (incl. VAT)` · `Cr Sales (taxable)` · `Cr Output VAT`.
- **Purchase:** `Dr Purchase (taxable)` · `Dr Input VAT` · `Cr Party (incl. VAT)`.
- Party total = Σ taxable + Σ VAT, to the paise → the voucher balances.

The invoice-mode client (`resources/js/vouchers/screen.js`) is now **regime-aware**:
`taxComputation` dispatches to `_gstComputation()` (CGST/SGST/IGST slabs) or
`_vatComputation()` (a single VAT line per slab), reading the VAT duty-ledger ids
from `window.ZB_VAT.tax_ledgers`. The single VAT line shows and updates **live, 0
network**, and is injected into the payload posted through the shared path. GST off
+ VAT off → invoices behave exactly as pre-tax ledger invoices.

---

## Schema

Migration `2026_07_07_000009_add_nepal_vat_regime.php` + `_docs/phase5e_schema.sql`:
`tax_type` enum widened with `vat`; `company_features.vat` (bool) + `company_pan`
(string); seeder adds the two VAT duty ledgers. All additive/nullable — nothing
before VAT is affected.

---

## Worked numeric proof — `php artisan zerobook:prove-vat`

Runs inside ONE always-rolled-back transaction, so it **restores the company's
prior regime** and never breaks the other proofs. Posts VAT invoices through
`VoucherScreen::post()`.

```
Enabling VAT disabled GST (mutual exclusivity) ✓
Sales    10,000 @ 13% :  Dr Kathmandu Traders 11,300 · Cr Sales 10,000 · Cr Output VAT 1,300   (no CGST/SGST/IGST)
Purchase  6,000 @ 13% :  Dr Purchase 6,000 · Dr Input VAT 780 · Cr Pokhara Supplies 6,780
Tampered VAT payload (1,200 ≠ 1,300) → rejected by the server ✓
VAT Summary : Output 1,300 · Input 780 · Net Payable 520
Trial Balance : Dr 11,300 = Cr 11,300 balanced ✓
```

**All 13 assertions PASS**, and the command rolls back (prior regime restored).

### Verified live in the browser
- **F11 mutual exclusivity:** toggling GST on turns VAT off (and vice-versa) with the message; exactly one regime active.
- **Live VAT invoice:** a Sales invoice for a 13% ledger shows a **single** "VAT @13% = 1,300" line (badge "VAT (Nepal · single rate)"), grand 11,300 — **0 network**; posts `Dr Party 11,300 / Cr Sales 10,000 / Cr Output VAT 1,300`.
- **VAT Summary:** Output 1,300 / Input 780 / **Net VAT Payable 520**, Company PAN shown, tax lines drill.
- **Tax Invoice print:** heading **"Tax Invoice"**, company + party **PAN** (no "GSTIN" anywhere), VAT breakup, **"Nepali Rupees Eleven Thousand Three Hundred Only"**.
- **Regression:** all six `prove-*` commands pass; the regime is unchanged after `prove-vat`; no console errors.

---

## Files

**New**
- `database/migrations/2026_07_07_000009_add_nepal_vat_regime.php` + `_docs/phase5e_schema.sql`
- `app/Services/VatService.php`
- `app/Livewire/Reports/VatSummary.php` + `resources/views/{reports,livewire/reports}/vat-summary.blade.php`
- `app/Console/Commands/ProveVatCommand.php`

**Extended**
- `database/seeders/TaxLedgerSeeder.php` — Output/Input VAT
- `app/Models/CompanyFeature.php` — `vat` cast + `toFlags` + `vatProfile()`
- `app/Livewire/FeaturesScreen.php` (+ blade) — VAT toggle (mutual exclusivity) + company PAN
- `app/Livewire/VoucherScreen.php` — VAT server authority in the shared after-hook + `vat` boot data
- `app/Services/GstService.php` — `summary()` filters to GST tax types (cross-regime fix)
- `app/Support/Shell.php` — `vatConfig()` + VAT Summary in Go To / hub
- `app/Http/Controllers/{Vouchers,Reports}Controller.php` — regime-aware print + VAT summary route
- `resources/js/vouchers/screen.js` — regime-aware `taxComputation` (single VAT line) + relabel getters
- `resources/views/livewire/voucher-screen.blade.php`, `resources/views/partials/quick-ledger.blade.php`, `resources/views/livewire/ledger-workspace.blade.php` — GST↔VAT relabeling
- `resources/views/vouchers/print.blade.php` — Nepal Tax Invoice branch (heading/PAN/Nepali Rupees)
- `resources/views/layouts/app.blade.php` — `window.ZB_VAT`
- `routes/web.php`, `resources/css/shell.css`

---

## Not in 5E (later)
VAT return filing / IRD submission; registration-threshold logic; simultaneous
GST + VAT (explicitly unsupported); Devanagari typesetting (English labels this
phase); any inventory/item work (Phase 6B — tax computation is now dual-regime-aware).
