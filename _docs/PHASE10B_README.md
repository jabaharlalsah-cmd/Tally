# ZeroBook — Phase 10B: Form 26Q return-file exporter

**Status: done and proven.** `php artisan zerobook:prove-26q` → **79 assertions, 0 failures.**
Full regression: **19/19** prove-commands green (18 prior + the new one), plus `prove-multi-tenant`
(47/0). 10B is read-only over the Phase 10A deduction engine.

Phase 10B produces the quarterly **Form 26Q** return file — the caret-delimited `.txt` a business or
its CA uploads to the Income Tax e-filing portal, reporting all non-salary TDS deductions for a
quarter. Built with the same discipline that landed 9A/9B/10A: **read the real government-published
spec, build against it, validate against the real government-published validator.**

---

## Step 0 — audit result

All prior prove-commands were re-run before a line of 10B was written. **18/18 green** (after starting
MySQL, which was down). Nothing needed fixing. 10B adds only new tables and a read-only exporter.

---

## The artifacts are real, and their provenance is documented

`_docs/tds-schemas/` holds Protean's own published files (see `DOWNLOAD-LOG.md` for the source URLs
and download record):

- **`form-26q-file-format-current.xlsx`** — "Form Number 140 (26Q) Version 1.0, for Tax Year 2026-27
  and onwards". The current-era spec this phase builds against.
- **`form-26q-file-format-historical.xls`** — 26Q v7.8, for FY 2010-11 … 2025-26.
- **`fvu-current/TDS_STANDALONE_FVU_1.1/`** — FVU 1.1 (the current validator) + `fvu-historical/…FVU_9.5`.
- The RPU 1.1 / 6.0 bundles, and the 27Q / 27EQ specs (for later phases).

The current spec's field tables and annexures were **machine-extracted** into two spec-row-cited JSON
files under `resources/tds-specs/` (`form-26q-current.json`, `form-26q-annexures.json`) — the same
"page-cited artifact" discipline 9A/9B used. Both the exporter and the structural validator read their
rules from those files, so nothing about the format is transcribed by hand twice.

---

## Record-type inventory (from the spec)

The current 26Q format is a **`^`-delimited, variable-width ASCII `.txt`** (spec general note 9), each
record on its own line ending with CRLF (note 2). **Four record types — there is no File Trailer:**

| Record | Fields | Delimiters | Purpose |
|---|---|---|---|
| **FH** — File Header | **18** | 17 | file metadata: File Type `NS1`, TAN, RPU name |
| **BH** — Batch Header | **72** | 71 | Form `140`, quarter, deductor + responsible-person identity, batch total |
| **CD** — Challan Detail | **30** | 29 | one per remittance: BSR, challan number, deposit date, amount |
| **DD** — Deductee Detail | **45** | 44 | one per deduction: PAN, name, section code, amount, rate |

Sequence: `FH` · `BH` · then for each challan a `CD` followed by its `DD`s. The field COUNT per record
is exactly what the FVU's "Record Length" check enforces.

---

## The field-position map (the auditable trail)

Every field the exporter writes traces to a **Sr. No. and a spreadsheet row** in
`form-26q-file-format-current.xlsx` (sheet `File Format_R`). The populated fields:

### FH — File Header
| Sr | Field | Type/Size · M/O | Spec row |
|---|---|---|---|
| 2 | Record Type = `FH` | CHAR/2 M | 22 |
| 3 | File Type = `NS1` | CHAR/4 M | 23 |
| 4 | Upload Type = `R` | CHAR/2 M | 24 |
| 5 | File Creation Date (ddmmyyyy) | DATE/8 M | 25 |
| 7 | Uploader Type = `D` | CHAR/1 M | 27 |
| 8 | TAN of Deductor | CHAR/10 M | 28 |
| 9 | Total No. of Batches = 1 | INT/9 M | 29 |
| 10 | Name of RPU = `ZeroBook` | CHAR/75 M | 30 |

### BH — Batch Header (key fields)
| Sr | Field | Type/Size · M/O | Spec row |
|---|---|---|---|
| 2 | Record Type = `BH` | CHAR/2 M | 44 |
| 5 | Form Number = `140` | CHAR/4 M | 47 |
| 13 | TAN of Deductor | CHAR/10 M | 55 |
| 15 | PAN of Deductor | CHAR/10 M | 57 |
| 16 | Assessment Yr (e.g. `202728`) | INT/6 M | 58 |
| 17 | Tax Year (e.g. `202627`) | INT/6 M | 59 |
| 18 | Period = `Q1`…`Q4` | CHAR/2 M | 60 |
| 19 | Name of Deductor | CHAR/75 M | 61 |
| 20 | Deductor Country/Region = `INDIA` | CHAR/25 M | 62 |
| 21 | Deductor Address1 | CHAR/25 M | 63 |
| 26 | Deductor State (Annexure 1) | INT/2 M | 68 |
| 27 | Deductor Pincode | INT/6 M | 69 |
| 28 | Deductor Email | CHAR/75 M | 70 |
| 29 | Deductor Contact Country Code = `91` | INT/5 M | 71 |
| 30 | Deductor Contact Number | CHAR/10 M | 72 |
| 32 | Deductor Type (Annexure 4) | CHAR/1 M | 74 |
| 33 | Responsible Person Name | CHAR/75 M | 75 |
| 34 | Designation | CHAR/20 M | 76 |
| 35 | Responsible Address1 | CHAR/25 M | 77 |
| 40 | Responsible State | INT/2 M | 82 |
| 41 | Responsible PIN | INT/6 M | 83 |
| 42 | Responsible Email | CHAR/75 M | 84 |
| 43 | Responsible Country = `INDIA` | CHAR/25 M | 85 |
| 44 | Responsible Contact Country Code = `91` | INT/5 M | 86 |
| 45 | Responsible Contact Number | CHAR/10 M | 87 |
| 47 | Batch Total Deposit (= Σ CD deposit) | INT/15 M | 89 |
| 52 | Regular statement filed earlier = `N` | CHAR/1 M | 94 |
| 59 | PAN of Responsible Person | CHAR/10 M | 101 |

### CD — Challan Detail
| Sr | Field | Type/Size · M/O | Spec row |
|---|---|---|---|
| 2 | Record Type = `CD` | CHAR/2 M | 120 |
| 4 | Challan-Detail Record Number (1..N) | INT/9 M | 122 |
| 5 | Count of Deductee Records | INT/9 M | 123 |
| 6 | NIL Challan Indicator = `N` | CHAR/1 M | 124 |
| 8 | Total tax Deducted (income tax) | INT/15 M | 126 |
| 9–11 | Interest / Fee / Penalty = `0.00` | INT/15 M | 127-129 |
| 12 | Total Deposit (B+C+D+E) | INT/15 M | 130 |
| 13 | Mode = `C` (bank challan) | CHAR/1 O | 131 |
| 15 | BSR Code (7 digits) | INT/7 M | 133 |
| 17 | Bank Challan No (5 digits) | INT/5 O | 135 |
| 19 | Date of Bank Challan (ddmmyyyy) | DATE/8 M | 137 |
| 21 | Total Tax Deposited (Σ DD col L) | DEC/15 M | 139 |
| 22 | Total Tax Deducted (Σ DD col J) | DEC/15 M | 140 |
| 23 | Minor Head = `200` (Annexure 7) | INT/3 O | 141 |

### DD — Deductee Detail
| Sr | Field | Type/Size · M/O | Spec row |
|---|---|---|---|
| 2 | Record Type = `DD` | CHAR/2 M | 154 |
| 4 | Deductee Detail Record No (1..N) | INT/9 M | 156 |
| 5 | Challan reference Number (= CD Sr 4) | INT/9 M | 157 |
| 6 | Mode = `O` | CHAR/1 M | 158 |
| 8 | Deductee's PAN (or `PANNOTAVBL`) | CHAR/10 M | 160 |
| 9 | Name of the Deductee | CHAR/75 M | 161 |
| 15 | Section Code (Annexure 2) | CHAR/4 M | 167 |
| 18 | Whether deducted-tax deposited = `Y` | CHAR/1 M | 170 |
| 19 | Date of payment / credited | DATE/8 M | 171 |
| 20 | Amount Paid / Credited (base) | DEC/15 M | 172 |
| 24 | Total Tax Deducted | DEC/15 M | 176 |
| 25 | Total Tax Deposited | DEC/15 M | 177 |
| 27 | Date of deduction | DATE/8 M | 179 |
| 28 | Rate at which Tax Deducted (4 dp) | DEC/7 M | 180 |
| 32 | Non-deduction / higher-rate flag (Annexure 6) | CHAR/1 O | 184 |

Amounts carry decimal precision 2 (`65000.00`) per general note 4; the rate carries precision 4
(`10.0000`) per note 5; dates are `ddmmyyyy` per note 8.

---

## The Section-393 code translation

ZeroBook's own section codes (Phase 10A: `393-194J`, `393-194C`, …) are a **different system** from
the 26Q return codes (Annexure 2's four-digit `1027`). The 26Q code is a property of the *return
format*, not of the deduction, so it lives in the exporter's format layer (`Form26qSpec`), keyed by
the section's base code:

| ZeroBook base | 26Q code | Annexure 2 |
|---|---|---|
| `194J` professional fees | `1027` | 393(1) Sl.No 6(iii).D(b) |
| `194C` contractor — individual/HUF | `1023` | 393(1) Sl.No 6(i).D(a) |
| `194C` contractor — other | `1024` | 393(1) Sl.No 6(i).D(b) |
| `194H` commission/brokerage | `1006` | 393(1) Sl.No 1(ii) |
| `194I-A` rent — machinery | `1008` | 393(1) Sl.No 2(ii).D(a) |
| `194I-B` rent — land/building | `1009` | 393(1) Sl.No 2(ii).D(b) |
| `194Q` purchase of goods | `1031` | 393(1) Sl.No 8(ii) |
| `194A` interest (non-securities) | `1021` | 393(1) Sl.No 5(ii).D(b) |

A user-created section with no mapping fails pre-flight with a clear message — never a guess.

---

## The challan-capture flow

A 26Q file is useless without real bank challan identifiers. In Phase 10A, remitting TDS is an
ordinary Payment (`Dr TDS Payable / Cr Bank`). Phase 10B captures the bank challan on that voucher:

- When a Payment **debits the TDS Payable ledger**, a **TDS Challan** panel appears (client getter
  `showChallanPanel`), collecting the **7-digit BSR code**, the **5-digit challan serial** from the
  bank stamp, and the **deposit date**.
- On accept, `TdsService::persistChallan()` writes a `tds_challans` row inside the voucher's own
  transaction. The **deposited amount is derived server-side from the Dr TDS Payable line** — never
  the client's word. `TdsService::verifyChallanPayload()` refuses a challan on anything but a
  remittance, and a malformed BSR/challan number.
- Continuous entry **preserves the BSR** (the deductor's bank branch is stable) but resets the
  challan number and date for the next remittance.
- The exporter maps whole deductions to challans **FIFO** (a deductee row belongs to exactly one
  challan; a challan's deductee TDS must sum to its amount) and emits one CD per challan.

Browser-verified end-to-end on a tenant subdomain: a `Dr TDS Payable / Cr Bank` Payment produced a
`tds_challans` row (BSR `0510308`, challan `07788`, server-derived ₹7,500, minor head `200`), 0
console errors.

---

## The FVU-validation result — and why the exporter's job ends at the `.txt`

The official Protean **File Validation Utility (FVU)** is the government's own validator. Phase 10B
invokes it (I reverse-engineered its undocumented 6-argument CLI from the JAR bytecode:
`java -jar TDS_STANDALONE_FVU_1.1.jar <input.txt> <errorReportFile> <challan.csi> 0 0 0`) — but a
**clean automated pass on synthetic third-party data is impossible by deliberate design**, for three
independent reasons:

1. **An online anti-tamper version self-check.** The FVU calls
   `onlineservices.tin.egov.proteantech.in/TIN/checkfvuversion.do` and rejects any non-official
   invocation before field validation, writing `Incorrect FVU Version of JAR` / `T-FV-1021 "FVU
   Version is either Incorrect or NULL"`. (The endpoint itself confirms `1.1` is a current version —
   the barrier is the JAR's self-check, not connectivity.)
2. **A mandatory external `.csi` (Challan Status Inquiry) file** — spec general note 12. It is real
   OLTAS challan data downloaded from TIN, cross-verifying that the challans in the return actually
   exist in the government banking system. It **cannot be synthesised for a test TAN** whose deposits
   never happened.
3. **A barcode + hash** stamped into the `.fvu` output to detect tampering.

These exist so that **only the official RPU/FVU can mint a valid `.fvu`**. A third-party accounting
tool is *expected* to produce the `.txt`; the filer runs the FVU themselves. So ZeroBook does the
correct thing — it emits a spec-conformant `.txt` and does **not** attempt to defeat a government
anti-tampering control.

The proof invokes the real FVU, captures its report (`Incorrect FVU Version of JAR`), classifies the
barrier (`version-gate`), and asserts it is a **known anti-tamper gate, not a format defect** — never
a false green.

### The automated validation gate: spec-driven structural conformance

Per the prompt's explicit fallback, the pass/fail gate is `Form26qStructuralValidator` — but its rules
are **not the exporter's rules re-stated**. They are loaded from the government spec's own field tables
(the extracted `form-26q-current.json`, with each field's count/size/type/mandatory flag and spec row,
plus the annexure code sets). It parses the generated file back and asserts:

- the record sequence (FH · BH · CD-then-its-DDs · no trailer);
- each record's **field count** (the FVU's Record-Length check);
- the constant fields (FH/BH/CD/DD types, `NS1`, `140`, …);
- each field within its spec max width and correct data type (date / amount / rate);
- identifier formats (TAN, PAN, BSR, section ∈ Annexure 2, state ∈ Annexure 1);
- cross-record reconciliation (**BH batch total = Σ CD deposit = Σ DD tax**; CD count = # DDs);
- line numbers 1..N.

If the exporter ever drifts from the spec, this catches it.

---

## The worked example (the FVU-target file)

Five professional-fee (`393-194J`) payments to three vendors — two with a valid PAN, one without —
totalling **₹5,50,000 base and ₹65,000 TDS**, all in Q1, discharged by one remittance of ₹65,000
(BSR `0510308`, challan `02345`, deposited 28-Jun-2026). The exported file:

```
1^FH^NS1^R^11072026^1^D^MUMZ12345A^1^ZeroBook^^^^^^^^
2^BH^1^1^140^^^^^^^^MUMZ12345A^^AAACZ1234F^202728^202627^Q1^ZeroBook Test Deductor Pvt Ltd^INDIA^1 Evergreen Road^^^^^19^400001^tds@zerobook.test^91^2266778899^^K^Alex Manager^Director^1 Evergreen Road^^^^^19^400001^alex@zerobook.test^INDIA^91^2266778899^^65000.00^^^^^N^^^^^^^AAAPA1234Q^^^^^^^^^^^^^
3^CD^1^1^5^N^^65000.00^0.00^0.00^0.00^65000.00^C^^0510308^^02345^^28062026^^65000.00^65000.00^200^^^^^^^
4^DD^1^1^1^O^^ABCDE1234F^M/s Legal Advisors^^^^^^1027^^^Y^10042026^120000.00^^^^12000.00^12000.00^^10042026^10.0000^^^^^^^^^^^^^^^^^
5^DD^1^2^1^O^^BCDEF2345G^M/s Audit Partners LLP^^^^^^1027^^^Y^15042026^100000.00^^^^10000.00^10000.00^^15042026^10.0000^^^^^^^^^^^^^^^^^
6^DD^1^3^1^O^^ABCDE1234F^M/s Legal Advisors^^^^^^1027^^^Y^10052026^80000.00^^^^8000.00^8000.00^^10052026^10.0000^^^^^^^^^^^^^^^^^
7^DD^1^4^1^O^^BCDEF2345G^M/s Audit Partners LLP^^^^^^1027^^^Y^20052026^150000.00^^^^15000.00^15000.00^^20052026^10.0000^^^^^^^^^^^^^^^^^
8^DD^1^5^1^O^^PANNOTAVBL^M/s Counsel (no PAN)^^^^^^1027^^^Y^01062026^100000.00^^^^20000.00^20000.00^^01062026^20.0000^^^^C^^^^^^^^^^^^^
```

Line 8 is the no-PAN deductee: PAN = **`PANNOTAVBL`**, rate = **`20.0000`** (Section 206AA, already
enforced at deduction time in 10A), and field 32 = flag **`C`** ("higher rate under section 397(2) on
account of non-furnishing of PAN", Annexure 6). All other rows are regular deductions with no flag.
Reconciliation: Σ DD tax `65000.00` = CD deposit `65000.00` = BH batch total `65000.00`.

---

## No-PAN handling (Section 206AA)

- **PAN field (DD 8)** → `PANNOTAVBL` (the spec's "PAN not available" placeholder).
- **Rate (DD 28)** → `20.0000` (10A already deducted at 20% under 206AA for the no-PAN vendor).
- **Flag (DD 32)** → `C` (Annexure 6 — higher rate, non-furnishing of PAN).

Lower-deduction (Section 197 certificate) and NIL-deduction (197A) flags are **out of scope** — every
other row emits as a regular deduction. `PANAPPLIED` / `PANINVALID` are accepted as valid placeholders
by the validator but only `PANNOTAVBL` is produced (10A doesn't track the applied/invalid distinction).

---

## The deductor-identity gate

A 26Q Batch Header is mandatory-heavy. The exporter **refuses to run** (naming each missing field)
unless the deductor's filing identity is complete, rather than emitting placeholders the FVU would
reject. Added to `company_features` (settable on **F11 → Company Features → 26Q Deductor Details**):

`company_tan`, `deductor_name`, `deductor_address1/2`, `deductor_state_code`, `deductor_pincode`,
`deductor_email`, `deductor_phone`, `deductor_type` (Annexure 4), and the responsible-person block
`resp_name`, `resp_designation`, `resp_pan`, `resp_address1`, `resp_state_code`, `resp_pincode`,
`resp_email`, `resp_phone`. The company PAN (BH 15) reuses the existing `company_pan`.

Country (BH 20/43) defaults to `INDIA`, contact country codes (BH 29/44) to `91`, "regular earlier"
(BH 52) to `N`, minor head to `200` — none needed a stored field.

---

## The historical-spec switch

`SpecResolver` picks the era from `--fy-start`:

- **fy-start ≥ 2026 → current** (`form-26q-file-format-current.xlsx`, Form 140, FVU 1.1).
- **fy-start ≤ 2025 → historical** (`form-26q-file-format-historical.xls`, 26Q v7.8, FVU 9.5).

The switch is real and tested (the proof asserts the era + spec file + FVU version for 2025 vs 2026).
ZeroBook itself only ever holds Section-393 data (Phase 10A launched in FY 2026-27), so there is no
pre-2026 TDS to export — a pre-2026 request is **refused with the spec citation** rather than emitting
the wrong format. When a book eventually carries pre-2026 vouchers, the historical builder is the only
piece to add; everything routing to it already exists.

---

## How a user files

1. **ZeroBook** → *Reports → TDS Returns (26Q)* (Gateway **4**, or `zerobook:26q-export`). Pick the
   fiscal year; each quarter shows *draft / ready / filed*. Download the quarter's `.txt`.
2. **The CA** opens the official **Protean RPU/FVU**, imports the `.txt` and the **`.csi`** from
   TIN (Challan Status Inquiry, which verifies the challans against OLTAS), and validates — producing
   the `.fvu` file (with the government's barcode/hash).
3. **Upload** the `.fvu` to the Income Tax e-filing portal.
4. Back in **ZeroBook**, record the acknowledgement **token** on the 26Q Returns screen, so the
   quarter shows *filed*.

---

## Files

**Schema** — one tenant migration + a phpMyAdmin SQL script.

| file | |
|---|---|
| `database/migrations/tenant/2026_07_17_000001_add_tds_return_filing.php` | `tds_challans`, `tds_return_filings`, the `company_features` deductor profile |
| `_docs/phase10b_schema.sql` | the phpMyAdmin equivalent; **verified** by rolling a tenant back to pre-10B, applying the script, and diffing the columns against the migration's (identical) |

**Spec + exporter** (`app/Services/Tds/`) — `Form26qSpec` (loads the extracted JSON + section-code map
+ identifier validators), `SpecResolver` (era switch), `Form26qExporter` (builders + `pad` + pre-flight),
`Form26qStructuralValidator` (spec-driven), `Form26qException`, `FvuValidator` (invokes + classifies the
real FVU). Extracted spec: `resources/tds-specs/form-26q-current.json`, `form-26q-annexures.json`.

**Challan capture** — `TdsService` (`persistChallan` / `reverseChallanFor` / `verifyChallanPayload`),
`VoucherScreen` (rules + normalization + persist), `resources/js/vouchers/screen.js`,
`resources/views/partials/tds-challan.blade.php`.

**Command + screens** — `Tds26qExportCommand` (`zerobook:26q-export`), `Tds26qReturns` Livewire +
`livewire/tds-26q-returns.blade.php` + `reports/tds-returns.blade.php`, the F11 deductor-profile block,
`ReportsController::tdsReturns`, the route, and `Shell` nav/gateway (`4`).

**Proof** — `app/Console/Commands/ProveTds26qCommand.php` (`zerobook:prove-26q`, 79 assertions).

---

## Deploying to an existing tenant

```bash
php artisan tenants:migrate   # each tenant — tds_challans, tds_return_filings, deductor columns
npm run build
```

No central migration is needed (TDS was already plan-gated in Phase 10A).

---

## Scope — honestly deferred

- **Form 24Q** (salary TDS) — payroll is a whole subsystem. Not built.
- **Form 27Q** (non-resident) / **Form 27EQ** (TCS) — the format specs are in the folder for later;
  27EQ needs TCS itself, which doesn't exist yet. Deferred.
- **Correction statements** (revising a filed 26Q) — Protean's RPU correction flow. The Batch Header's
  correction fields are emitted empty (regular statement only). Deferred.
- **NIL challans / below-threshold deductee rows** — 10A's zero-TDS (below-threshold) deductions are
  **excluded** from the export; they belong to NIL-challan reporting with Annexure-6 non-deduction
  flags. The exporter emits only deductions that actually withheld tax and were remitted.
- **Lower/NIL-deduction certificates** (Section 197 / 197A) — not tracked; only the no-PAN higher-rate
  flag `C` is emitted. All other rows are regular deductions.
- **Direct portal upload** — no public third-party API; the user uploads the `.fvu` manually.
- **Form 16A certificates** — a separate compliance product; later.
- **Challan-to-quarter attribution** is simplified: a challan is attributed to a quarter if deposited
  from the quarter start through one month past its end (covering the routine "deposit in the following
  month"). Precise year-wide FIFO across quarter boundaries is a future refinement.

---

## Known limitations, stated plainly

- **A clean FVU pass on synthetic data is not achievable in this environment** — by the government's
  anti-tamper design (online version gate, mandatory external `.csi`, barcode/hash). The gate is
  spec-driven structural conformance; the real FVU is invoked and its barrier documented, never
  falsely reported as green. This is the correct real-world architecture: ZeroBook emits the `.txt`,
  the filer runs the FVU.
- **The 26Q section-code map covers the seeded 10A sections.** A user-added section needs its Annexure-2
  code added to `Form26qSpec`; until then, the exporter refuses that section with a clear message.
- **Deductor identity must be complete** or the exporter refuses — by design, to never emit a
  placeholder the FVU rejects.
