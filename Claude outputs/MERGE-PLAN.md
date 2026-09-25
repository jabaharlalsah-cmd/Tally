# ZeroBook — Merge Plan: bring the `tally` modules into the approved `New Account Software`

**Date:** 2026-09-09
**Owner:** NS Learning (Dibi Tech Private Limited)
**Status:** Proposed — waiting for owner approval on the three decisions at the end.

---

## 1. What we are doing, in one paragraph

The client approved **New Account Software** (NAS) for its UI, keyboard flow and accounting core, but NAS only has
the accounting system. The older **tally** project was rejected (UI, bugs, keyboard problems) but it already
contains the tax, invoicing, inventory, API and other modules. We will make a **copy of NAS** and, module by
module, **move the tally features into that copy** — keeping NAS's screens, keyboard engine, database design
and hosting setup. Neither original folder is ever changed.

---

## 2. What each project is today

| | `D:\laragon\www\New Account Software` (NAS) — **approved** | `D:\laragon\www\tally` — **rejected** |
|---|---|---|
| Backend | Laravel 13, PHP 8.3 | Laravel 13, PHP 8.3 |
| Screens (UI) | **Inertia + Vue 3 + TypeScript + Tailwind 4** — 239 Vue files | **Livewire 4 + Blade + Alpine + Tabler** — 158 Blade views, 61 Livewire components |
| Multi-company / tenancy | One database; `company_id` on every accounting table; `tenant_id` on companies/users only (row-level). Works on Hostinger shared hosting. | Separate database per tenant (`stancl/tenancy`). Needs wildcard subdomain + `CREATE DATABASE` — Hostinger shared hosting cannot do this. |
| Financial year | **None** — continuous books, reports for any period | Every voucher carries `fy_start`; FY-based numbering |
| Voucher types | Proper `voucher_types` master (numbering, prefix/suffix, restart, hotkey…) | Fixed `type` enum column |
| Accounting core | Masters, all 10 voucher types, bill-wise, BRS, Day Book, Ledger, TB, BS, P&L, Cash/Funds Flow, Ratio Analysis, Columnar, Registers, Outstanding + ageing, Exception reports, Audit log, Backup/Restore, Tally XML import/export, Bank-statement PDF import, Platform admin, OTP/2FA/passkeys | Masters, vouchers, TB, BS, P&L, Day Book, Outstanding, Ledger |
| Extra modules | — | **Inventory** (units, stock groups, godowns, items, lots, weighted-average COGS), **Invoicing** (item/accounting invoices, orders, delivery/receipt notes, debit/credit notes with items, invoice print), **GST** (GSTR-1/3B JSON), **Nepal VAT** (IRD format), **TDS** (sections, challans, Form 26Q FVU), Cost centres, Multi-currency + forex revaluation, Budgets, Scenarios, Company groups + consolidation, **REST API v1** (API keys, idempotency, webhooks), Sync, SaaS billing (plans/payments/subscription) |
| Automated tests | 73 PHPUnit files + Vitest suite | 3 PHPUnit files (verification was via artisan "proof commands") |
| Code size | 85 services · 25 models · 46 migrations | 58 services (16 k lines) · 54 models · 61 migrations |
| Live? | Yes — zerobook.in, branch `main` | No |

---

## 3. Why a straight copy / git merge is not possible

1. **Different screen technology.** tally screens are Blade + Livewire; NAS screens are Vue components.
   A Blade file cannot be dropped into NAS. Every tally screen has to be **re-made as a Vue page** using
   NAS's console components — that is where most of the work is.
2. **Different database shape.** tally = one database per tenant with a financial year; NAS = one shared
   database, `company_id` everywhere, no financial year, a real `voucher_types` table. Column names differ
   too (`line_no` → `line_order`, `maintain_bill_by_bill` → `maintain_bill_wise`, `AccountGroup` → `Group`, …).
3. **Unrelated git histories.** The two repos share no commits, so `git merge` would produce thousands of
   conflicts with nothing useful in them.

What **does** carry over well: the PHP business logic (services, models, migrations, the GSTN / IRD / Form 26Q
schemas and formatters, the stock-costing maths, the API layer). That is the expensive, hard-to-get-right part,
and it exists. We port it with a small set of adapter rules (Section 8) instead of writing it again.

---

## 4. The way we will do it (recommended): NAS copy is the base, tally is the parts bin

**Base = copy of NAS.** Reasons, in order of importance:

- The client approved **this** UI and keyboard flow. Starting from tally and re-skinning it (the July
  "NAS-parity plan") means rebuilding 158 Blade screens *and* keeping the codebase the client already
  rejected for bugs.
- NAS's database design works on the hosting we actually have; tally's does not.
- NAS is already live at zerobook.in with a real tenant. The merged product becomes the next release of the
  same system — same URLs, same data, no migration of the client's books.
- NAS has a real test suite. Each ported module gets tests as it lands, which is how we avoid re-importing
  tally's bugs.

The **alternatives considered and rejected**:

| Option | Why not |
|---|---|
| tally as base, replace its UI with NAS's | Same amount of UI work, but you end up on the buggy, rejected, per-tenant-database codebase. |
| Run both apps side by side | Two logins, two databases, double data entry. Client rejected tally's UI anyway. |
| Copy tally files into NAS as-is | Blade/Livewire cannot run inside Inertia/Vue; schemas don't line up. Nothing would work. |

---

## 5. Ground rules for the new folder

| Rule | Detail |
|---|---|
| **Three folders, one writable** | `New Account Software` = frozen, read-only (the approved snapshot; still the live deploy source until the merged product replaces it). `tally` = frozen, read-only, **reference source for porting**. **New folder** = the only place we write. |
| **New folder name** | Proposed: `D:\laragon\www\zerobook` (owner to confirm). |
| **How the copy is made** | `git clone "D:\laragon\www\New Account Software" "D:\laragon\www\zerobook"` — keeps the full git history so we can see why every line exists. Then `composer install`, `npm install`, copy `.env` and edit it. The original is not touched by a clone. |
| **Database** | New database `zerobook` (owner to confirm). Fill it from a **fresh `mysqldump` of `nslp_accounting`** so real books are available for testing. Never point the new folder at `nslp_accounting` itself (that rule already exists in NAS's CLAUDE.md for good reason). |
| **Deploy scripts** | `deploy.config` in the copy must be **disabled/renamed** on day one. The copy must not be able to deploy to zerobook.in until the merged product is signed off. |
| **Git branches** | `main` stays as cloned. All work on `work/<phase-name>` branches. Merging to `main` is the owner's manual step. No `git push`, no deploy. |
| **CLAUDE.md in the new folder** | Rewritten: new scope (inventory / invoicing / tax / API are now IN scope), tally becomes the allowed read-only reference (NAS's old "never read tally" rule is lifted for the new folder only), the `nslp_accounting` protection stays, standing QA-then-commit rules stay. |
| **Feature flags** | Every ported module sits behind an F11 company feature (NAS already has `CompanyFeatureRegistry`). Off by default, so the approved accounting experience is unchanged until a company turns a module on. |

---

## 6. What we will NOT port from tally (NAS already has it, or it isn't needed)

| tally module | Decision |
|---|---|
| TallyImport (XML importer) | Skip — NAS `TallyExchange` does import **and** export. |
| Backups / Exports / Offboarding | Skip — NAS `Backup` + `Support/Export` cover it. |
| RatioService + RatioDashboard | Skip — NAS has `RatioAnalysisService`. (tally's **ratio thresholds/alerts** can be added later as a small extra.) |
| Tenancy / Platform admin / Signup / Impersonation / Hostinger provisioning | Skip — NAS has its own tenancy + admin panel, built for the hosting we use. |
| Subscription / Plans / Payments (SaaS billing) | **Optional, last.** Only if ZeroBook is to be sold as a SaaS. Not needed for the client. |
| Sync (push/pull) | **Optional.** Decide after the API lands. |
| BalanceService / BillService | Skip — NAS `LedgerBalanceService`, `BillAllocation`, `OutstandingService` are the versions to keep. |

---

## 7. Porting phases (in dependency order)

Each phase = one work branch, ported logic + new Vue screens + tests + phpMyAdmin SQL for every migration,
QA'd end-to-end, then handed to the owner for review before the next phase starts.

| # | Phase | What moves over from tally | Why this order | Size |
|---|---|---|---|---|
| 0 | **Set up the new folder** | Clone, new DB from dump, `.env`, disable deploy, rewrite CLAUDE.md, run the full NAS test suite as the baseline, one smoke test in the browser. | Everything else depends on it. | S |
| 1 | **Inventory masters + stock ledger** | `Unit`, `StockGroup`, `Godown`, `StockItem`, `StockEntry`, `StockLot`, `StockService`, `StockLotService`; Stock Summary, Stock Item movement, Lot ledger / provenance reports; F11 flag "Maintain inventory". | Invoicing needs stock items. Self-contained tables — lowest-risk first port. | L |
| 2 | **Invoicing** | Sales/Purchase in **invoice mode** (item invoice + accounting invoice), orders (`OrderLine`, `OrderFulfillment`, `OrderService`), delivery/receipt notes, debit/credit notes with items, Notes register, Orders outstanding, invoice **print** layout. NAS vouchers already have `party_ledger_id` + `reference` — we extend, not replace. | Builds on Phase 1; this is the client's most visible missing feature. | L |
| 3 | **Tax** | **GST** (tax ledgers, rates, HSN/SAC, GST Summary, GSTR-1/3B JSON via `gstn-schemas`), **Nepal VAT** (IRD format, VAT return), **TDS** (sections master, deductions, challans, deductee YTD, TDS Summary, Form 26Q FVU via `Tds/*`). Each behind its own F11 flag. | Needs invoice mode for GST/VAT. TDS is nearly standalone and can be split out if wanted sooner. | L |
| 4 | **Cost centres + Multi-currency** | `CostCentre`, `CostAllocation`, Cost breakup reports; `Currency`, `ExchangeRate`, `ForexService`, forex revaluation report. | Both touch every voucher line, so they come after the voucher screens have settled. | M |
| 5 | **REST API v1 + webhooks** | `ApiKey*`, `ApiRequestLog`, `ApiIdempotencyKey`, `Webhook*`, `Services/Api/*`, `Controllers/Api/V1/*`, API-keys and Webhooks screens. | Needs final shape of vouchers, ledgers and stock items. | M |
| 6 | **Planning & groups** | Budgets (`Budget*`, `BudgetService`, variance report), Scenarios (`Scenario*`), Company groups + consolidation (`CompanyGroup`, `GroupConsolidationService`, `InterCompanyService`). | Nice-to-have analytics; nothing else depends on them. | M |
| 7 | **Optional** | Sync push/pull; SaaS billing (plans/payments/subscription); ratio thresholds. | Only if the owner wants them. | S–M each |

S = small, M = medium, L = large. The large phases are large because of the Vue screens, not the PHP.

---

## 8. Porting rules (the "adapter" every ported file passes through)

1. **`company_id` on every table and every query.** tally's later `add_multi_company` migration already added it
   to most tables — keep it, and add NAS's composite indexes/uniques (`company_id` + name, etc.).
2. **No financial year.** Drop `fy_start` and any "carry forward". Anything that was FY-based becomes a
   period the user chooses (F2 / Alt+F2). Statutory periods (GST months, TDS quarters, Apr–Mar) are
   **report periods**, not book closes.
3. **Voucher types come from NAS's `voucher_types` table**, never from tally's `type` enum. New base types
   (sales order, delivery note, receipt note, stock journal, physical stock, …) are added as seeded voucher
   types with their TallyPrime hotkeys.
4. **Rename to NAS names**: `AccountGroup` → `Group`, `line_no` → `line_order`, `maintain_bill_by_bill` →
   `maintain_bill_wise`, `bank_account_no` → `bank_account_number`, `address` → `address_line1/2`, and so on.
   A one-page mapping table is written in Phase 0 and kept in `_docs/`.
5. **UI is rebuilt, never copied.** Each tally Blade/Livewire screen becomes a Vue page built from NAS's
   existing console components (button bar, ledger picker, F-key bindings, Alt+C create-on-the-fly,
   drill-down). Keyboard bindings go through NAS's central shortcut table only.
6. **F11 feature flags** via `CompanyFeatureRegistry`; menus and shortcuts appear only when the flag is on.
7. **Tests with every module.** tally's logic arrives with almost no tests; we write PHPUnit tests for the
   service layer and Vitest tests for the screens before the phase is called done. NAS's existing suite must
   stay green after every phase.
8. **Every migration ships with a phpMyAdmin-ready `.sql` in `_docs/sql/`**, and forward-only — no
   `migrate:fresh` anywhere near real data.
9. **Dibi Tech UI standards** apply as usual (no number spinners, ₹ on amounts, auto-select on focus,
   gear-icon master management, eye-icon on passwords).

---

## 9. Decisions needed from the owner before Phase 0 starts

1. **Approve the direction** — copy of NAS as the base, tally as the read-only parts bin (Section 4).
2. **Folder and database names** — proposed `D:\laragon\www\zerobook` and database `zerobook`.
3. **Module scope** — port Phases 1–6 as listed? And for Phase 7: do you want Sync and/or SaaS billing at all?
