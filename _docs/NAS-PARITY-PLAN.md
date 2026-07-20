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
