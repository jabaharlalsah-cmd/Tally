# TDS RPU / FVU / File Format — Download Log

**Task:** ZeroBook Phase 10B — fetch official Protean e-TDS/TCS artifacts
**Download date:** 2026-07-11 (UTC)
**Downloaded by:** Claude (Cowork), on request of nslpoint@gmail.com

## Source URL used

- **Primary URL** (`https://www.protean-tinpan.com/downloads/e-tds/eTDS-download-regular.html`) — **did not serve the expected page**. It redirected to `https://tinpan.proteantech.in/` (the Protean PAN homepage), not the e-TDS downloads page. See Anomalies.
- **Backup URL** (`https://tinpan.proteantech.in/downloads/e-tds/eTDS-download-regular.html`) — **worked**, served the full "Procedure for Preparation of TDS Return" page with all expected sections (Form, Data Structure, Sample files, Protean Return Preparation Utility, File Validation Utility). All files below were sourced from this host (`tinpan.proteantech.in`), which is the alternate official host named in the task as the backup.

## Files downloaded

### Pair 1 — Current (statements for TY 2026-27 onwards)

| File | Source URL | Version shown on page | Size (bytes) | Saved as |
|---|---|---|---|---|
| RPU | `https://tinpan.proteantech.in/downloads/e-tds/download/TDS_RPU_1.1.zip` | RPU version 1.1 | 37,395,912 | `rpu_1.1.zip` (unpacked to `rpu-current/`) |
| FVU | `https://tinpan.proteantech.in/downloads/e-tds/download/TDS_STANDALONE_FVU_1.1.zip` | FVU version 1.1 | 9,523,584 | `fvu_1.1.zip` (unpacked to `fvu-current/`) |

### Pair 2 — Historical (statements FY 2010-11 up to FY 2025-26; RPU/FVU cover FY 2007-08 onward per page text)

| File | Source URL | Version shown on page | Size (bytes) | Saved as |
|---|---|---|---|---|
| RPU | `https://tinpan.proteantech.in/downloads/e-tds/download/TDS_RPU_6.0.zip` | RPU version 6.0 | 38,675,841 | `rpu_6.0.zip` (unpacked to `rpu-historical/`) |
| FVU | `https://tinpan.proteantech.in/downloads/e-tds/download/TDS_STANDALONE_FVU_9.5.zip` | FVU version 9.5 | 9,543,075 | `fvu_9.5.zip` (unpacked to `fvu-historical/`) |

Note: the historical RPU 6.0 zip also bundles `TDS_STANDALONE_FVU_2.191.jar` (the FVU for FY ≤ 2009-10), untouched, inside `rpu-historical/TDS_RPU_6.0/`.

### File format specifications

The page's "Data Structure" section splits format specs into three eras. Per the task's request, Form 26Q was downloaded as required, plus 27Q and 27EQ since both were available. Both the current-year and historical-year variants were pulled since the RPU/FVU pair download already spans both eras.

| Form | Era | Version on page | Source URL | Size (bytes) | Saved as |
|---|---|---|---|---|---|
| 26Q (Form No. 140 for TY26-27+) | Current (FY 2026-27 onwards) | v1.0 | `.../Form Number 140 - 26Q - Q1 to Q4_02072026.xlsx` | 52,082 | `form-26q-file-format-current.xlsx` |
| 26Q | Historical (FY 2010-11–2025-26) | v7.8 | `.../File_Format_26Q_Regular_Q1_to_Q4_Version_7.8_27052025_201011.xls` | 127,488 | `form-26q-file-format-historical.xls` |
| 27Q (Form No. 144) | Current | v1.0 | `.../Form Number 144-27Q - Q1 to Q4_02072026.xlsx` | 52,951 | `form-27q-file-format-current.xlsx` |
| 27Q | Historical | v7.5 | `.../File_Format_27Q_Regular_Q1_to_Q4_Version_7.5_27052025_201011.xls` | 153,088 | `form-27q-file-format-historical.xls` |
| 27EQ (Form No. 143) | Current | v1.0 | `.../Form Number 143-27EQ - Q1 to Q4_02072026.xlsx` | 38,545 | `form-27eq-file-format-current.xlsx` |
| 27EQ | Historical | v6.9 | `.../File_Format_27EQ_Regular_Q1_to_Q4_Version_6.9_ 27052025_201011.xls` | 122,368 | `form-27eq-file-format-historical.xls` |

(24Q / Form No. 138 spec was not downloaded — task asked only for 26Q required, plus 27Q/27EQ if available; 24Q was out of scope.)

## Sample .fvu generation

**Not generated.** Java 11 (OpenJDK) and Xvfb are present in the execution environment, but:

- `TDS_RPU_1.1.jar` is a Swing GUI application with no documented CLI/batch mode for statement creation — running it headless (`-Djava.awt.headless=true`) produces no usable output.
- Driving the GUI under Xvfb would require blind input automation (no way to view the virtual display / take screenshots of it in this sandbox) across many interdependent, validity-checked screens (deductor TAN/PAN checksum, address, at least one challan, at least one deductee row) before a "Create File" action produces the `.fvu`. That's too error-prone to trust as a byte-level reference artifact without visual verification at each step.

Per the task's own fallback instruction, this step was skipped. The downloaded spec spreadsheets (current + historical, per form) are the reference artifact instead.

## Anomalies observed

1. **Primary URL does not serve the download page.** `https://www.protean-tinpan.com/downloads/e-tds/eTDS-download-regular.html` redirects to the Protean PAN homepage (`https://tinpan.proteantech.in/`), a client-rendered React app with no e-TDS content at that path. The backup URL (`https://tinpan.proteantech.in/...`) served the correct, correctly-labeled page — this is a same-host situation (`protean-tinpan.com` and `proteantech.in` are both Protean-controlled domains per the task's own framing of them as "same downloads, alternate host"), so it stayed within the two authorized official sources. No third-party or unofficial source was used.
2. **File format specs are XLS/XLSX, not PDF.** The task described the "File Format" section as containing PDFs. On the live page, the current-year (FY2026-27+) specs are `.xlsx` workbooks and the historical-year specs are legacy `.xls` workbooks — no PDF format specs exist on the page at all. Files were saved with their real extensions (`.xlsx` / `.xls`) rather than being force-renamed to `.pdf`, since that would misrepresent the file type to any downstream parser. If the downstream code-gen task specifically requires PDF, these will need to be converted or the task's file-reference assumption updated.
3. **Current vs. historical duality for format specs**, not anticipated by the task's flat `form-26q-file-format.pdf` naming. Resolved by suffixing `-current` / `-historical` per file (see table above) rather than picking one arbitrarily, so downstream tooling can select the era it needs.
4. **Raw ZIPs retained.** The four downloaded RPU/FVU zip files (`rpu_1.1.zip`, `rpu_6.0.zip`, `fvu_1.1.zip`, `fvu_9.5.zip`) could not be deleted from the destination folder after unpacking (write-once restriction on this connected folder) — they remain alongside their unpacked subfolders. This is harmless and preserves an untouched byte-identical copy of what Protean served.
5. No other broken links or missing files were observed on the download page.

## Integrity note

All files were downloaded directly via HTTPS from `tinpan.proteantech.in` with no modification, re-encoding, or renaming beyond the explicit destination-filename mapping described above (format-spec files only — RPU/FVU zips and their unpacked contents are untouched).
