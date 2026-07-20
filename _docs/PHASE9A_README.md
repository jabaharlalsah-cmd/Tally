# ZeroBook — Phase 9A: GSTR-1 & GSTR-3B JSON Export

**Goal:** produce **portal-ready JSON** for the two returns every GST-registered Indian
business files — **GSTR-1** (all outward supplies, invoice level) and **GSTR-3B**
(summary of liability, input credit, net payable) — so a user or their CA can upload them
via the GST Offline Tool or the portal's JSON import. ZeroBook does **not** submit returns
(that needs a commercial GSP arrangement); this is the shortest honest path to a filed
return, and it is what most Indian SMEs actually do today.

**Schema fidelity is the whole feature.** A JSON with one field wrong is rejected. So
nothing here was written from recollection.

```bash
php artisan zerobook:prove-gstr1     # worked numeric proof, refuses without the real schema
php artisan zerobook:prove-gstr3b
php artisan zerobook:gstr1-export  --tenant=acme --period=042026 --output=gstr1.json
php artisan zerobook:gstr3b-export --tenant=acme --period=042026 --output=gstr3b.json
```

---

## Step 0 — audit + how the schema was obtained

**Audit — all fourteen prior proves pass, before and after 9A.** The self-provisioning
tenant proves (`prove-notes`, `prove-sync`, `prove-order-flow`, `prove-multi-tenant`) run
directly; `prove-tally-import` takes `--tenant=`; the nine pre-tenancy proves run against a
provisioned tenant's database (`DB_DATABASE=tenant<slug>`), since after the 7B restructure
business data lives only in tenant DBs. **After 9A: 16/16 green** (14 prior + 2 new).

**Obtaining the schema (the part that mattered).** The user supplied the two Government
artifacts: `gst_offline_tool.zip` and `GSTR3B_Excel_Utility.zip`. **Neither ships a JSON
schema** — both only *generate* JSON. So the schema was read out of GSTN's own code:

* `gst_offline_tool.zip` is an **Inno Setup 6.1.0** installer wrapping a **Node.js +
  AngularJS** app (`GST Offline Tool.exe`, v3.2.4). Unpacked:
  * `utility/common.js → formDataFormat()` — the authoritative **top-level GSTR-1 template**,
    including `HSN_BIFURCATION_START_DATE = "052025"` and `version = "GST3.2.4"`.
  * `utility/returnStructure.js` (per-section builders), `utility/jsonToExcel.js` (names
    every key), `utility/constants.js` (`B2CL_MIN_VAL = 100000`, `B2CL_MIN_VAL_STR_PRD = '082024'`).
* `GSTR3B_Excel_Utility_V5.8.xlsm → xl/vbaProject.bin` — the VBA concatenates the GSTR-3B
  JSON literally; the keys and nesting were read directly from it.

Every section was extracted and then **independently re-verified against a different source
file**. That adversarial pass caught a real error: `cdnur`'s `itm_det` carries **only**
`{txval, rt, iamt, csamt}` — no intra-state branch, so `camt`/`samt` are never emitted.

The derived documents live at `_docs/gstn-schemas/gstr1-schema.json` and `gstr3b-schema.json`
(with `_meta`, `_skeleton`, `_fields`, `_rules`, and for 3B an `_absent` list). See
`_docs/gstn-schemas/README.md` for full provenance and how to refresh them.

### Corrections the real artifacts forced on this phase's own spec

| The 9A prompt said | GSTN's tool v3.2.4 actually emits |
|---|---|
| `docs` | **`doc_issue: { doc_det: [ { doc_num, docs: [ … ] } ] }`** |
| a flat `hsn` section | **`hsn: { hsn_b2b: [], hsn_b2c: [] }`** for `fp ≥ 052025`; `hsn: { data: [] }` before |
| GSTR-3B `tax_pmt.tx_pyd.*` | **no tax-payment section exists at all** (neither `tax_pmt` nor `tx_pmt`) |
| period `YYYYMM` | **`MMYYYY`** — `"042026"` is April 2026 |
| B2CL threshold ₹2.5 lakh | **₹2,50,000 before `082024`; ₹1,00,000 from `082024`**, compared strictly `>` |

Had the exporter been built to the prompt's field list, the portal would have rejected it.
This is precisely why the build was paused until the real artifacts were in hand.

### `GstService::summary()` — confirmed

`summary(from,to)` aggregates in **integer paise**: `taxable_sales` / `taxable_purchase`
(from the Sales/Purchase Accounts groups, so Sales Return is already netted off),
`output{central,state,integrated}` (presented positive), `input{…}`, and
`net_payable = output_total − input_total`. GSTR-3B is therefore a **thin field-name mapping
over the existing aggregation** — no new tax math. GSTR-1 needed the real work: invoice-level
section builders.

---

## Section-by-section mapping — which ZeroBook data flows where

| GSTR-1 section | Source | Rule |
|---|---|---|
| **b2b** | `sales` with a party holding `ledgers.gstin` | grouped by `ctin`; invoice level |
| **b2cl** | `sales`, party has no GSTIN, **inter-state**, `val >` period threshold | grouped by `pos` |
| **b2cs** | all other `sales` to unregistered parties | summed by (`sply_ty`, `pos`, `rt`) |
| **cdnr** | `credit_note` to a party with a GSTIN | grouped by `ctin`, `ntty = "C"` |
| **cdnur** | `credit_note` to an unregistered party that would have been B2CL | flat, `typ = "B2CL"` |
| *(credit notes to unregistered parties below the threshold)* | — | **netted −** into `b2cs` |
| **hsn** | item lines of the above (`stock_items.hsn_sac`, `units.symbol → UQC`) | `hsn_b2b` / `hsn_b2c`, sales `+`, credit notes `−` |
| **doc_issue** | number ranges of `sales` (1), `debit_note` (4), `credit_note` (5) | gaps in the range ⇒ `cancel` |
| exp / nil / at / ecom … | — | emitted **empty** (reported and skipped) |

| GSTR-3B field | Source |
|---|---|
| `sup_details.osup_det.txval` | `summary().taxable_sales` (net of Sales Return) |
| `.iamt / .camt / .samt` | `summary().output.integrated / central / state` |
| `sup_details.osup_zero / osup_nil_exmp / osup_nongst / isup_rev` | zero — ZeroBook doesn't classify these |
| `itc_elg.itc_avl[ty="OTH"]` | `summary().input.*` (all ITC is ordinary domestic input tax) |
| `itc_elg.itc_net` | = 4(A) − 4(B); Debit Notes already reduce the input ledgers, so 4(B) is zero |
| `inter_sup.unreg_details[]` | inter-state sales to unregistered parties, by `pos` |
| `eco_dtls`, `inward_sup`, `intr_ltfee` | zero — not tracked / entered on the portal |

**Why a Debit Note is not in GSTR-1.** GSTR-1 reports *outward* supplies. ZeroBook's
`credit_note` is customer-side (a sales return; reverses **output** tax) so it adjusts our
outward supplies → `cdnr` / `cdnur`. ZeroBook's `debit_note` is supplier-side (a purchase
return; reverses **input** tax) — an *inward* adjustment that belongs in the **supplier's**
GSTR-1, not ours. It is still fully accounted for: it reduces our ITC, which
`GstService::summary()` nets automatically, so it lands in GSTR-3B's `itc_elg`. It is also
listed in `doc_issue` (a document we issued).

**Phase 8B workflow vouchers can't reach either return.** Sales/Purchase Orders,
Delivery/Receipt Notes and Rejections carry no tax and no ledger entries; the exporter only
ever reads `['sales','purchase','credit_note','debit_note']`. The proofs assert it. The
computed **Profit & Loss A/c** ledger is skipped defensively too.

---

## Numeric precision

All wire numbers route through `App\Services\Gst\GstnFormat`, never scattered `round()`s:

* `formatMoney()` → **2dp** · `formatRate()` → **2dp** · `formatQty()` → **4dp` ·
  `paiseToMoney()` bridges the integer-paise books to the rupee wire.
* `itemNum(rate)` → **`rate × 100 + 1`** (an 18% line is `1801`) — the tool's own convention.
* POS is a 2-char string, taken from the recipient's **GSTIN prefix** when registered, else
  from the state name via the tool's own 38-code table (there is no code `28`).
* UQC is the **short** official code (`NOS`, `KGS`, …); an unmapped unit falls back to `OTH`.

Whole numbers serialise as `118000` (not `118000.00`) — which is exactly what GSTN's own
Node-based tool emits via `JSON.stringify`. Output is **compact**, unescaped slashes/unicode.

---

## The worked numeric proofs

Company `27AAAAA0000A1Z5` (Maharashtra, POS 27); item **Widget**, HSN **8471**, UQC **NOS**,
GST **18%**; period **042026** (⇒ B2CL threshold ₹1,00,000, HSN bifurcated).

```
SALE-1  registered  (MH) 100 × 1000 = 100,000  intra → C 9,000  S 9,000   val 118,000  → b2b
SALE-2  unregistered(MH) 100 × 1000 = 100,000  intra → C 9,000  S 9,000   val 118,000  → b2cs
SALE-3  unregistered(KA) 300 × 1000 = 300,000  inter → I 54,000           val 354,000  → b2cl
CRNT-1  credit note to the registered buyer, 10 × 1000 = 10,000 intra → C 900 S 900    → cdnr
PURC-1  supplier (MH)  50 × 1000 =  50,000     intra → C 4,500  S 4,500                (ITC)
PURC-2  supplier (KA)  20 × 1000 =  20,000     inter → I 3,600                         (ITC)
+ a Sales Order, a Purchase Order and a Delivery Note in the same period.

GSTR-1
  b2b       ctin 27BBBBB1111B1Z5 · inum SALE-1 · idt 05-04-2026 · val 118000 · pos 27
            itms[0].num 1801 · itm_det {txval 100000, rt 18, camt 9000, samt 9000, csamt 0}   (no iamt)
  b2cl      pos 29 · inum SALE-3 · val 354000 · itm_det {txval 300000, rt 18, iamt 54000}     (no camt/samt)
  b2cs      {sply_ty INTRA, typ OE, pos 27, rt 18, txval 100000, camt 9000, samt 9000}
  cdnr      ctin … · ntty C · nt_num CRNT-1 · val 11800 · itm_det {txval 10000, camt 900, samt 900}
            (no cname, no inum — CDN is delinked)
  hsn_b2b   qty 90 (100 − 10) · txval 90,000 · camt 8,100 · samt 8,100 · val 106,200
  hsn_b2c   qty 400 (100 + 300) · txval 400,000 · iamt 54,000 · camt 9,000 · samt 9,000 · val 472,000
            ← one HSN row totals BOTH kinds of tax; they are not mutually exclusive here
  doc_issue doc_num 1: SALE-1→SALE-3, totnum 3, cancel 0, net_issue 3
            doc_num 5: CRNT-1→CRNT-1, totnum 1, net_issue 1        (no doc_det for a Purchase)

GSTR-3B
  osup_det  txval 490,000 (= 100k + 100k + 300k − 10k)  iamt 54,000  camt 17,100  samt 17,100
  itc_avl   [IMPG, IMPS, ISRC, ISD, OTH] — OTH: iamt 3,600  camt 4,500  samt 4,500
  itc_net   iamt 3,600  camt 4,500  samt 4,500
  inter_sup.unreg_details  [{pos 29, txval 300,000, iamt 54,000}]
  net payable = 88,200 − 12,600 = 75,600
  *** no tax_pmt / tx_pmt key anywhere in the file ***

TIE-OUT   GSTR-1 total taxable (490,000) == GSTR-3B osup_det.txval
          GSTR-1 total tax     ( 88,200) == GSTR-3B output tax
```

Both proofs also assert: **schema conformance** (every key the schema marks *required* is
present with the right nested shape — optionality is read from the schema file, not
assumed), **rounding** (every money 2dp, rate 2dp, qty 4dp), **workflow-voucher exclusion**
(the Delivery Note's 5 units did not inflate the HSN qty), **preview == JSON**, the **ARN
log**, and the **missing-GSTIN refusal**. Each provisions a throwaway tenant and tears down.

**They refuse to run without the real schema** — remove `_docs/gstn-schemas/gstr1-schema.json`
and `prove-gstr1` exits with a message telling you to put it back, rather than proving
anything against a remembered shape.

---

## UI — the GST Returns screen

`/reports/gst-returns` (Gateway **1**, Go To: *gstr, gstr1, gstr3b, return, filing, arn*).

* A **period selector** defaulting to the last completed month.
* A **preview** of both returns — B2B/B2CL/B2CS/CDNR/CDNUR/HSN counts, total taxable, total
  tax; and 3.1(a), 4(C) ITC, net payable. The preview is computed **from the generated JSON
  itself**, so it cannot drift from the file that gets uploaded (a proof asserts this).
* Two buttons: **Generate GSTR-1 JSON** / **Generate GSTR-3B JSON** → streamed download,
  compact, named `gstr1-<gstin>-<period>.json`.
* With no company GSTIN the screen blocks with the actionable message and a link to F11.

## ARN log

`gst_return_filings` (tenant-scoped): `period` (MMYYYY), `return_type` (gstr1|gstr3b),
`filed_at`, `arn`, `notes`, unique on (period, return_type). **Not auto-populated** — there
is no portal integration this phase. After uploading, paste the Acknowledgement Reference
Number into the screen; the return is then badged **FILED** with its ARN and date. Re-filing
a period updates the row in place.

---

## How to actually file

1. **Generate** — open GST Returns, pick the period, eyeball the preview, click *Generate
   GSTR-1 JSON*. (Or `php artisan zerobook:gstr1-export --tenant=… --period=…`.)
2. **Import** — open the GST portal's *Returns Offline Tool* → **Open** → select the JSON →
   the tool validates and shows its own summary. Fix anything it flags, regenerate.
3. **Upload** — in the tool, *Generate File* → upload the resulting file on the portal
   (`Returns Dashboard → GSTR-1 → Prepare Offline → Upload`).
4. **Submit & file** on the portal (DSC/EVC). Repeat for **GSTR-3B**, then **offset the
   liability on the portal** — the JSON carries no payment section by design.
5. **Record the ARN** the portal returns, back in the GST Returns screen.

---

## Schema

Tenant migration `database/migrations/tenant/2026_07_14_000001_create_gst_return_filings.php`
— the ARN log, and nothing else: both exporters are **pure read-only projections** over the
existing `vouchers` / `voucher_entries` / `ledgers` / `stock_entries` / `company_features`
tables. Company GSTIN (`company_features.company_gstin`, 5B), party GSTIN (`ledgers.gstin`,
2), item HSN (`stock_items.hsn_sac`, 6A) all already existed. Existing tenants:
`php artisan tenants:migrate`. phpMyAdmin equivalent: `_docs/phase9a_gst_returns_schema.sql`.

## Out of scope (deliberately)

GSTN portal API / GSP e-filing · GSTR-2 / 2A / 2B reconciliation · GSTR-9 / 9C annual
returns · e-invoicing & e-way bills · Nepal VAT return (**Phase 9B**).

---

## Files delivered / modified

```
delivered:
app/Services/Gst/GstnFormat.php          precision, period, B2CL threshold, POS codes, UQC, itemNum, doc natures
app/Services/Gst/GstReturnService.php    gstr1() / gstr3b() / preview() + buildB2b/B2cl/B2cs/Cdnr/Cdnur/Hsn/Docs
app/Support/GstReturnExport.php          default period, compact encoding, filename
app/Models/GstReturnFiling.php
database/migrations/tenant/2026_07_14_000001_create_gst_return_filings.php
app/Console/Commands/Gstr1ExportCommand.php · Gstr3bExportCommand.php
app/Console/Commands/ProveGstr1Command.php · ProveGstr3bCommand.php
app/Console/Concerns/ProvesGstReturns.php   schema gate, invoice factory, rounding walker, conformance check
app/Livewire/GstReturns.php + resources/views/livewire/gst-returns.blade.php
resources/views/reports/gst-returns.blade.php
_docs/gstn-schemas/gstr1-schema.json · gstr3b-schema.json · README.md · reference/*
_docs/phase9a_gst_returns_schema.sql · _docs/PHASE9A_README.md

modified (surgical):
app/Http/Controllers/ReportsController.php + routes/tenant.php   /reports/gst-returns
app/Support/Shell.php                                            Go To + Gateway ('1') entries
```

No stubs, no TODOs. **16/16 proves green.** Nothing in either return was written from
memory — every field name traces to a line of the Government's own published code.
