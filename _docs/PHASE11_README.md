# ZeroBook — Phase 11: the multi-currency engine

**Status: done and proven.** `php artisan zerobook:prove-forex` → **62 assertions, 0 failures.**
Full cold regression: **20/20** prove-commands green (18 prior + `prove-forex` + `prove-multi-tenant`
47/0). The phpMyAdmin script `_docs/phase11_schema.sql` was verified to reproduce the migration
schema **byte-identically** (provision → revert Phase 11 → apply script → `SHOW CREATE TABLE` diff = 0).

Phase 11 adds **foreign-currency accounting** as a *layer on top of* the base-currency books:
foreign-currency party ledgers, a dated exchange-rate history, dual-currency voucher entry, **realised**
gain/loss on settlement, and **unrealised** (revaluation) gain/loss at period-end. The Trial Balance,
Balance Sheet and P&L stay in base currency — they were not touched. Everything posts through the same
`VoucherScreen::post()` every other voucher uses; there is no parallel posting path.

---

## Step 0 — audit result

Every prior prove-command was re-run before a line of Phase 11 was written: **18/18 green**. Nothing
needed fixing. Phase 11 only *adds* — two tables, three nullable columns on two existing tables, one
service, one client layer, a master screen and a report — and re-runs all 18 afterwards unchanged.

---

## What "multi-currency" means here (and what it deliberately does not)

A ZeroBook company keeps its books in **one** reporting currency (its *base*: INR for an Indian tenant,
NPR for a Nepali one). Multi-currency does **not** make the ledgers multi-valued. It does exactly one
thing: it lets a **party** be denominated in a foreign currency, records **how many foreign units** each
line represents and **at what rate**, and books the base-currency equivalent that the rest of the system
already understands. The foreign figures are a faithful sidecar; the `amount` in paise remains the single
source of truth for the Dr/Cr balance gate and every report.

Concretely, on any foreign line this invariant holds and is enforced **server-side** on every post:

```
amount (paise) = ROUND(foreign_amount × exchange_rate × 100)
```

- **Do not trust a client-supplied base amount.** `ForexService::deriveBase()` recomputes it from
  `foreign × rate` and `verifyPayload()` rejects the line if the submitted `amount` doesn't match to the
  paise. A tampered base value is refused (proven).
- **Do not trust a client-supplied rate.** The rate must equal `rateOn(currency, voucher-date)` — the
  most recent rate on or before the voucher date — unless the user supplies an explicit, reason-stamped
  **override** (see below), or the line is *settling* a bill (where the party line legitimately carries
  the bill's original **booked** rate). Any other rate is refused.

---

## The data model

Everything is per-tenant; nothing is central (see "Plan-tier gating" for why there is no central change).

### 1. `currencies` — the currency master
| column | meaning |
|---|---|
| `code` (unique, 3) | ISO code: `USD`, `EUR`, `INR`, `NPR` |
| `symbol` (8) | `$`, `€`, `₹` |
| `name` (60), `decimal_places` (default 2) | display |
| `is_base` (bool) | **exactly one row is true** — the reporting currency |

Seeded with **INR as base**. The master UI (`masters.currencies`) enforces exactly-one-base: marking a
new base un-marks the previous one. A Nepali tenant marks **NPR** base, which correctly turns INR into a
foreign currency for that book. Users add every other currency by hand.

### 2. `exchange_rates` — the dated rate history
`rate` = the **base-currency value of one foreign unit**, `DECIMAL(16,6)` (1 USD = ₹83.50 → `83.500000`).
`UNIQUE(currency_id, date)` = one rate per currency per day. `ExchangeRateService::rateOn(id, date)`
returns the most recent rate **on or before** the date and *throws* if none exists (a voucher cannot be
priced against a currency with no rate yet). The base currency is always rate `1.0`.

### 3. `ledgers.currency_id` (nullable) — foreign-currency tagging
`NULL` = base currency (every existing ledger, untouched). A party ledger tagged with a **non-base**
currency is a foreign-currency ledger: every voucher line on it must carry a foreign amount + rate, and
the server rejects the line if it doesn't. `ON DELETE SET NULL` — removing a currency reverts its ledgers
to base, never deletes them.

### 4. `voucher_entries.{currency_id, foreign_amount, exchange_rate}` (all nullable) — the dual shape
All three `NULL` on a base line. On a foreign line they carry the sidecar, and the invariant above holds.
`amount` is still the only column the balance gate reads.

### 5. Two reserved ledgers
`Foreign Exchange Gain` (Indirect Incomes) and `Foreign Exchange Loss` (Indirect Expenses). Ordinary
base-currency nominal ledgers, so they flow into the P&L naturally and are **invisible to the GST/VAT/TDS
engines** (those key on `tax_type`, which is `NULL` here). `ForexService` finds them by their reserved
names. Both open at zero.

---

## Realised gain/loss — the worked example, to the paise

The exchange rate on the day you **invoice** a foreign customer differs from the rate on the day you
**collect**. That timing difference is a real profit or loss, and it lands on settlement.

**The rule** (receivable shown; payable is the mirror):

```
booked_value    = foreign × booked_rate        (what the party was booked at, in paise)
settlement_cash = foreign × settlement_rate     (what the bank actually received/paid, in paise)
realised        = receivable ? (settlement_cash − booked_value)
                             : (booked_value − settlement_cash)
realised > 0 → Cr Foreign Exchange Gain
realised < 0 → Dr Foreign Exchange Loss
```

**Proven loss.** Acme Corp USA is invoiced **$10,000 @ 84.20** on 15-Jul-2026 (bill EXP-1) → booked
₹8,42,000. The $10,000 is received on 15-Aug when the rate is **82.80**:

| line | Dr | Cr | note |
|---|---|---|---|
| Acme Corp USA (closes EXP-1 at **booked** 84.20) | | 8,42,000.00 | foreign $10,000 |
| HDFC Bank (actual cash at 82.80) | 8,28,000.00 | | |
| **Foreign Exchange Loss** | **14,000.00** | | 8,42,000 − 8,28,000 |

Receivable, cash < booked → **realised loss ₹14,000.00**. The voucher balances (8,42,000 = 8,28,000 +
14,000); the bill EXP-1 closes to zero. `ForexService::computeRealisedGainLoss()` computes the figure and
the fourth line the client adds; `verifyPayload()` re-derives and confirms it server-side.

**Proven gain.** A $5,000 bill booked @ **82.00** (₹4,10,000) and collected @ **84.00** → cash ₹4,20,000,
receivable, cash > booked → **realised gain ₹10,000.00**, posted **Cr Foreign Exchange Gain 10,000**.

The **party line closes the bill at the booked rate** (foreign × booked = booked_value), the **bank line
is the real cash** (foreign × settlement_rate), and the **forex line is the difference** — so the balance
gate, given party + forex, forces the bank line automatically. This is why settlement lines are the one
place a non-`rateOn` rate is legal on the party line: it *must* carry the bill's original booked rate.

---

## Unrealised gain/loss — revaluation at period-end

An **open** foreign bill still sitting on the books at year-end is worth a different number of rupees than
when it was booked. Accounting standards require you to restate it to the closing rate and recognise the
**unrealised** difference — without touching the still-open foreign outstanding.

```
remaining_foreign = |pending_base| / booked_rate      (foreign units still open)
revalued          = ROUND(remaining_foreign × current_rate)
unrealised        = receivable ? (revalued − booked) : (booked − revalued)
```

**Proven.** An open **$5,000 @ 84.20** bill (₹4,21,000) revalued at the 31-Aug closing rate **82.80** →
₹4,14,000. Receivable, revalued < booked → **unrealised loss ₹7,000.00**.

The **Forex Revaluation report** (`reports.forex-revaluation`, Gateway letter `5`) lists every open
foreign bill with its booked INR, revalued INR and the difference, and a net figure. **It does not
auto-post.** The report *computes*; the user clicks **post revaluation journal**, and the draft goes
through the **normal** `VoucherScreen::post()` path:

| line | Dr | Cr |
|---|---|---|
| Acme Corp USA (on-account, ref `Forex Reval 31-Aug-2026`) | | 21,000.00 |
| Foreign Exchange Loss | 21,000.00 | |

The party leg is booked **on account** against the party's bills, so it adjusts the ledger's INR carrying
value **without changing the foreign outstanding** — it surfaces as "on account" in Outstandings, and the
reconciliation `closing = Σ pending + on_account` still holds. The Trial Balance still balances (proven,
full period). The banner reminds the user to **reverse it next period** if they revalue afresh — standard
practice, because next period's settlement realises against the *original* booked rate.

---

## Server authority — the rate-override mechanism

The day's `rateOn` rate is the default and is enforced. But a real desk sometimes contracts at a specific
rate (a forward, a customer-agreed rate). So a voucher may carry `rate_override = true` with a mandatory
`rate_override_reason`. When set, `verifyPayload()` accepts the line's rate as given **but still enforces
the base invariant** (`amount = round(foreign × that_rate × 100)`) — you can choose the rate, never the
arithmetic. The reason is stamped into the voucher narration for audit. Without the override flag, an
off-history rate is rejected. The client mirrors `rateOn` from the shipped rate history purely to *warn*;
the server is the authority and re-checks everything.

---

## Regime notes — GST and Nepal VAT

- **GST.** A foreign sale to a party with **no Indian state** attracts **no GST** — an export is
  zero-rated/out-of-scope, and the GST engine already keys on the party's state, so a stateless foreign
  debtor simply produces no tax line. Proven: a foreign sale posts with GST on, no CGST/SGST/IGST added.
- **Nepal VAT.** The base-vs-foreign machinery is regime-agnostic. A Nepali (NPR-base) tenant marks NPR
  base and treats INR/USD as foreign exactly the same way; the forex Gain/Loss ledgers and the P&L stay
  in NPR. No VAT-return logic changed.
- The forex Gain/Loss ledgers carry `tax_type = NULL`, so they can never be picked as a tax line and
  never appear in any GST/VAT/TDS summary.

---

## Plan-tier gating (F11)

Multi-currency is an **Enterprise** feature, enforced at two layers:

1. **F11 company switch** — `company_features.multi_currency` (pre-existing since Phase 7A; **this
   migration does not touch it**). `ForexService::enabled()` returns false unless it is on.
2. **Plan gate** — `PlanGate::allows('multi_currency')` reads the central `plans.features` JSON, where
   `multi_currency` is gated to `enterprise` only (`starter=false, professional=false, enterprise=true`,
   set in the plans migration since Phase 7B). `enabled()` requires **both**.

So a Professional tenant that flips the F11 switch still can't post a foreign line — the plan gate refuses
it. Proven: with multi-currency off, a foreign line is refused.

**Because both the switch and the plan entry pre-existed, Phase 11 has *no* central-database change** —
`phase11_schema.sql` is tenant-only. (To unlock multi-currency on a lower tier, the one central edit is a
`JSON_SET` on `plans.features`, shown commented at the bottom of the SQL script.)

---

## Files

**Migration & seed**
- `database/migrations/tenant/2026_07_18_000001_add_multi_currency.php` — the two tables + three columns +
  seeds; `down()` fully reverses. Fresh-provision runs `CurrencySeeder` + `ForexLedgerSeeder` via
  `DatabaseSeeder`; an existing tenant seeds inside the migration.
- `database/seeders/CurrencySeeder.php` (INR base), `database/seeders/ForexLedgerSeeder.php` (Gain/Loss).
- `_docs/phase11_schema.sql` — the verified phpMyAdmin equivalent.

**Domain**
- `app/Services/ExchangeRateService.php` — `rateOn`, `rateOrNull`, `latestRates`, `history`/`historyCache`.
- `app/Services/ForexService.php` — the core: `enabled`, `deriveBase`, `verifyPayload` (the after-hook
  under key `forex`), `computeRealisedGainLoss`, `bookedRateForBill`, `revaluation`,
  `revaluationJournalLines`, `bootData`.
- `app/Models/{Currency,ExchangeRate}.php`; `currency_id`/forex fields added to `Ledger` + `VoucherEntry`.

**Client & UI**
- `app/Livewire/VoucherScreen.php` — forex validation rules, the `forex` after-hook, forex `bootData`,
  and `writeEntries()` writing the three columns. `resources/js/vouchers/screen.js` — dual-currency line
  entry, rate-on-date warning, base-total math. `resources/views/livewire/voucher-screen.blade.php` +
  `resources/css/vouchers.css` — the `zb-fx-*` foreign-line UI.
- `app/Livewire/CurrencyMaster.php` (`masters.currencies`) — add currency, make base, add rate.
- `app/Livewire/Reports/ForexRevaluation.php` (`reports.forex-revaluation`, Gateway `5`) — computes and
  posts the revaluation journal.

**Proof**
- `app/Console/Commands/ProveForexCommand.php` — `zerobook:prove-forex`, 62/0, the worked ₹14,000 loss,
  ₹10,000 gain and ₹7,000 unrealised loss above, plus the tamper-rejection and feature-gate cases.

---

## Out of scope (named, not stubbed)

- **Item-level foreign pricing.** Foreign amounts sit at the ledger line, not on individual stock items;
  a foreign *inventory invoice* prices the whole line, not per-item rate cards. A later phase can add a
  per-item foreign price list.
- **Automated rate feeds.** Rates are entered by hand in the currency master. A scheduled fetch from a
  published reference (e.g. RBI/central-bank rates) is deliberately deferred — the model is ready for it
  (`exchange_rates` is already a dated history), only the fetch job is not built.

Neither is stubbed: there are no placeholder functions or TODOs for them in the code.
