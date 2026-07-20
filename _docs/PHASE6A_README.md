# ZeroBook — Phase 6A: Inventory Masters (Units · Stock Groups · Godowns · Stock Items)

Adds the four inventory masters and the forward-compatible stock ledger. **Masters
and schema only** — no voucher posts stock movement yet, and no report reads it.
Mirrors the Phase 2 (Groups/Ledgers) pattern, and mirrors the **Phase 5E GST/VAT
regime relabeling** on the Stock Item tax fields exactly.

---

## Step 0 — audit & findings

**All six proofs PASS** (`prove-costcentre`, `prove-billwise`, `prove-gst`,
`prove-vat`, `prove-sales-purchase`, `prove-balance`), and `prove-vat` leaves the
company tax regime exactly as it found it (gst=OFF, vat=OFF).

**Note:** Phase 6A was genuinely absent from the codebase before this build (no
`stock_items`). The Phase 5E prompt's sequencing had assumed 6A existed; it does
now. The item's tax columns (`gst_rate`/`hsn_sac`) reuse the **rate seam**
(`gst_rate`) that VatService/GstService already read, so 6B consumes item-level
rates without a schema change.

**Conventions mirrored:** the Phase 2 self-referential hierarchy (`parent_id`
`restrictOnDelete`, unique name, `pathLabel()`, acyclic re-parent via
`descendantIds` + `Rule::notIn`); the Phase 5D standalone-workspace pattern (a
controller that does NOT touch the shared accounts `masterWorkspace`); and the
**5E ledger tax-field relabeling** (ledger-workspace.blade lines 109–135) cloned
verbatim onto the Stock Item form.

---

## The four masters — field maps & key bindings

All four use the same keyboard master shell (menu → **C**reate / **M**ultiple /
**D**isplay / **A**lter, `↑/↓` `Enter`, `Alt+D` delete, `Esc` back, `Ctrl+A`
accept), driven by a generic Alpine `inventoryWorkspace` (Units / Stock Groups /
Godowns) and a specialised `stockItemWorkspace`. Typing, filtering and picker use
are **100% client-side (0 network)** — only accept hits the server.

- **Units** — `name` (unique), `symbol`, `decimal_places` (0 = whole counts like
  "Nos", 2 = "Kg"; governs quantity rounding for that unit).
- **Stock Groups** — `name` (unique), `alias`, optional `parent_id` (nestable;
  acyclic re-parent enforced). `pathLabel()` = "Raw Materials ▸ Chemicals".
- **Godowns** — `name` (unique), optional `parent_id` (nestable). **"Main Location"
  seeded + reserved** (non-deletable; keeps its top-level position on alter).
- **Stock Items** — Tally field order: `name` → `alias` → **Under** (Stock Group,
  `zbSelect`, **Alt+C** creates the group inline) → **Unit** (`zbSelect`, **Alt+C**
  creates the unit inline) → **Opening**: `opening_qty` × `opening_rate` =
  `opening_value` (auto-computed live, editable — a manual value edit freezes the
  auto-compute rather than back-solving the rate) → **Opening Godown** (defaults to
  Main Location) → **tax** (`gst_rate`/`hsn_sac`, regime-relabelled — see below) →
  `reorder_level`. `costing_method` is fixed to `weighted_average` (the column
  exists so 6B's valuation reads it without a schema change; other methods are a
  later enhancement).

Inline **Alt+C** create is powered by the `CreatesStockGroups` / `CreatesUnits`
concerns (mirroring `CreatesGroups`/`CreatesLedgers`) + quick-create sub-screens,
returning to the item form with the new master **selected**.

---

## Regime-aware tax fields on Stock Items (mirrors the ledger form exactly)

The item's own `gst_rate`/`hsn_sac` columns are shown **only when GST or VAT is
enabled** and relabelled by the active regime — the identical Blade/Alpine
conditional the ledger form uses since Phase 5E:

| Company regime | Section | Rate label | HS-code label | Default rate |
|---|---|---|---|---|
| **GST** on | "GST Details" | "GST rate (%)" | "HSN / SAC" | — |
| **VAT** on | "VAT Details" | "VAT rate (%)" | "HS Code" | **13** (via `x-init`) |
| neither | *hidden* | — | — | — |

**Browser-verified:** with GST → "GST Details / GST rate (%) / HSN / SAC" (no
default); switch the company to VAT → the **same fields** become "VAT Details /
VAT rate (%) / HS Code" defaulting to **13**; with neither, the block is hidden —
identical to the ledger form. `GstService`/`VatService`/`VoucherScreen`/
`FeaturesScreen` were **not touched** — only the item form mirrors their pattern.

---

## `stock_entries` schema + `StockService` skeleton

`stock_entries` (migration `..._000005`) — forward-compatible, **nothing writes to
it this phase**: `voucher_id` (cascade), `stock_item_id`, `godown_id`, `direction`
(`in`/`out`), `quantity`/`rate` (`decimal 15,4`), `value` (`decimal 15,2`, stored
explicitly so it never drifts), `line_no`. Indexed `(stock_item_id)` + `(voucher_id)`.

`app/Services/StockService.php` mirrors `BalanceService`'s shape:
- `openingBalance($itemId)` → `{qty, rate, value}` from the item's opening columns.
- `closingBalance($itemId, $asOf)` → **returns the opening balance unchanged this
  phase** (no movement exists). The signature is the contract 6B extends — it folds
  in `stock_entries` movement (weighted-average) inside the body without changing
  any caller.

**Verified:** for a fresh item (opening 10 @ 50 = 500, no `stock_entries`),
`closingBalance()` returns exactly `{qty:10, value:500}` = the opening.

---

## Schema & migrations

Migrations `2026_07_08_000001..000005`: `units`, `stock_groups`, `godowns` (+ Main
Location seed), `stock_items`, `stock_entries`. Ready-to-run phpMyAdmin script
(incl. the seed) at **`_docs/phase6a_schema.sql`**.

---

## Acceptance — all self-verified in the browser

- ✅ Unit "Kg" (2 dp) and "Nos" (0 dp) created by keyboard; persisted, cache updated.
- ✅ Stock Group nested 2+ deep — "Raw Materials ▸ Chemicals ▸ Acids/Solvents" (multi-create).
- ✅ Godowns: "Main Location" seeded + reserved; "Rack A" sub-godown created ("Main Location ▸ Rack A").
- ✅ Stock Item created by keyboard (Cement OPC 50kg / Chemicals / Kg / opening 100 @ 450 = **45,000** auto-computed); opening value auto = qty × rate, and a manual override survives a later rate change.
- ✅ **Alt+C** creates a new Stock Group and a new Unit inline mid-flow, returning with each **selected** (verified end-to-end).
- ✅ **Regime-aware tax fields** relabel GST ↔ VAT ↔ hidden, matching the ledger form (default 13 under VAT).
- ✅ Display + Alter for all four; alter persists; **acyclic re-parent rejected** by the server (Raw Materials under its own descendant Chemicals → refused).
- ✅ Master cache includes units / stockGroups / godowns / stockItems and refreshes on create.
- ✅ `StockService::closingBalance()` == opening for a fresh item.
- ✅ 0 network during typing/filtering/picker use; no console errors.
- ✅ **Phases 1–5E not regressed** — all six `prove-*` pass and the regime is unchanged.

---

## Files

**New — models/services/concerns**
- `app/Models/{Unit,StockGroup,Godown,StockItem,StockEntry}.php`
- `app/Services/StockService.php`
- `app/Livewire/Concerns/{CreatesStockGroups,CreatesUnits}.php`

**New — Livewire + views**
- `app/Livewire/{Unit,StockGroup,Godown,StockItem}Workspace.php`
- `resources/views/livewire/inventory/{unit,stock-group,godown,stock-item}-workspace.blade.php`
- `resources/views/inventory/{index,units,stock-groups,godowns,stock-items}.blade.php`
- `resources/views/partials/{quick-stock-group,quick-unit}.blade.php`
- `app/Http/Controllers/InventoryController.php`

**New — client**
- `resources/js/masters/inventory.js` (`inventoryWorkspace` + `stockItemWorkspace`)

**New — schema**
- `database/migrations/2026_07_08_000001..000005_*.php` + `_docs/phase6a_schema.sql`

**Extended (additive; no behavioural change to existing masters)**
- `resources/js/masters/store.js` — inventory caches + generic `addTo`/`removeFrom`/`descendantsIn`/`seedInventory`
- `resources/js/masters/select.js` — generic source dispatch for inventory pickers
- `resources/js/app.js` — register inventory controllers
- `app/Support/Shell.php` — Inventory hub + masters in Go To / Gateway
- `routes/web.php`, `resources/css/masters.css`

---

## Not in 6A (later)
Item-invoice mode, inventory vouchers, weighted-average COGS (6B); Stock Journal /
Physical Stock + Stock Summary + Opening/Closing Stock in P&L/BS (6C). The item's
tax rate and `costing_method` are stored now and consumed by 6B.
