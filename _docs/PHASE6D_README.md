# ZeroBook — Phase 6D: Integrate Accounts with Inventory (the tie-out)

The capstone of Phase 6. Phases 6A–6C built and hardened a weighted-average stock
engine that writes to `stock_entries`; **6D reads that data into the accounting
reports** so the whole system ties out: **Opening Stock / Closing Stock / Gross
Profit** in the P&L, a computed **Stock-in-Hand** asset in the Balance Sheet, and a
new **Stock Summary** report. As in Tally, there is no per-sale COGS ledger entry —
Closing Stock and Gross Profit are **computed report-level figures**.

---

## Step 0 — audit & the Direct/Indirect split I found

All prior proofs pass (after starting Laragon MySQL, which was stopped). Reading the
**real** `BalanceService`:
- Everything is **integer paise, Dr-terms** (Dr +, Cr −). `profitAndLoss()` computed
  `net = totalIncome − totalExpenses` by group **nature**; `balanceSheet()` already
  reused `$pl['net']` — i.e. one shared profit figure (exactly what 6D needs).
- The seeded primaries **do** distinguish `Sales Accounts` / `Purchase Accounts` /
  `Direct Incomes` / `Direct Expenses` (Trading, above Gross Profit) from
  `Indirect Incomes` / `Indirect Expenses` (below it). `Stock-in-Hand` is a child of
  the `Current Assets` primary. So the split needed for Gross Profit is by **root
  name**, not nature.

**This phase is purely additive** — the existing group-summation logic is untouched;
inventory contributes a correction term on top of it.

---

## The formulas, as implemented

`StockService` (the only new methods): `totalOpeningValue()` = Σ every item's
`opening_value`; `totalClosingValue($asOf)` = Σ `closingBalance($id, $asOf)['value']`.
Both return 0.0 for a company with no items — which is what makes the correction
vanish.

`BalanceService::profitAndLoss()` keeps the Phase-4 `ledger_net` and adds:

```
openingStock = round(totalOpeningValue()  × 100)      // paise, period-independent
closingStock = round(totalClosingValue(to) × 100)     // paise, as of period end
Gross Profit = (tradingIncome + closingStock) − (tradingExpense + openingStock)
Net Profit   = Gross Profit + indirectIncome − indirectExpense
             ≡ ledger_net + (closingStock − openingStock)      ← the additive term
```

Because the identity `Net = ledger_net + (closing − opening)` holds exactly, a company
with **no stock** (both terms 0) gets `Net == ledger_net` and every returned field
equals the pre-6D value — the regression is guaranteed by construction, not by luck.

`BalanceService::balanceSheet()` injects `closingStock` **into the `Stock-in-Hand`
group node** (`injectStockValue()` adds it to that node and every ancestor up to the
Assets total — additive, never clobbering a ledger balance) and into `totalAssets`;
the Nett Profit carried across is the **same** `$pl['net']` (one figure, never two).
`closingStock == 0` ⇒ injection skipped ⇒ output byte-identical to before.

---

## The P&L display

Regression-gated on `hasStock = opening>0 || closing>0`:
- **No stock** → the exact pre-6D flat layout (`flattenMag(expense_roots)` / `income_roots`,
  single grand total, no stock/Gross-Profit rows) — byte-identical.
- **With stock** → Tally's **two-section horizontal format** so every visible column
  adds up to its own subtotal:
  - **Trading account** — Dr: Opening Stock · Purchase/Direct-Expense roots · **Gross
    Profit c/d** · *Trading Total*. Cr: Sales/Direct-Income roots · Closing Stock ·
    *Trading Total*. (Dr Trading Total ≡ Cr Trading Total.)
  - **Profit & Loss account** — Dr: Indirect-Expense roots · Nett Profit · *Total*.
    Cr: **Gross Profit b/d** · Indirect-Income roots · *Total*. (Gross Profit carries
    down from Trading; each section balances.)
  - Gross/Nett *loss* variants mirror this (Gross Loss c/d on the Cr side, Nett Loss
    on the Cr side).

Worked example: Trading — Dr Purchase 1,000 + GP c/d 600 = **1,600** = Cr Sales 1,200 +
Closing Stock 400. P&L — Dr Nett Profit 600 = **600** = Cr GP b/d 600. Every line adds
up; Gross Profit **600** and Net Profit **600** are both explicit.

Balance Sheet shows **Stock-in-Hand** in place under Current Assets (the injected
value), rolling up to the Assets total; F2 period, detailed/condensed and drill all
work unchanged.

---

## Stock Summary report (new)

`/reports/stock-summary` — quantity + value per Stock Item as of the period end,
rolled up through the **Stock Group hierarchy** (mirrors the Trial Balance rollup and
reuses the shared `reportScreen` keyboard client). **Enter** expands a group and drills
an **item → its movement history** (`/reports/stock-item/{id}/movement`, a
Ledger-Vouchers-style list over `stock_entries` with a running quantity, each row
drillable to its voucher). Value is the item-level weighted-average closing value (the
same figure the P&L Closing Stock and BS Stock-in-Hand use); **group rows roll up
value only** — quantities aren't summed across a group (units don't add meaningfully),
a documented, faithful choice. Reachable from the Gateway (letter **L**) and Go To
(keywords: stock summary, inventory, stock).

---

## The worked numeric proof — `php artisan zerobook:prove-inventory-integration`

Posts through the real paths (GST/VAT off, no tax):

- Buy **100 @ 10** (`Dr Purchase 1,000 / Cr Party 1,000` + stock IN 100@10).
- Sell **60 @ 20** (`Dr Party 1,200 / Cr Sales 1,200`; locked cost 60×10 = 600).

Asserts (paise), **20/20 pass**:
- **Ledger-only Net = 200**, but Closing Stock = **400**, Opening Stock = **0**, so
  **Gross Profit = Net Profit = 600** (the true margin: revenue 1,200 − COGS 600).
- **Balance Sheet ties out:** Assets (Debtor 1,200 + Stock-in-Hand 400 = **1,600**) =
  Liabilities (Creditor 1,000 + Net Profit 600 = **1,600**), no forced difference line,
  BS net == P&L net (one shared figure).
- **F2 earlier period** (before the sale) → Closing Stock = **1,000** (full 100 units).
- **Regression (no stock items):** Opening/Closing Stock **exactly 0**, `net === ledger_net === 200`,
  Balance Sheet still balances, Stock-in-Hand absent — the correction is genuinely zero.

---

## Acceptance — self-verified

- ✅ `zerobook:prove-inventory-integration` — **20/20**.
- ✅ **Browser-driven** (127.0.0.1:8777): the **P&L** Trading account shows Purchase
  1,000 + Gross Profit c/d **600** = Trading Total **1,600** = Sales 1,200 + Closing
  Stock **400**, and the P&L account shows Nett Profit **600** = Gross Profit b/d 600
  (each section balances); the **Balance Sheet** shows Stock-in-Hand **400.00** under
  Current Assets (rolled to 1,600) and reads *"✓ Balance Sheet balances"*; the **Stock
  Summary** shows Finished Goods → Widget 40 Nos / ₹400 → Grand Total 400; **drilling**
  Widget shows PURC-1 IN 100@10 (Bal 100) and SALE-1 OUT 60 **@ the weighted-average
  cost 10** (Bal 40), Closing 40 Nos / ₹400. No console errors.
- ✅ `npm run build` clean; **all eight prior proofs still pass**; DB restored.

---

## Post-build adversarial review

The finished 6D diff went through the same multi-agent adversarial review (6 dimensions
× dual-lens verify × high-effort synthesis). The core math was **verified correct** (the
additive net identity, the balance-sheet tie-out + additive Stock-in-Hand injection, the
no-stock regression). It found **5 real defects** — all fixed and re-verified:

1. **HIGH — Stock Summary group→item collapse was broken.** `reportScreen.isVisible()`
   short-circuited any non-`group`/`ledger` row to always-visible, so the new `item`
   leaves rendered unconditionally (a group never hid its items). **Fix:** `item` now
   honours the ancestor-expanded gate. **Verified:** collapsed on load → expand shows the
   item → collapse hides it.
2. **MEDIUM — negative closing stock broke the P&L display.** An oversold item (closing
   value < 0) slipped into the flat pre-6D branch (gated on `> 0`) even though `net`
   still shifted, so a column stopped summing to its Total. **Fix:** the P&L gate is now
   sign-aware (`!= 0`); a negative closing sits on the Dr side as *Closing Stock
   (deficit)*. **Verified** (buy 10@10, sell 20@15): Dr Trading 100+100+100 = **300** =
   Cr Sales 300; P&L Nett Profit 100 = GP b/d 100 — every section balances.
3. **LOW — Stock Summary group subtotal** rounded the float subtree sum while items
   rounded individually (±1-paise drift). **Fix:** the rollup now sums rounded per-item
   paise, so a group always equals the item rows beneath it.
4. **LOW (proof) — the injection into the asset TREE was unverified** (only the scalar
   total was checked). **Fix:** the proof now walks `asset_roots`, asserts the
   Stock-in-Hand node carries the injected 400, and that the roots sum to `total_assets`.
5. **LOW (proof) — the regression Balance-Sheet check was tautological** (`balanced` is
   forced true). **Fix:** the regression now asserts `asset_total == liability_total` and
   no forced difference line. Proof is now **24/24**.

Separately, during the build I upgraded the P&L from a single-total layout (where Gross
Profit + Nett Profit both showing made a column not visibly add up) to Tally's faithful
**two-section** Trading + P&L format — each section balances on screen.

---

## Scope — not in 6D
Ratio Analysis, data export, multi-currency, budgets, multi-company — out of scope.
No change to GST/VAT/bill-wise/cost-centre reports. No change to `StockService`'s
existing per-item methods beyond the two aggregate additions.

Documented limitation: `totalClosingValue()` loops the per-item fold; for a very large
catalogue that's a future optimisation, not a correctness concern.

---

## Files

**Server**
- `app/Services/StockService.php` — `totalOpeningValue()`, `totalClosingValue()` (added; existing methods untouched).
- `app/Services/BalanceService.php` — `TRADING_ROOTS`; `profitAndLoss()` inventory correction + splits; `balanceSheet()` Stock-in-Hand injection; `injectStockValue()`.
- `app/Http/Controllers/ReportsController.php` — `stockSummary()`, `stockItem()`.
- `app/Console/Commands/ProveInventoryIntegrationCommand.php`.

**Livewire + views**
- `app/Livewire/Reports/{ProfitLoss,StockSummary,StockItemMovement}.php`.
- `resources/views/livewire/reports/{profit-loss,stock-summary,stock-item-movement}.blade.php`.
- `resources/views/reports/{stock-summary,stock-item-movement}.blade.php`.

**Client / nav / css**
- `resources/js/reports/screen.js` — `item` kind (navigable + drillable).
- `app/Support/Shell.php` — Stock Summary nav + Gateway letter L.
- `resources/css/reports.css` — `zb-r-stock` / `zb-r-gross` / `zb-r-item` styles.

No schema changes (pure aggregation of existing data).
