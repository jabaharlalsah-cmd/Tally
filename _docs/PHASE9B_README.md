# ZeroBook — Phase 9B: Nepal VAT Return (अनुसूची-१०)

**Goal:** end the "close the books, then re-key everything into the government's return"
workflow for Nepali VAT filers — the same thing 9A did for Indian GST.

```bash
php artisan zerobook:prove-nepal-vat-return                                   # worked numeric proof
php artisan zerobook:vat-return-export --tenant=acme --period=2082-04         # Shrawan 2082
```

---

## Step 0 — audit, and the artifact

**Audit — all sixteen prior proves pass, before and after 9B. After 9B: 17/17 green.**
(7 self-provisioning tenant proves, `prove-tally-import --tenant=`, and the 9 pre-tenancy
proves against a provisioned tenant DB via `DB_DATABASE=tenant<slug>`.)

**The artifact.** `_docs/ird-schemas/` was empty. But — unlike 9A, where the GSTN schema
was genuinely unpublished and had to be dug out of a Government installer — **Nepal's VAT
return form is a free, public legal document.** So rather than stop, the artifact was
fetched, verified, and committed:

* **`_docs/ird-schemas/vat-rules-2053-schedule10.pdf`** — मूल्य अभिवृद्धि कर नियमावली,
  २०५३ (VAT Rules, 2053), from the Nepal government CDN / Nepal Law Commission. 100 pages;
  **Schedule 10 is pages 70–73**.
* Pages 70, 71, 72 and 73 were **rendered and read directly**. Every box number, every
  Devanagari label, and — critically — **which of the three value columns is enterable
  (white) versus blocked (shaded)** was taken from the rendered form, not from any
  secondary description.
* The result is catalogued in **`_docs/ird-schemas/nepal-vat-return-fields.json`**, which
  is the build's authoritative source and what the proof asserts against. Every entry cites
  its page.

### Corrections the artifact forced on this prompt

| The 9B prompt said | The real Schedule 10 says |
|---|---|
| "historically the **Form 07** / VAT Return" | The form is **अनुसूची-१० (Schedule 10)** of the VAT Rules 2053, under **नियम २६(१)**. No basis was found anywhere for the name "Form 07". |
| the portal "accepts … an uploaded return file … for larger filers" | **The VAT return is web-form entry ONLY.** IRD publishes no upload schema/template for the return, and no XML/CSV/JSON return-file path exists. Verified from IRD's own *VAT return help file* and re-checked by two independent adversarial searches that both failed to refute it. (The sales/purchase **register** is a separate Excel upload — see "Not built".) |
| "English field names are standard … Devanagari for the printed version" | **Every field label on the form is Devanagari.** The only English token anywhere is `USER ID` in the office block. So the export carries the Devanagari label as the primary key, with an English gloss beside it. |
| "60/40 taxable/exempt split rules" | No such split appears on Schedule 10. The form has exempt rows (1.3, 2.3, 2.4) but no apportionment. |
| currency conventions unspecified | The form states the rule itself, above the grid (p.71): amounts are **whole rupees**, and *"रु.१ भन्दा घटि भएमा पैसालाई रु.१ मा मिलान गरी"* — a non-zero amount below Re. 1 is carried in as **Re. 1**. |

**Because the portal takes no file, deliverable E's web-form branch applies:** the exporter
produces a **transcription document**, not an upload file.

### `VatService::summary()` — confirmed

`summary(from,to)` returns integer paise: `taxable_sales`, `taxable_purchase`, `output`,
`input`, `net_payable = output − input`. Since a Credit Note debits Output VAT and a Debit
Note credits Input VAT, the netting the return needs already happens there. **9B is a thin
field-name mapping over it** — no new tax math — exactly as 9A was for GSTR-3B.

---

## Field-by-field mapping (ZeroBook → Schedule 10)

Three value columns (p.71): **कारोवार मूल्य** (transaction value) · **खरिदमा तिरेको कर
क्रेडिट** (input VAT) · **विक्रीमा संकलन गरेको कर डेविट** (output VAT).

| Box | Label (p.) | Enterable cells *(from the form's shading)* | ZeroBook source |
|---|---|---|---|
| 1.1 | कर लाग्ने विक्री (71) | value, **debit** | `taxable_sales` · `output` |
| 1.2 | निर्यात (71) | value | 0 — exports not classified |
| 1.3 | छुट विक्री (71) | value | 0 — exempt sales not classified |
| 2.1 | कर लाग्ने खरिद (71) | value, **credit** | `taxable_purchase` · `input` |
| 2.2 | कर लाग्ने पैठारी (71) | value, credit | 0 — imports not distinguished |
| 2.3 / 2.4 | छुट खरिद / छुट पैठारी (71) | value | 0 |
| 3.1 | अन्य थपघट (71) | credit, debit | 0 — adjustments not modelled |
| 4 | जम्मा (71) | credit, debit | column totals |
| 5 | डेविट—क्रेडिट (71) | amount (+/−) | `box4.debit − box4.credit` |
| 6 | गत महिनाको मिलान गर्न बाँकी क्रेडिट (72) | amount | **explicit input** (default 0) |
| 7 | कुल तिर्नु पर्ने कर रु. (५—६) (72) | amount (+/−) | `box5 − box6` |
| 8 / 9 / 10 | कर फिर्ता माग / आधार / जम्मा भुक्तानी + भौचर नं. (72) | — | 0 / unticked / blank — taxpayer actions on the portal |
| 11 | कर अवधिमा प्रयोग गरिएका कागजातहरुको विवरण (72) | six counts | voucher counts per type |

Note 1.1 has **no credit cell** and 2.1 has **no debit cell** — that is the form's own
shading, and the proof asserts the emitted document matches it exactly.

**Why a Debit Note reduces input VAT.** `credit_note` = sales return → debits Output VAT →
reduces box 1.1's *debit* column. `debit_note` = purchase return → credits Input VAT →
reduces box 2.1's *credit* column. A Debit Note therefore never touches output VAT. Proven.

**Phase 8B workflow vouchers can't reach the return.** Only
`['sales','purchase','credit_note','debit_note']` are ever read, and box 11 counts only
those types — a Sales Order or Delivery Note has no tax and no ledger entry.

## Not tracked by ZeroBook (present, zero-valued, documented)

Exports (1.2) · exempt sales (1.3) · taxable import (2.2) · exempt purchase/import
(2.3/2.4) · other adjustments (3.1) · refund claim & basis & payment voucher (8/9/10) ·
credit/debit *advice* counts (11.4/11.5). Also: **no per-tenant business name, address or
phone exists in the tenant database** (`company_features` holds only `company_pan`), so
नाम / ठेगाना / टेलिफोन नं. / मोबाइल नं. are emitted as `null` and flagged. Adding them is a
small future migration.

## Not built (deliberately)

The **sales register (अनुसूची-९ बिक्री खाता)** and **purchase register (अनुसूची-८ खरिद
खाता)** Excel annexures. IRD does mandate uploading these for taxpayers on its list, and
sample `.xlsx` templates exist — but their **column schema could not be verified** from a
primary source. Building them from a guess would violate this phase's whole discipline. To
add them, drop the IRD register template into `_docs/ird-schemas/` and the same gate
applies. Also out of scope: IRD portal integration (no public submission API), annual VAT
return, VAT audit/refund applications, Nepal TDS.

---

## BS calendar

Nepal's fiscal year runs **Shrawan → Ashadh**, and the form's month grid is printed in that
fiscal order. But the BS *calendar* numbers Baishakh = 1, so **Shrawan = BS month 4** — two
orderings that must never be confused. `App\Support\NepalDate` keeps them apart:

* A period is `BS YYYY-MM` (calendar), e.g. `2082-04` = Shrawan 2082. The form prescribes
  **no serialized period string** and the portal's live field is behind taxpayer login, so
  ZeroBook never invents a portal format — it shows the BS year, the month name, and the
  fiscal year.
* Conversion delegates to **`ernilambar/nepali-date`** (maintained, framework-agnostic) —
  not rolled by hand. `NepalDate` adds the bounds check the package lacks (it returns an
  **empty array**, not an exception, outside BS 2000–2089).
* Verified: Shrawan 2082 → **AD 2025-07-17 … 2025-08-16** (31 days); Ashadh 2083 → FY
  **2082/83**, fiscal index 12, 32 days.

---

## The worked proof — `zerobook:prove-nepal-vat-return`

Company PAN `301234567`, VAT regime, 13%; period **2082-04** (Shrawan 2082).

```
Sale        100,000  → output VAT 13,000
Purchase     50,000  → input  VAT  6,500
Credit Note  10,000  → output VAT reversal 1,300
+ a Sales Order and a Delivery Note in the same period.

  1.1 कर लाग्ने विक्री    value 90,000 (100,000 − 10,000)   debit 11,700 (13,000 − 1,300)
  2.1 कर लाग्ने खरिद      value 50,000                      credit 6,500
  4   जम्मा                                credit 6,500      debit 11,700
  5   डेविट—क्रेडिट        + 5,200
  6   गत महिनाको … क्रेडिट   0
  7   कुल तिर्नु पर्ने कर    + 5,200   ← net VAT payable
  11  खरिद बिजक 1 · क्रेडिट नोट 1 · डेविट नोट 0 · विक्री बिजक 1 · एडभाइस 0/0

then a Debit Note (purchase return) of 4,000 → VAT 520:
  2.1 value 50,000 → 46,000   credit 6,500 → 5,980
  1.1 debit UNTOUCHED at 11,700   ← a Debit Note reduces INPUT VAT, never output
  5   rises to 5,720
```

The proof also asserts: the **schema gate** (delete the fields JSON and it refuses to run —
verified), the **regime gate** (no regime → *"set your tax regime in F11"*; GST tenant →
*"This tenant is under GST regime, not Nepal VAT"*; VAT but no PAN → refuses), carry-forward
flowing into box 7 (and going negative with the form's `(—)` sign), **whole-rupee rounding**
including the Re.1 floor, **field conformance** (every emitted box exists on the form with
the form's exact Devanagari label *and* the form's exact white/shaded cell layout, both
directions), **workflow-voucher exclusion**, **preview == document**, and the submission-ref
log. It provisions a throwaway tenant and tears down.

---

## UI — the VAT Return screen

`/reports/vat-return` (Gateway **2**, Go To: *vat return, ird, nepal vat, anusuchi, schedule 10*).

BS period selector · a box-6 carry-forward input · four headline cards (taxable sales,
output VAT, input VAT, net payable/credit) · **Schedule 10 rendered box by box** with the
form's Devanagari labels, English glosses, and blocked cells greyed exactly as the form
shades them · a **Generate VAT Return** download · a submission-reference recorder.

The preview and the table are read off the **same generated document** the download serves,
so they cannot drift (a proof asserts it). The download is pretty-printed with **unescaped
Devanagari** — deliberately *not* the compact GSTN convention, because a human reads this one.

## Submission-reference log

`vat_return_filings` (tenant-scoped): `period` (BS `YYYY-MM`, unique), `submission_ref`,
`filed_at`, `notes`. Not auto-populated — there is no portal API. Re-filing a period updates
the row in place.

## How to file

1. **Generate** — open VAT Return, pick the BS period, enter last month's carry-forward if
   any, review the boxes. (Or `php artisan zerobook:vat-return-export --tenant=… --period=…`.)
2. **Sign in** at `taxpayerportal.ird.gov.np` → VAT → **E-VAT Return Entry** → register to
   get a submission number.
3. **Transcribe** the boxes into the VAT Return Data Entry form → Save → tick the validation
   box → Submit.
4. **Record** the submission reference back in the VAT Return screen.

ZeroBook does not submit returns on your behalf.

---

## Schema

Tenant migration `database/migrations/tenant/2026_07_15_000001_create_vat_return_filings.php`
— the filing log, and nothing else: the exporter is a **pure read-only projection** over
existing tables. Existing tenants: `php artisan tenants:migrate`. phpMyAdmin equivalent:
`_docs/phase9b_vat_return_schema.sql`.

## Files delivered / modified

```
delivered:
_docs/ird-schemas/vat-rules-2053-schedule10.pdf      the Government's own form (pp.70-73)
_docs/ird-schemas/nepal-vat-return-fields.json       every box, label, cell layout, cited to a page
app/Support/NepalDate.php                            BS<->AD, bounds-checked; fiscal vs calendar month order
app/Support/VatReturnExport.php                      pretty, unescaped-Devanagari serialisation
app/Services/Vat/IrdFormat.php                       whole-rupee rounding (the form's own rule)
app/Services/Vat/NepalVatReturnService.php           return() / preview() + buildSales/Purchases/Adjustments/NetVat/DocumentCounts
app/Models/VatReturnFiling.php
database/migrations/tenant/2026_07_15_000001_create_vat_return_filings.php
app/Console/Commands/VatReturnExportCommand.php · ProveNepalVatReturnCommand.php
app/Livewire/VatReturn.php + resources/views/livewire/vat-return.blade.php
resources/views/reports/vat-return.blade.php
_docs/phase9b_vat_return_schema.sql · _docs/PHASE9B_README.md

modified (surgical):
app/Http/Controllers/ReportsController.php + routes/tenant.php   /reports/vat-return
app/Support/Shell.php                                            Go To + Gateway ('2') entries
composer.json / composer.lock                                    + ernilambar/nepali-date (AD<->BS)
```

No stubs, no TODOs. **17/17 proves green.** Not one field name was written from memory —
every box traces to a page of the Government's own form, committed alongside the code.
