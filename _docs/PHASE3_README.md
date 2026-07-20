# ZeroBook — Phase 3: Accounting Vouchers + Day Book

The four core accounting vouchers — **Contra (F4), Payment (F5), Receipt (F6), Journal (F7)** —
in Tally's **"as Voucher" (double-entry line) mode**, plus a **Day Book** review surface.
The whole entry loop is client-side (Alpine); the server (Livewire) is touched **only on accept**,
where it re-validates the double-entry balance and assigns the authoritative number.

## Step 0 — Phase 2 audit result

Audited Phase 2 against its acceptance criteria before building — **all pass**. Nine defects from a
prior adversarial review were fixed first (see `_docs/PHASE2_README.md`): multi-create Dr/Cr + `min:0`
validation, reserved-ledger group lock, atomic delete + `restrictOnDelete` FK, `DB::transaction` on
batches, `popToContext` for nested-Alt+C context leaks, reactive `excludeModel` on Alter's Under picker,
picker Enter no-dead-end, and an engine `_focusInto` generation-token + retry-window fix.

> The `_focusInto` fix (generation token so two rapid context focuses can't fight, plus a longer retry
> window for slow overlay reveals) also benefits Phase 3's dense keyboard flow. Note: automated tests run
> in a headless preview where `document.hasFocus() === false`, so the browser drops *async* `.focus()`;
> logic was therefore verified by explicit focus + DB assertions.

## What was built

| Area | Files |
|------|-------|
| Data | `app/Models/Voucher.php`, `VoucherEntry.php`, `database/migrations/2026_07_07_000001_*` (vouchers), `_000002_*` (voucher_entries) |
| Livewire (commit-only) | `app/Livewire/VoucherScreen.php` (post + validate + number + alter + cancel), `DayBook.php` (list + period + cancel), `app/Livewire/Concerns/CreatesLedgers.php` (inline ledger) |
| Entry engine | `resources/js/vouchers/screen.js` (`voucherScreen` + `dayBook` controllers) |
| Picker reuse | `resources/js/masters/select.js` — added an **event-sink** mode so the Phase 2 picker writes voucher lines (which live in Alpine) instead of `$wire` |
| Engine bindings | `resources/js/engine/engine.js` (`openVoucher`, F4–F7 + Alt+F6 globals, Vouchers button-bar group), `keys.js` (F7 → HARD_BLOCK) |
| Views | `resources/views/livewire/voucher-screen.blade.php`, `day-book.blade.php`, `resources/views/partials/quick-ledger.blade.php`, `resources/views/vouchers/{entry,day-book}.blade.php` |
| Nav / routes | `app/Http/Controllers/VouchersController.php`, `routes/web.php`, `app/Support/Shell.php` (Gateway V/B + Go To + config URLs) |
| Styling | `resources/css/vouchers.css` |

## Voucher field map & flow

`Date` (top-right, **F2** focuses it) · `Voucher No.` (auto, per type per FY) · then **ledger lines**:

```
[ Dr/Cr ]  [ Particulars — ledger via Phase-2 picker ]  [ Debit | Credit amount ]
```

- The screen starts with **one** line (side defaulted per type: Receipt→Cr, others→Dr).
- `Enter` chains `Dr/Cr → ledger → amount`. On `Enter` past the **last** line's amount, if the voucher is
  out of balance a **balancing line is auto-added** with the opposite side and the **difference pre-filled**
  (Tally's rhythm); if already balanced, focus jumps to **Narration**.
- **Live running totals** for Debit and Credit, and a **difference** indicator: `Balanced`, or
  `Debit/Credit short by X`. **Accept is blocked while Dr ≠ Cr.**
- `Narration` closes the voucher; `Enter` on it (or `Ctrl+A` from anywhere) **accepts**.
- On accept the voucher posts **balanced Dr/Cr rows** and a **fresh blank voucher of the same type**
  appears immediately (continuous entry) — number incremented, lines/narration cleared.

## Key bindings (this phase)

| Key | Action |
|-----|--------|
| `F4`/`F5`/`F6`/`F7` | Open Contra/Payment/Receipt/Journal — or **switch type** when already in a voucher |
| `Alt+F6` | Receipt (reliable fallback — see below) |
| `Enter` | Advance Dr/Cr → ledger → amount → next line → narration → accept |
| `Ctrl+A` | Accept & post from any field |
| `Alt+C` | Create a ledger inline on the current line (Under picker's `Alt+C` creates a group) |
| `Alt+I` / `Alt+R` | Add / remove a ledger line |
| `Alt+D` | Cancel the voucher (alter mode & Day Book), with confirmation |
| `F2` | Change voucher date / Day Book period |
| `↑`/`↓` | Navigate lines / Day Book list |

## Posting, balance validation & continuous entry

- **Client:** `voucherScreen.accept()` refuses to call the server unless `balanced` (difference 0, total > 0,
  ≥ 2 filled lines). Totals/difference are rounded to 2 dp to avoid float drift.
- **Server (authoritative):** `VoucherScreen::post()` re-validates every line (ledger exists, `dr_cr` ∈ {Dr,Cr},
  `amount > 0`) **and** `round(sum Dr,2) === round(sum Cr,2)` in a Validator `after()` hook, then writes the
  voucher + entries inside a `DB::transaction`. An unbalanced payload — even if forced past the client — is
  rejected with no DB write (verified). Numbering is `max(number)+1` per `(type, fy_start)` computed inside
  the transaction; a unique index `(type, fy_start, number)` backstops duplicates.
- **Continuous entry:** `post()` returns the saved row + the next number; the controller resets to a blank
  voucher of the same type without a page load.
- **Alter:** re-posts by updating the header (type & number preserved) and **replacing** all entries in one
  transaction. **Cancel/Delete:** removes the voucher; `voucher_entries.voucher_id` is `cascadeOnDelete`, so
  postings are reversed cleanly.

## Browser-reserved keys — F6 / Receipt fallback

`F5` and `F6` are in the engine's `HARD_BLOCK` list, so the engine `preventDefault`s them and the browser's
reload / address-bar never fires when they're captured — **F5 (Payment) is reliable**. `F6` is only
*best-effort* capturable in a normal tab (some environments hand it to the browser first), so **Receipt is
also reachable three other ways** and never dead-ends:

1. **`Alt+F6`** — a reliably-capturable alternate bound to Receipt.
2. The **Gateway → Vouchers** entry and the **Go To → Receipt Voucher** destination.
3. Once on any voucher screen, pressing **F6/Alt+F6** switches the type client-side.

(F7 was added to `HARD_BLOCK` to suppress Chrome's caret-browsing prompt.)

## Zero-network guarantee

Verified in the Network tab: entering lines, filtering the ledger picker, moving between Dr/Cr/amount,
adding/removing lines, and the running-total updates make **0 requests**. Only **accept** posts (1 request).
Masters are read from the Phase 2 client cache, so ledger lookups are instant.

## Migration / SQL notes

- `php artisan migrate` creates `vouchers` and `voucher_entries` (both start empty).
- `_docs/phase3_schema.sql` is a ready-to-run phpMyAdmin export of the two tables' structure.
- `vouchers`: unique `(type, fy_start, number)`, indexes on `date` and `(type, date)`.
- `voucher_entries`: `voucher_id` cascade-on-delete, `ledger_id` restrict-on-delete, `amount` always positive,
  `dr_cr` enum, `line_no` for order. Columns are left open for later bill-wise / cost-centre references.

## Acceptance self-verification (all passing)

Driven with real key events + DB checks:

- `F4/F5/F6/F7` open the correct voucher from anywhere; **F5 does not reload**; Receipt reachable via `Alt+F6` + menu. ✓
- A **Payment** entered by keyboard posts **balanced** `Dr Rent 5000 / Cr HDFC 5000` to `vouchers` + `voucher_entries`. ✓
- Voucher **cannot be accepted out of balance** (blocked, difference shown, 0 network); balanced → `Ctrl+A` posts. ✓
- **Alt+C** creates a ledger inline mid-voucher (ZB Petrol Exp) and returns with it selected. ✓
- **Auto-numbering** per type (`PYMT-1`); **narration** saved. ✓
- After accept, a **fresh blank voucher** of the same type appears (number → 2, lines/narration cleared). ✓
- **Day Book** lists vouchers; `Enter` drills; **Alter** re-posts (5000 → 7500, entries replaced not duplicated, number kept); **Cancel** cascade-removes postings. ✓
- **0 network** during entry/picker/totals; **1** on accept. ✓
- Server **rejects a forced unbalanced** voucher — no DB write. ✓
- **Phase 1 & 2 not regressed** (harness, calculator, masters picker all still work); no console errors. ✓

## Post-review hardening (adversarial review, 9 confirmed fixes)

A multi-lens adversarial review ran over the phase; 9 confirmed defects were fixed (3 reported issues were
verified as false alarms and dismissed):

1. **(critical)** Amount inputs were missing `data-zb-field`, so Enter-chaining skipped the amount column —
   added it, so `Dr/Cr → ledger → amount → next line` chains correctly by keyboard.
2. **(high)** Altering a voucher across financial years recomputed `fy_start` while keeping `number`, risking a
   unique-key collision / sequence corruption — alter now keeps **type, number and `fy_start` stable**.
3. **(high)** `nextNumber` (max+1) had a TOCTOU race under concurrent posts — new vouchers now **retry on a
   duplicate-key** collision with a fresh number (each attempt its own transaction/snapshot).
4. **(high)** `post()` re-opened the alter target with `findOrFail` after validation (could 500 on a concurrent
   cancel) — now `lockForUpdate()->find()` with a friendly "no longer exists" validation error.
5. **(high)** The balance check compared two accumulated floats with `!==` (IEEE-754 collapse at huge
   magnitudes) — now accumulates and compares in **integer paise**.
6. **(medium)** `switchType` / continuous-entry reset could orphan an open ledger-picker's engine context —
   both now `closePickers()` before resetting lines.
7. **(low)** `switchType` didn't clear `narration` — it does now.
8. **(low)** The Day Book flash had a duplicate `class` attribute (dropped `is-ok` styling) — merged.

All fixes verified: keyboard chaining reaches the amount field; a cross-FY alter keeps `fy_start=2026`/`number=1`;
a forced sub-cent-unbalanced voucher is rejected; posting + continuous entry still work; no console errors.

## Out of scope (as specified)

Single-entry mode (F12 toggle, Phase 4), Sales/Purchase (Phase 5), inventory (Phase 6), bill-wise/cost-centre/GST
sub-screens (Phase 5), and Trial Balance / Balance Sheet / P&L (Phase 4) were **not** built.
