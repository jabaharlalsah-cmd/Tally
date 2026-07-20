# ZeroBook → production: New Account Software parity plan

**Status:** Phase 0 complete. Phase 1 awaiting owner approval.
**Owner:** NS Learning (Dibi Tech Private Limited)
**Started:** 2026-07-20

---

## What this project is

ZeroBook (`C:\laragon\www\tally`) becomes the production system. It receives:

1. The **screen arrangement** of the client-approved build, New Account Software
   (`C:\laragon\www\New Account Software`, referred to below as **NAS**) — layout, menu order,
   field order, report grouping only.
2. **ZeroBook's own branding** — deep evergreen `#0B6E4F`, Inter + Spectral, Tabler. No second
   CSS framework, no NAS colours or fonts.
3. A **cross-platform keyboard engine** that works on Windows and macOS, in Chrome, Safari,
   Edge and Firefox.
4. **TallyPrime 7.x web behaviour parity.**
5. A **zero-known-bug audit** before delivery.

**NAS is READ-ONLY.** Nothing in this project ever writes to it.

---

## Reference sources (resolved)

The original brief carried a `<<FILL IN>>` placeholder for the NSLP shortcut map. It resolves to
NAS, which *is* NSLP Cloud Accounting. The authoritative files:

| Purpose | File |
|---|---|
| Shortcut map (authoritative; wins over any other doc) | `NAS/_docs/keyboard-shortcuts.md` |
| Same map as working code | `NAS/resources/js/keyboard/shortcut-table.ts` |
| Keyboard engine to port | `NAS/resources/js/keyboard/{manager,platform,types,dirty,status}.ts` |
| Engine tests to port | `NAS/resources/js/keyboard/{manager,single-listener,status}.test.ts` |
| Menu hierarchy / report grouping | `NAS/_docs/menu-navigation-map.md` |

---

## Key finding: the two apps are different shapes

NAS is **not** a subset or superset of ZeroBook. Each has substantial screens the other lacks.

### In NAS, missing from ZeroBook (~19 screens)

Concentrated in the accounting core:

Bank Reconciliation · Cash Flow · Funds Flow · Columnar multi-period · Exception Reports ·
Analysis & Verification · Group Summary · Cash & Bank Book · per-type Registers (Sales, Purchase,
Journal, Contra, Payment, Receipt, Debit Note, Credit Note) · Voucher Type master ·
List of Accounts · Create/Alter chooser · financial-year carry-forward · in-app user management ·
Profile / Security / Appearance / Shortcuts settings screens · Confirm Password · 2FA challenge.

### In ZeroBook, absent from NAS (~40 screens)

NAS **deliberately excluded** these — its own spec puts inventory, cost centres and all
tax/statutory work out of scope:

All inventory (5 masters + 4 stock reports + 8 order/stock voucher types) · GST · Nepal VAT ·
TDS · cost centres · budgets · scenarios · forex/multi-currency · company-group consolidation ·
notes register · the entire SaaS / multi-tenant / API / webhook layer.

There is therefore **no approved arrangement to copy** for these 40 screens. They follow NAS's
structural conventions, placed where real TallyPrime puts them.

---

## Decisions taken (owner-approved 2026-07-20)

| # | Decision | Choice |
|---|---|---|
| 1 | The ~19 NAS screens missing from ZeroBook | **Build all 19.** Full parity with what the client signed off on. |
| 2 | ZeroBook's ~40 extra screens in the rearranged Gateway | **Show, feature-flagged off by default.** Gateway matches the approved app out of the box; each module appears when switched on in Company Features (F11), which is Tally's own behaviour. |
| 3 | Browser-reserved keys (F11, F12, Ctrl+N, F5) | **Add PWA install**, matching NAS. Near-complete Tally key fidelity on Mac and Windows once installed; button-bar fallbacks in a plain tab. |

### Consequence of decision 2 to watch

`ProveMultiCompanyCommand` hard-codes the expected `company_features` flag set in an assertion.
Feature-flagging the extra modules **will** add flags and break that proof until the expected
array is updated. This is known, not a regression.

---

## Root cause of the macOS rejection (verified, not assumed)

`resources/js/engine/keys.js:56` builds a key signature that emits `meta+a` when a Mac user
presses ⌘A. A grep across the whole codebase for bindings registered as `meta+…` returns **zero
matches**, and there is no Ctrl↔Cmd equivalence anywhere in the engine.

**Every Ctrl-based Tally shortcut is therefore dead on macOS**, because Mac users press ⌘ and the
app only listens for Ctrl. ⌘A (save voucher), ⌘Q, ⌘H and the rest silently do nothing.

The existing engine does already carry a macOS fix for Option-key composition
(`keys.js:33-45`) — so this was partially addressed, but the Cmd gap was not.

### Why port rather than rebuild

NAS's engine is 2,055 lines including 488 lines of passing unit tests, and already handles every
hard case: IME composition and dead keys (Indic IMEs), AltGr layouts, physical-`e.code` matching
with an exact modifier mask, Cmd-as-Ctrl via an opt-in `ctrlOrCmd` flag, an OS-reserved Cmd set
that passes through untouched, a permanent F5 / Ctrl+R reload guard, focus-aware dispatch, a
scope stack with opaque modals, and an HMR-safe singleton listener.

It is also the *approved* implementation. Porting it — translated from TypeScript/Vue to
ZeroBook's JavaScript/Alpine — is lower risk than rebuilding and keeps behaviour identical to
what the client accepted.

---

## Phase plan

| # | Phase | Deliverable | Owner-visible check |
|---|---|---|---|
| 0 | **Groundwork** | git repo + baseline commit, debug leftovers removed, Playwright installed, 34-proof baseline recorded | Nothing visual — a rollback point |
| 1 | **Keyboard engine** | Port NAS engine + shortcut table + unit tests; Ctrl↔Cmd, reserved-key policy, reload guard; PWA manifest + install prompt; ⌘/⌥ hints on Mac | Shortcuts work on a Mac; help screen shows the right symbols |
| 2 | **Shell & Gateway** | 4-section hierarchy (Masters / Transactions / Utilities / Reports), right-hand button bar, top menu bar, Go To; extras feature-flagged | Home screen matches the approved app, in ZeroBook green |
| 3 | **Masters** | Field order, grouping, labels; + Voucher Type master, List of Accounts, Create/Alter chooser | Master screens match the approved arrangement |
| 4 | **Vouchers** | Tally field order, Enter-flow, create-on-the-fly, accept-and-save, date/period | Data entry feels like Tally |
| 5 | **Reports** | Menu grouping, in-report hierarchy, keyboard drill-down, period change, detailed/condensed, columnar | Reports grouped and drill down like the approved app |
| 6 | **Missing screens** | The 19 from decision 1 — BRS, Cash Flow, Funds Flow, Columnar, Exception, Analysis & Verification, Registers, carry-forward, user/settings screens | New screens appear and work |
| 7 | **UI standards** | Dibi Tech sweep: no number spinners, currency prefixes, gear-icon master management, auto-select on focus, password eye toggles, forgot-password flow, SQL files for every migration | Consistent polish everywhere |
| 8 | **Audit** | `AUDIT-CHECKLIST.md` matrix, accounting correctness, Playwright on Chromium + WebKit + Firefox, `MAC-SAFARI-MANUAL-TEST.md`, `PRODUCTION-READINESS-REPORT.md` | The sign-off documents |

Keyboard comes **first**, not last: it is the rejection reason, the highest-risk item, and every
later screen registers its shortcuts into it. Building screens first would mean building them
twice.

---

## Working rules

- Commit after every phase. Never leave the app broken between phases.
- Never edit NAS.
- The full `prove-*` battery must pass at the end of every phase — `bash _docs/battery.sh <log>`.
- Any schema change ships a Laravel migration **and** a phpMyAdmin-ready `.sql` in `_docs/sql/`.
- Ambiguity in Tally behaviour or the shortcut map goes to the owner, not to a guess.

---

## Phase 1 record

**Approach changed after reading both engines — and the change reduced risk.**

The original plan said "port NAS's engine into ZeroBook". On reading ZeroBook's
engine in full, that turned out to be the wrong shape of work. ZeroBook's engine is
*richer* than NAS's in the areas that matter to its own screens: a context stack with
`popToContext`/`replaceTop`/`resetTo`, field chaining (Enter/Backspace), the
accept-gate overlay, `yieldWhen`/`yieldInTextarea` escape hatches, auto-select-on-focus,
and capture-phase numeric filtering. Sixty Livewire screens and ~28 JS modules are
built on that API, and five screens register their contexts in inline Blade where no
JS-only sweep would find them.

Every defect was confined to the **key-matching layer** — the ~60 lines that turn a
keypress into a shortcut name. So the port became surgical: keep ZeroBook's context
engine and its entire caller-facing API untouched, replace only the matcher. Nothing
downstream had to change.

### What was wrong, and what it is now

| Defect | Fix |
|---|---|
| `combo()` emitted `meta+a` for ⌘A while every binding is registered `ctrl+a`, and **no `meta+` binding exists anywhere** — so every Ctrl shortcut was dead on macOS | Command folded into Ctrl; screens register Ctrl once, both platforms reach it |
| No macOS-reserved passthrough | ⌘ + Q/W/H/M/N/T/R, Space, Tab, brackets, comma pass to the OS untouched |
| No IME or dead-key guard | `isComposing`, `keyCode 229`, `key === 'Dead'` never intercepted — Indic and CJK input types normally |
| No AltGr guard | Ctrl+Alt on European layouts is treated as typing, not a command |
| `Ctrl+R` unguarded — **silently discarded an in-progress voucher** | Joins F5 in an unconditional reload guard |
| No auto-repeat policy | Held keys repeat navigation only; holding F5 no longer opens a stack of vouchers |
| No unsaved-work guard (brief §3 requires one) | `registerDirty()` + `beforeunload`, **inert until screens opt in** — see note below |
| Hints said `Ctrl+A` on a Mac | `prettyHint` is platform-aware; `labels.js` rewrites the 362 literal chips across 68 Blade files at runtime |
| F1 had no help screen | Tally-style help overlay, read live from the engine registry |

### Key resolution: deliberately hybrid, not pure `e.code`

The brief asks for `e.code`. Applied bluntly that breaks non-US layouts — `e.code` is
*positional*, so on AZERTY a pure-code matcher fires Ctrl+A when the user presses the
key labelled Q. The stated *purpose* in the brief is that Alt/Option combos resolve on
Mac keyboards, so: Alt/Option combos resolve through `e.code` (macOS composes Option+C
into "ç", making `e.key` useless there), everything else through `e.key`. Both goals met.

### Voucher keys corrected to TallyPrime 7.x

Eight keys diverged; several used a key Tally assigns to a *different* voucher.
Owner-approved 2026-07-20.

| Action | Was | Now |
|---|---|---|
| Credit Note | `Ctrl+F8` *(Tally: Sales Order)* | `Alt+F6` |
| Debit Note | `Ctrl+F9` *(Tally: Purchase Order)* | `Alt+F5` |
| Receipt Note | `Alt+F5` *(Tally: Debit Note)* | `Alt+F9` |
| Sales Order | `Alt+F6` *(Tally: Credit Note)* | `Ctrl+F8` |
| Purchase Order | `Alt+F7` *(Tally: Stock Journal)* | `Ctrl+F9` |
| Rejections In / Out | `Ctrl+F5` / `Ctrl+F6` | swapped to `Ctrl+F6` / `Ctrl+F5` |
| Select Company | `F1` | `F3` — frees F1 for Help |
| Calculator alternate | `Alt+N` | `Ctrl+Alt+N` (doc §F.4) |
| Stock Journal · Physical Stock | *(unbound)* | `Alt+F7` · `Ctrl+F7` |

### Installable app

Manifest, service worker, icons and an install affordance inside the help screen.
Three decisions worth recording:

- **Root-relative `start_url`/`scope`.** ZeroBook is multi-tenant by subdomain and a
  manifest's scope is origin-bound; relative URLs make each tenant install as its own
  app. Linked from the *tenant* layout only, so nobody installs the marketing site
  instead of their books.
- **The service worker caches nothing, on purpose.** Cached Livewire HTML would carry a
  stale CSRF token and snapshot, producing page-expired errors that look like data loss
  mid-voucher; and cross-tenant cache bleed would be a confidentiality problem.
  Its only job is to satisfy installability.
- **Never registered inside ZeroBook Desktop**, which already runs its own offline layer
  against local SQLite.

Icons were derived from the existing 512×512 desktop mark; its background sampled as
exactly `#0B6E4F`, confirming the brand primary. `public/favicon.ico` had been 0 bytes
and is now a real icon.

### Verification

- **43 unit tests** (vitest + jsdom) over both the Windows and macOS maps. Proven
  non-vacuous by mutation: reintroducing the original `meta+` defect fails the suite;
  restoring it passes 43/43.
- **26 browser tests** (Playwright) green on **Chromium and WebKit**. WebKit is Safari's
  engine, so this is the closest automated proxy for the client's Mac. The macOS cases
  fake `navigator.platform` and exercise the real Mac code path in a real WebKit build.
- One e2e test initially failed and the *test* was wrong, not the app: F5 correctly
  suppresses the browser reload **and** opens the Payment voucher, which is a
  navigation. Verified directly (`defaultPrevented: true`, URL →
  `/vouchers/create/payment`) before rewriting the assertion.

### Deliberately deferred

- **The dirty guard is inert.** No screen registers a probe yet, so `beforeunload` never
  binds and bfcache is preserved. Wiring it into `Esc` would break the ~23 screens whose
  `onEsc` currently assumes navigation is unconditional — that belongs with the voucher
  work in Phase 4.
- **Three dead `@keydown` handlers in Blade** (voucher date-segment Backspace, and the
  company-picker Enter affordance) are dead *today*, before and after this change, because
  the engine's `stopPropagation` runs first. Logged for Phase 4.
- **Gateway hot-letter collisions**: `C`, `D` and `0` are each bound twice or three times
  in `Shell::gatewayMenu()`; last-wins silently shadows Subscription, Data & Privacy and
  Budgets/Ratios. Belongs with the Gateway rebuild in Phase 2.

---

## Phase 0 record

- `git init`, identity set repo-local. Baseline commit `ec2815f` — the app exactly as it stood
  before this work.
- Removed `tmp_auth_check.php`, `tmp_check_user.php`, `tmp_mark_verified.php`,
  `tmp_verify_login.php` (throwaway login-debug scripts that embedded a live account's plaintext
  password and echoed its password hash) and `public/default.php.old.php` (leftover Hostinger
  placeholder in the web-accessible directory). Recoverable from `ec2815f`.
- Baseline proof battery: see `_docs/` log referenced in the Phase 0 commit.
- PHP note: the project needs PHP ≥ 8.3; the shell default is 8.2. Prefix with
  `export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:$PATH"`.
