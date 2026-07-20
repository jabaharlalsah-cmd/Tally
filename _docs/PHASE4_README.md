# ZeroBook — Phase 4: Financial Reports + F11/F12 Configuration

Trial Balance, Balance Sheet and Profit & Loss — computed from ledger opening
balances + voucher entries, rolled up the group hierarchy, with Tally-faithful
keyboard drill-down to the voucher. Plus the **F11 (Features)** / **F12
(Configuration)** two-tier config and **single-entry mode** for Payment/Receipt/Contra.

The accounting is proven end-to-end (see the worked numeric proof below).

## Step 0 — Phase 3 audit

Phase 3 was audited against its acceptance criteria — **all pass** (verified last
session end-to-end: F-key vouchers, balanced posting, balance gate, Alt+C ledger,
numbering, continuous entry, Day Book alter/cancel, server rejection of unbalanced,
no regressions). Its 9 review fixes remain in place. No Phase 3 fixes were needed.

Schema confirmed from the live DB before computing anything: `account_groups.nature`
∈ {Assets, Liabilities, Income, Expenses}; `ledgers.opening_balance` decimal(18,2) +
`opening_balance_type` enum(Dr,Cr); `voucher_entries.dr_cr`/`amount`/`ledger_id`;
`vouchers.date`/`type`/`fy_start`.

## The balance engine (single authoritative service)

`app/Services/BalanceService.php` — used by every report, never duplicated. Everything
is **integer paise in Dr terms** (Dr positive, Cr negative); rounding only at display.

- **Ledger opening (Dr terms)** = `+opening_balance` if `Dr` else `−opening_balance`, plus
  any movement **before** `from`.
- **Ledger net (period)** = `Σ(Dr amount) − Σ(Cr amount)` over `voucher_entries` joined to
  `vouchers` with `from ≤ date ≤ to`.
- **Ledger closing** = opening + net; presented **Dr** if `> 0`, else **Cr**.
- **Roll-up** aggregates ledger + child-group closings up `account_groups.parent_id`.
- **Trial Balance** grand totals: `Σ Dr closings` and `Σ Cr closings` — must be equal.
- **P&L**: `Net = total Income − total Expenses` (Income nature is Cr, Expenses Dr).
- **Balance Sheet**: `Assets = Liabilities + Nett Profit`; a **Difference in opening
  balances** line is added on the short side if openings don't net to zero (as in Tally).
- The special **Profit & Loss A/c** ledger (`group_id` null) is excluded from the group
  roll-up — profit is computed from Income/Expenses, so it is never double-counted.

## Worked end-to-end numeric proof

Reproduce with `php artisan zerobook:prove-balance` (seeds, asserts, rolls back;
`--keep` to leave it for the browser). Scenario (period = FY 2026-27):

| Ledger | Group (nature) | Opening | Vouchers | Closing |
|---|---|---|---|---|
| Cash | Cash-in-Hand (Assets) | Dr 100,000 | −5,000 +20,000 −15,000 | **Dr 100,000** |
| Capital A/c | Capital Account (Liabilities) | Cr 100,000 | — | **Cr 100,000** |
| Furniture | Fixed Assets (Assets) | 0 | Dr 15,000 (Journal) | **Dr 15,000** |
| Rent Paid | Indirect Expenses (Expenses) | 0 | Dr 5,000 (Payment) | **Dr 5,000** |
| Sales | Sales Accounts (Income) | 0 | Cr 20,000 (Receipt) | **Cr 20,000** |

- **Trial Balance:** Dr = 100,000 + 15,000 + 5,000 = **120,000**; Cr = 100,000 + 20,000 =
  **120,000** → **balanced.** ✓
- **Profit & Loss:** Income 20,000 − Expenses 5,000 = **Nett Profit 15,000.** ✓
- **Balance Sheet:** Assets = Cash 100,000 + Furniture 15,000 = **115,000**; Liabilities =
  Capital 100,000 **+ Nett Profit 15,000** = **115,000** → **balances**, Difference 0. ✓

All three verified live in the browser: TB Grand Total 120,000 = 120,000; BS "✓ balances
(Assets = Liabilities + Nett Profit)"; and the Cash **Ledger Vouchers** drill shows the
running balance 95,000 → 115,000 → 100,000 Dr.

## Reports & drill-down

Three Livewire reports (`app/Livewire/Reports/*`) + a shared `reportScreen` Alpine
controller (`resources/js/reports/screen.js`). The server renders the **full** row set once;
**all navigation, expand/collapse and highlight are client-side (0 network — verified)**.

- **Trial Balance** — single column; groups → (Alt+F1 / Enter) sub-groups → ledgers; grand
  totals that must balance.
- **Balance Sheet** — two columns: **Liabilities | Assets**, Nett Profit carried, Difference
  line if needed.
- **Profit & Loss A/c** — two columns: **Expenses | Income**, Nett Profit/Loss. (Opening/Closing
  Stock lines arrive with inventory in Phase 6 — noted on screen.)
- **Drill:** `Enter` expands a group or drills a ledger → **Ledger Vouchers** (running balance,
  reuses the Day Book row pattern) → `Enter` opens the voucher. `Esc` collapses one level, then exits.

**Key bindings:** `↑`/`↓` move · `Enter` drill · `Esc` ascend/exit · `F2` period · `Alt+F1`
detailed/condensed. F2 re-queries the server (Tally recomputes on period change); arrow keys never do.

## F11 Features (company-level, persisted)

`company_features` table + `CompanyFeature` model + F11 screen (`/features`,
`app/Livewire/FeaturesScreen.php`). Toggles: **Maintain bill-by-bill**, **Cost centres**,
**GST**, **Multi-currency** (their functionality lands in Phase 5/6). Saved with `Ctrl+A`,
persisted, and surfaced to the client as `window.ZB_FEATURES`. They **gate the scaffolded
fields** — e.g. the ledger form's "Maintain balances bill-by-bill?" appears only when the
feature is on (verified: ON → field shows, OFF → hidden).

## F12 Configuration (screen-level, context-aware)

An Alpine `config` store (`resources/js/config/store.js`) persisted to `localStorage`, plus a
context-aware F12 overlay that shows options for the **active** screen:
- **Reports:** Show Opening Balance, Show Percentages.
- **Vouchers:** Use single-entry mode.

## Single-entry mode (extends `voucherScreen`)

When F12 "single-entry" is on, Payment/Receipt/Contra show the single-entry screen: pick the
**account (bank/cash) once**, then list the **other-side ledgers + amounts**. On accept the app
**derives the balanced double-entry** — Payment/Contra credit the account & debit the
particulars; Receipt debits the account & credits the particulars — posting the **same balanced
`voucher_entries`** as double-entry mode (verified: single-entry Payment posted `Cr Cash 3,000 /
Dr Rent 3,000`, Dr = Cr). **Journal always stays double-entry.**

## Current-balance on voucher lines

`VoucherScreen::bootData()` ships a per-ledger closing map (from the balance service); each
voucher line shows the chosen ledger's current balance inline (e.g. `Bal: Dr 97,000.00`).

## Browser-reserved keys — F11 / F12

`F11` (fullscreen) and `F12` (devtools) are **not reliably capturable** in a normal tab, so —
per the Phase 1 fallback policy — Features and Configure are **also** bound to the reliable
alternates **`Alt+F11`** and **`Alt+F12`**, and reachable from the **Gateway** (Features entry)
and **Go To**. They're in `HARD_BLOCK` for best-effort capture; the workflow never dead-ends.

## Migration / SQL notes

- `php artisan migrate` adds `company_features` (one seeded row). Reports need no new tables.
- `_docs/phase4_schema.sql` is the phpMyAdmin export of `company_features`.

## Acceptance self-verification (all passing)

- **Numeric proof:** TB Dr = Cr = 120,000; P&L Nett Profit = 15,000; BS Assets 115,000 =
  Liabilities 100,000 + Profit 15,000 (Difference 0). ✓
- Ledger closing = opening ± entries (Cash Dr 100,000; running balance in Ledger Vouchers). ✓
- Drill-down to the voucher works (report → ledger → Ledger Vouchers → voucher); Enter descends, Esc ascends. ✓
- **F2 period** narrows every report consistently (full FY 120,000 → pre-voucher period 100,000). ✓
- **Alt+F1** expands/collapses groups → ledgers. ✓
- **F11** toggles a feature, it **persists**, and gates the ledger field (ON→shown, OFF→hidden). ✓
- **F12** is context-aware and its options take effect (single-entry, report options). ✓
- **Single-entry** posts the same balanced double-entry as double-entry; **Journal stays double-entry**. ✓
- Voucher lines show the ledger's **current balance** inline. ✓
- Report keyboard nav makes **0 server requests**; only load/drill/F2 hit the server. ✓
- **Phases 1–3 not regressed** (harness, calculator, masters, vouchers, Day Book all work); no console errors. ✓

## Post-review hardening (adversarial review, 12 confirmed fixes)

A multi-lens adversarial review ran over the phase; 12 confirmed defects were fixed (4 reported
issues were verified as false alarms and dismissed — including a correct analysis that Livewire's
`$wire.set(k,v,false)` still mutates reactively):

1. **(critical)** The special **Profit & Loss A/c** ledger (`group_id` null) was postable on a voucher
   line but silently dropped by the balance engine → Trial Balance wouldn't balance. Now it is **excluded
   from the voucher picker AND rejected server-side** (verified: forced post → 0 entries, no voucher).
2. **(critical)** Toggling F12 single-entry **mid-entry** could post a balanced-but-wrong voucher (lines
   reinterpreted, stale account). A `$watch` on `single` now **starts a fresh blank voucher** on any flip.
3. **(high)** `accountLedgerId` is now cleared by `resetLines()` (so switchType/accept never carry a stale account).
4. **(high)** Single-entry account leg is now the **paise-exact** sum of the per-line rounded amounts (no
   float drift; verified 33.335 × 2 → both sides 6,668 paise).
5. **(high)** In single-entry, `Alt+I` adds a **blank** line, not a double-entry balancing line.
6. **(high)** Report `Alt+F1`/expand/collapse now **reconcile the active row** so the highlight never
   strands on a hidden row (verified: detailed-off relocates the cursor to a visible ancestor).
7. **(high)** Arrow keys no longer move report rows while focus is in the **F2 period** date inputs.
8. **(high)** The **F12 report options now take effect** — Show Opening Balance and Show Percentages add
   real columns to the Trial Balance (verified: Cash shows 83.3% = 100k/120k + its opening).

All fixes re-verified live; the numeric proof still passes (TB 120,000 = 120,000; BS balances).

## Out of scope (as specified)

Sales/Purchase + invoice mode + bill-wise/cost-centre/GST **functionality** (Phase 5), inventory /
Stock Summary (Phase 6). Only the F11 **switches** for those exist and gate fields.
