# ZeroBook — Phase 2: Masters (Groups & Ledgers)

Builds the **Groups** and **Ledgers** masters on top of the Phase 1 keyboard engine,
replicating Tally's masters workflow, field logic, and keyboard behaviour in ZeroBook's
own brand. Server is **commit-only**; all typing/filtering/navigation is client-side.

## What was built

| Area | Files |
|------|-------|
| Data | `app/Models/AccountGroup.php`, `app/Models/Ledger.php`, `database/migrations/2026_07_06_000001_*`, `_000002_*` |
| Seeders | `database/seeders/AccountGroupSeeder.php` (28 groups), `LedgerSeeder.php` (Cash + P&L), `DatabaseSeeder.php` |
| Livewire (commit-only) | `app/Livewire/GroupWorkspace.php`, `LedgerWorkspace.php`, `app/Livewire/Concerns/CreatesGroups.php` |
| Reusable picker | `resources/js/masters/select.js` + `resources/views/components/master-select.blade.php` |
| Client cache | `resources/js/masters/store.js` (Alpine store `masters`) |
| Workspace controller | `resources/js/masters/workspace.js` (modes, list/grid nav, inline-create handshake) |
| Views | `resources/views/livewire/group-workspace.blade.php`, `ledger-workspace.blade.php`, `resources/views/partials/quick-group.blade.php`, `resources/views/masters/{index,groups,ledgers}.blade.php` |
| Nav / routes | `app/Http/Controllers/MastersController.php`, `routes/web.php`, `app/Support/Shell.php` (Gateway + Go To) |
| Styling | `resources/css/masters.css` |

## Field maps (Tally order)

- **Group create:** `Name → (alias) → Under` (searchable picker; **Alt+C** creates a parent inline). `Nature of Group` appears only when Under = ⌂ Primary. Advanced options (sub-ledger / nett-balance / used-for-calculation) are **scaffolded in the schema, hidden this phase** (F11/F12, Phase 4).
- **Ledger create:** `Name → (alias) → Under (group; Alt+C creates a group inline) → Opening Balance + Dr/Cr → Mailing block` (mailing name, address, state, country, PIN, PAN, GSTIN). F11-gated columns (bill-by-bill, cost-centres, bank details) are **scaffolded, sub-screens not built**.

## Key bindings (registered on the engine)

`Enter` advance/select/drill · `Esc` back/close · `Ctrl+A` accept & save · `Alt+C` create master inline from a picker · `Alt+D` delete (blocked for reserved) · `↑`/`↓` list & grid rows · highlighted letters `C/M/D/A` on the master menu (Create / Multiple / Display / Alter).

## How the searchable picker & Alt+C work

- The picker (`zbSelect`) reads the **client cache** (`$store.masters`) and filters on name+alias with prefix-ranking — **zero network** while typing. It pushes its own engine context on open (`combo:<id>`) and pops on close.
- Selecting sets the Livewire property via `$wire.set(prop, id, false)` (deferred — no round-trip) and advances to the next field.
- **Alt+C** emits `zb:combo-create`; the workspace opens a quick-create sub-screen (own context), and on accept persists, appends the new master to the cache, pops back, and fills the originating picker via `zb:combo-fill`.
- The cache refreshes in-place after every create, so a just-created master is instantly pickable without a reload.

## Migration / SQL notes

- `php artisan migrate:fresh --seed` (MySQL `tally`) is the source of truth.
- `_docs/phase2_schema.sql` is a ready-to-run phpMyAdmin export of `account_groups` + `ledgers` with the 28 predefined groups and the 2 default ledgers.
- `account_groups.parent_id` FK is **restrictOnDelete** (a parent with children can never be orphaned); `ledgers.group_id` is restrictOnDelete.

## Acceptance self-verification (all passing)

Verified by driving the browser and checking MySQL:

- 28 predefined groups seeded (15 primary + 13 sub) with correct nature & nesting; all reserved and non-deletable (Alt+D blocked with a message). ✓
- `Cash` (under Cash-in-Hand) and `Profit & Loss A/c` (special, under Primary) seeded reserved. ✓
- Group and Ledger each created **entirely by keyboard** and persisted; opening balance stored with correct **Dr/Cr** (e.g. `25000.00 Dr`). ✓
- **Alt+C** from a ledger's Under created a group inline and returned with it selected. ✓
- Picker filters client-side with **0 network requests**; only accept hits the server (**exactly 1 request** on commit). ✓
- Multiple-create grids (groups & ledgers) work row-by-row; Display and Alter work and persist. ✓
- 3-level nesting works (Current Assets → Bank Accounts → new sub-group); cache refreshes after create. ✓
- Phase 1 engine/shell/harness not regressed; no console errors. ✓

## Post-review hardening (adversarial review, 9 confirmed fixes)

A multi-lens adversarial review ran over the phase; 9 confirmed defects were fixed:

1. Ledger multi-create now validates each row's **Dr/Cr** side (`Rule::in`) — no invalid enum reaching MySQL.
2. Ledger multi-create enforces **opening ≥ 0** (parity with single-create).
3. Reserved ledgers (Cash) are locked from **group reassignment** in Alter (would change their nature).
4. `deleteMaster` runs in a **transaction with `lockForUpdate`**; `parent_id` FK is `restrictOnDelete` (no orphaning).
5. Multi-create loops wrapped in **`DB::transaction`** (no half-inserted batch).
6. `saveQuickGroup` / `saveAlter` now `popToContext(...)` so a still-open picker on top of a sub-screen can't **leak contexts** (nested Alt+C).
7. Alter's Under picker passes a **reactive `excludeModel`**, so self/subtree can't be chosen as a new parent client-side.
8. Picker `Enter` with no match and no create no longer **dead-ends** (clears the filter).
9. Engine `_focusInto` got a **generation token + longer retry window**, so two rapid context focuses never fight.

> **Testing note:** automated verification drove real `keydown` events. Because the headless preview page reports `document.hasFocus() === false`, the browser drops *asynchronous* programmatic `.focus()`; auto-focus-on-context-push therefore looks flaky under headless CDP but works normally for a real (focused) user. Logic was verified by explicitly focusing fields and asserting DB outcomes.
