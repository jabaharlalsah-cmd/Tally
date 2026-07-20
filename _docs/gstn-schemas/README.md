# GSTN schemas — provenance

ZeroBook's Phase 9A returns exporter is built and proven against **these files**, never
against anyone's recollection of the GSTR schema. A returns JSON with one field of the
wrong name or shape is rejected by the GST portal.

## What's here

| File | What it is |
|---|---|
| `gstr1-schema.json` | The GSTR-1 document: `_meta`, `_skeleton`, `_fields` (123 entries, with required/precision), `_rules` |
| `gstr3b-schema.json` | The GSTR-3B document: same shape, plus `_absent` (sections that do **not** exist) |
| `reference/gstn-state-codes.json` | The 38 place-of-supply codes, copied verbatim from the tool |
| `reference/gstn-tax-rates.json` | The valid IGST/CGST/SGST/Cess rate lists |
| `reference/gstn-invoice-types.json` | `inv_typ` codes (R / DE / SEWP / SEWOP / CBW) |
| `reference/gstn-offline-tool-ReleaseNotes.txt` | The tool's own changelog (documents the HSN bifurcation + B2CL limit change) |
| `reference/section-csv/` | The tool's per-section CSV column templates |
| `gst_offline_tool.zip`, `GSTR3B_Excel_Utility.zip` | The Government-published artifacts these were derived from |

## How they were derived

Neither official artifact ships a JSON schema — both only *generate* JSON. So the
schemas here were read out of the Government's own code:

* **GSTR-1** — `gst_offline_tool.zip` is an **Inno Setup 6.1.0** installer wrapping a
  **Node.js + AngularJS** app. Unpacked, the authoritative structures are:
  * `utility/common.js → formDataFormat()` — the exact top-level template, including the
    `HSN_BIFURCATION_START_DATE = "052025"` switch and `version = "GST3.2.4"`.
  * `utility/returnStructure.js` — the per-section row builders.
  * `utility/jsonToExcel.js` — the read side, which names every key.
  * `utility/constants.js` — `B2CL_MIN_VAL = 100000`, `B2CL_MIN_VAL_STR_PRD = '082024'`.
* **GSTR-3B** — `GSTR3B_Excel_Utility_V5.8.xlsm` → `xl/vbaProject.bin`. The VBA
  concatenates the JSON literally, so the key names and nesting are read directly from it.

Every section was extracted and then **independently re-verified against a different
source file**. One real error was caught that way (see `_meta.caveats` in
`gstr1-schema.json`): `cdnur`'s `itm_det` carries **only** `{txval, rt, iamt, csamt}` —
it has no intra-state branch, so `camt`/`samt` are never emitted.

## Corrections the real artifacts forced

Several things "everyone knows" about the GSTR schema are wrong for the current version:

| Common belief | The tool actually emits |
|---|---|
| `docs` | **`doc_issue: { doc_det: [ { doc_num, docs: [...] } ] }`** |
| flat `hsn` | **`hsn: { hsn_b2b: [], hsn_b2c: [] }`** from `fp ≥ 052025`; `hsn: { data: [] }` before |
| GSTR-3B `tax_pmt` / `tx_pmt` | **no payment section at all** — payment/offset happens on the portal |
| period `YYYYMM` | **`MMYYYY`** (`fp` / `ret_period`), e.g. `"042026"` |
| B2CL threshold ₹2,50,000 | **₹2,50,000 before `082024`, ₹1,00,000 from `082024`** — and strictly `>` |
| `itms[].num` is a serial | **`num = rate × 100 + 1`** (an 18% line is `1801`) |

## Refreshing them

The GSTN schema is versioned. When GSTN ships a new offline tool:

1. Download the current **Returns Offline Tool** (`gst.gov.in → Downloads → Offline Tools`)
   and the **GSTR-3B Offline Utility**, and drop the zips here.
2. Re-derive the two schema files from the tool's source (the paths above).
3. Run `php artisan zerobook:prove-gstr1` and `zerobook:prove-gstr3b`. They **refuse to
   run** if these files are missing, and they assert every required key of `_skeleton`
   is present in the generated JSON — so a schema change surfaces as a failing proof.
