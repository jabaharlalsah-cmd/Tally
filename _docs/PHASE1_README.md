# ZeroBook — Phase 1: Keyboard Engine & Application Shell

Foundation phase. Builds the client-side keyboard engine and the *Gateway of
ZeroBook* shell that every later feature (masters, vouchers, reports) depends
on. **No masters, vouchers, or reports are built here.** Everything in the
interaction loop is 100% client-side (Alpine.js) — a keystroke never waits for
the server.

Built from scratch in `C:\laragon\www\tally` — a standalone application,
independent of the reference `accounting` app. ZeroBook's own brand identity
(deep evergreen `#0B6E4F`, Inter + Spectral) is applied throughout.

---

## 1. Stack

| Piece      | Version        |
|------------|----------------|
| Laravel    | 13.18          |
| Livewire   | 4.3 (ships Alpine.js, auto-started) |
| Tabler     | 1.4 (Bootstrap 5 base) |
| Vite       | 8              |
| Fonts      | Inter + Spectral (self-hosted via @fontsource) |
| PHP / Node | 8.3 / 22       |
| Database   | MySQL, schema `tally` |

---

## 2. How to run

```bash
# from C:\laragon\www\tally
composer install
npm install
npm run build          # or: npm run dev   (Vite HMR)

# .env already points at MySQL schema `tally` (created during setup)
php artisan migrate    # stock Laravel tables only — see §7 (no Phase-1 schema)

php artisan serve --host=127.0.0.1 --port=8777
```

Then open:

- **Gateway hub:** <http://127.0.0.1:8777/>
- **Keyboard verification harness:** <http://127.0.0.1:8777/dev/keyboard-harness>

Chrome recommended. Use it entirely without a mouse.

### Verifying the engine (the harness)

`/dev/keyboard-harness` is a standalone screen that exercises every engine
feature before any real feature exists:

- **Party Details** form — press **Enter** to chain fields; **Ctrl+A** commits
  from any field.
- **Alt+L** → *Ledger List* (level 1) → **↑/↓** to move, **Enter** to open a
  ledger → *Ledger Detail* (level 2) → **Alt+A** → *Address Entry* (level 3).
- **Esc** pops each level and restores focus to exactly where you were.
- The right button bar and bottom status bar always reflect the active context.
- A live **Engine event log** (right column) traces every context push/pop,
  field move, commit, and handled keystroke.

---

## 3. Deliverables → where they live

| # | Deliverable | Files |
|---|-------------|-------|
| A | Global keyboard dispatcher + (context,key)→action registry | `resources/js/engine/engine.js` (`dispatch()`, `resolve()`, `registry`), `resources/js/engine/keys.js` |
| B | Context-stack manager (push/pop/peek, Esc-pop, focus restore) | `engine.js` (`pushContext/popContext/escape/_focusInto`) |
| C | Field-chaining (Enter) + whole-form commit (Ctrl+A) + arrow list nav | `engine.js` (`fieldAdvance`, `commitNearestForm`), per-context arrow actions |
| D | Gateway of ZeroBook shell (top / centre / right / bottom regions + hub) | `resources/views/layouts/app.blade.php`, `resources/views/gateway.blade.php`, `resources/css/shell.css` |
| E | Context-aware right button bar | `layouts/app.blade.php` (`barGroups()` render), `engine.js` (`barGroups`) |
| F | Bottom status bar (Quit/Accept + calculator/keyboard status) | `layouts/app.blade.php` footer |
| G | Calculator pane (Ctrl+N, safe arithmetic, keyboard-operable) | `resources/views/partials/calculator.blade.php`, `resources/js/engine/components.js` (`zbCalc`), `resources/js/engine/calculator.js` |
| H | Go To universal navigator (Alt+G) | `resources/views/partials/goto.blade.php`, `components.js` (`zbGoto`), destinations in `app/Support/Shell.php::nav()` |
| I | Browser key-interception + documented fallbacks | `keys.js` (`HARD_BLOCK`, `UNCAPTURABLE`), `engine.js` dispatcher — see §6 |
| J | Demo/verification harness at `/dev/keyboard-harness` | `resources/views/dev/keyboard-harness.blade.php`, `resources/js/harness.js`, `app/Http/Controllers/Dev/KeyboardHarnessController.php` |

Supporting: `app/Support/Shell.php` (server-side shell data — identity, working
date/period, Go To list, Gateway menu), `routes/web.php`,
`resources/css/{app,tokens,base,shell}.css`, `resources/js/app.js`.

---

## 4. Architecture (the non-negotiables)

- **One dispatcher.** A single `keydown` listener on `window` (capture phase),
  attached once in `attachDispatcher()`. It normalises the event to a canonical
  combo (`ctrl+a`, `alt+g`, `arrowdown`, `f2`…), consults the active context,
  and dispatches. No per-component key listeners competing.
- **Context stack.** `Alpine.store('zb').stack` is a stack of context objects,
  each carrying its own `(key → action)` map, a `focusEl`, and an `onPop`
  cleanup. Opening a sub-screen **pushes**; **Esc pops**. On pop, focus is
  restored to the element that was focused when that context was pushed —
  verified through 3 nested levels.
- **Resolution order:** active context → (static per-context registry) →
  `global`. Globals (Go To, Calculator, Change Date, Accept, Enter, Esc) work
  everywhere unless a nearer context overrides them.
- **Field chaining.** Each field is `[data-zb-field]` inside a `[data-zb-form]`.
  Enter advances to the next field (or an explicit `data-zb-next`); on the last
  field it commits. **Ctrl+A** commits the nearest enclosing form from any
  field, including inside a sub-screen.
- **Self-documenting bars.** The right button bar and bottom bar are pure
  renders of `barGroups()` / store state for the current context, so they are
  always correct and update automatically as context changes.
- **No server in the loop.** Livewire/MySQL are present for later phases; the
  keyboard loop performs **zero** network requests (verified — see §8).
- **Extensible bindings.** New keys register per context via
  `pushContext({ actions:[{key,label,run}] })` or by adding to the `global`
  set — no engine rework needed. F4–F9, F11, Alt+C, etc. slot in later.

---

## 5. Current key → action map

### Anywhere (global)
| Key | Action |
|-----|--------|
| `Enter` | Accept field / advance to next field (commit on last) |
| `Esc` | Back — pop context; at base shows a "Quit ZeroBook?" prompt |
| `Ctrl+A` | Accept/commit the whole current form, from any field |
| `Alt+G` | Go To universal navigator |
| `Ctrl+N` | Toggle calculator pane (fallback: **Alt+N**) |
| `F2` | Change date / period (stub picker; full logic in Phase 4) |

### Gateway hub
| Key | Action |
|-----|--------|
| `↑` / `↓` | Move menu highlight |
| `Enter` | Select highlighted item |
| `H` `G` `C` `D` | Highlighted-letter jump to Harness / Go To / Calculator / Date & Period |

### Harness — base
| `Alt+L` | Open *Ledger List* sub-screen |

### Ledger List (level 1) — `↑`/`↓` move · `Enter` open · `Esc` back
### Ledger Detail (level 2) — `Alt+A` add address · `Ctrl+A` accept · `Enter` chain · `Esc` back
### Address Entry (level 3) — `Ctrl+A` accept · `Enter` chain · `Esc` back
### Calculator — type expression · `Enter` add to tape · `Esc`/`Ctrl+N` close · operators `+ − × ÷ % ( )`
### Go To — type to filter · `↑`/`↓` move · `Enter` open · `Esc` close
### Period picker — `Enter` advance/apply · `Ctrl+A` accept · `Esc` cancel

---

## 6. Browser-reserved keys — captured vs. fallbacks (Deliverable I)

**Captured (we `preventDefault`, browser default suppressed):** verified
`defaultPrevented === true` in Chrome for
`F1, F2, F3, F4, F5, Ctrl+F5, Ctrl+S, Ctrl+P, Alt+G, Ctrl+N` (see
`keys.js::HARD_BLOCK`). `F6` is attempted best-effort.

**Cannot be reliably captured in a normal browser tab — working alternates
provided so the workflow never dead-ends** (`keys.js::UNCAPTURABLE`):

| Reserved key | Browser owns it for | ZeroBook alternate |
|--------------|---------------------|--------------------|
| `Ctrl+N` | New window | **`Alt+N`** — bound to the same calculator toggle. `Ctrl+N` is also wired and is captured in this Chrome build, but if a browser/OS opens a new window instead, `Alt+N` always works. |
| `F11` | Fullscreen | Not required by any Phase-1 workflow. A dedicated fullscreen action (**Alt+F**) will be wired when the feature needs it; nothing dead-ends without it. |
| `F12` | DevTools | Not used by the app. |
| `F6` | Focus address bar | The "jump anywhere" need it would serve is covered by **`Alt+G`** (Go To). |
| `Ctrl+W` / `Ctrl+T` | Close / new tab | Not used by the app. |

Rationale: a keystroke must never leave the user stuck. Where the browser wins,
an in-app alternate delivers the same capability.

---

## 7. UX standing rules (applied globally, all future work)

- **Number inputs** have their up/down spinner arrows removed
  (`base.css`; verified `-moz-appearance: textfield`). **Select** elements keep
  their dropdown arrow.
- **Every text/number input auto-selects its full content on focus** — a single
  global `focusin` handler in `engine.js` (verified: an 11-char field reports
  `selectionStart=0, selectionEnd=11`). Opt out with `data-zb-noselect`.
- **Brand only.** Deep evergreen `#0B6E4F`, Inter + Spectral. No third-party
  product's name, logo, or colours appear anywhere in the UI.

---

## 8. Schema / migrations

**Phase 1 introduces no schema changes.** It is UI/interaction foundation only.
The database contains just the stock Laravel scaffolding tables
(`users`, `cache`, `jobs`, `sessions`) from `database/migrations/0001_01_01_*`.
There is therefore no Phase-1 migration or phpMyAdmin SQL script to ship. When
masters/vouchers arrive (Phase 2+), their migration **and** a ready-to-run
`_docs/*.sql` will be added per the delivery rules.

---

## 9. Acceptance checklist — self-verified in Chrome

All checks were run programmatically against `/dev/keyboard-harness` and the
Gateway (dispatching real `keydown` events, reading engine state, and spying on
`fetch`/`XHR`). Results:

| # | Criterion | Result |
|---|-----------|--------|
| 1 | Every listed key works from the harness, zero mouse | ✅ Enter, Esc, Ctrl+A, Alt+G, Ctrl+N, arrows, F2, hot-letters, Alt+L/Alt+A |
| 2 | Esc pops ≥3 nested sub-screens, restoring focus each time | ✅ depth 4→3→2→1; focus restored to `h-detail-name` → `h-ledger-list` → `h-name` |
| 3 | Ctrl+A commits the form from any field, incl. sub-screen | ✅ committed from `h-amount` (base) and `h-addr-city` (depth 4) |
| 4 | Enter advances every field; arrows navigate lists/menus | ✅ name→alias→amount→type→narration; list & Gateway arrow-nav |
| 5 | No keystroke triggers a network request | ✅ 0 fetch/XHR across a 12-key burst |
| 6 | Right button bar updates automatically as context changes | ✅ base → Ledger List → Ledger Detail groups differ correctly |
| 7 | Calculator toggles with Ctrl+N and computes by keyboard | ✅ `1200*18/100=216`, `2+3*4=14`, `200*5%=10`, `5/0`→"Divide by zero"; tape works |
| 8 | Alt+G opens Go To, fully keyboard-operable | ✅ opens, filters ("harn"→Keyboard Harness), ↑/↓, Enter, Esc |
| 9 | Browser-reserved keys captured or have working alternates; documented | ✅ F1–F5/Ctrl+S/Ctrl+P captured; Ctrl+N↔Alt+N; see §6 |
| 10 | Runs in Chrome with no console errors; degrades gracefully | ✅ no console warnings/errors observed |
| 11 | Existing features not overwritten/duplicated | ✅ N/A — fresh standalone app; the `accounting` app is untouched |
