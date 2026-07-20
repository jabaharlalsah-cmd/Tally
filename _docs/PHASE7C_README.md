# ZeroBook — Phase 7C: Desktop Edition (offline / "true parity" tier)

**Goal:** close the last gaps between the web app and Tally — the browser-reserved
keys (F11/F12/F6/F3/Ctrl+W/T) and network latency in the interactive loop — with a
**Tauri v2 native desktop app** that wraps the *same* ZeroBook tenant app, adds full
keyboard control, and enters vouchers **offline** into a local mirror that
**chronologically syncs** to the tenant's database when connectivity returns. The web
SaaS remains the primary product; desktop is the premium tier.

> **Two honest constraints up front** (this build environment + the app's
> architecture), both detailed below:
> 1. **Rust/MSVC are not installed here**, so `cargo tauri dev`/`build` could not be
>    run and no `.msi` was produced *in this environment*. Every desktop file is
>    complete and ready to `cargo tauri build` on a Windows box with the toolchain.
>    WebView2 (the runtime the built app needs) **is** present here.
> 2. **ZeroBook's UI is Livewire (server-driven).** So the keyboard-capture win is
>    fully real and immediate, but "the whole server-rendered UI running offline" is
>    not possible without a local-first client (which the prompt forbids). The
>    feasible, honest offline path — which ZeroBook's already-client-side voucher
>    entry supports — is **offline voucher entry → local outbox → chronological
>    sync**; reports/masters stay online. That boundary is built and documented, not
>    papered over.

## What is PROVEN here vs. BUILD-READY

| Area | Status |
|---|---|
| Server sync engine (`/api/sync/push` + `/api/sync/pull`) | **PROVEN** — `zerobook:prove-sync`, 20/20 |
| Chronological posting (weighted-avg COGS) via 7A's shared sort | **PROVEN** |
| Server-authoritative rejection, idempotency, round-trip, pull | **PROVEN** |
| Keyboard-capture gating (`ZB_DESKTOP`) — web unaffected | **PROVEN** (web build + regression) |
| All 11 prior proves unchanged | **PROVEN** — `prove-multi-tenant` 47/0 after the changes |
| Tauri scaffold (window, menu, secure token, updater, local SQLite) | **BUILD-READY** (needs Rust toolchain to compile) |
| Local sync worker + offline entry + tenant picker (JS) | **BUILD-READY** (needs the desktop shell to exercise) |

---

## Step 0 — audit + decisions

**Audit — Phase 7B passes.** `prove-multi-tenant` = **47/0** (runs all ten prior
proves inside two tenants + isolation/plan-gate/interactive). After the 7C changes it
is **still 47/0** — no regression from the sync change-log trait or migration.

**Tauri v2 confirmed** (smaller than Electron, native WebView2, first-class Rust). No
reason surfaced to prefer Electron.

**Sync model — A (local SQLite mirror + change-log).** Model B doesn't work offline
(a stated goal) and keeps write latency. Model A's hardest part — **posting a batch of
offline vouchers in `voucher_date` order so weighted-average COGS is correct** — is
pure server-side PHP, so it is fully built and proven here. Model A it is.

---

## The server sync engine (the crown jewel — proven)

Two endpoints on the tenant subdomain (`routes/tenant.php`, behind `auth:tenant`), a
thin `SyncController` over `App\Services\Sync\SyncService`. **No parallel posting** —
every voucher goes through the same `VoucherScreen::post()` the web UI and the Tally
importer use, so the balance gate, GST/VAT authority, bill-wise and cost-centre checks
all apply to synced data.

* **`POST /api/sync/push`** — drains a desktop outbox. It:
  * sorts the batch into strict voucher-date order using the **shared**
    `VoucherImporter::chronologicalSort()` (extracted in 7A, now used by both) — so a
    day of offline vouchers entered in any order posts in the order COGS requires;
  * is **idempotent** on the desktop's `client_uuid` (a retried batch never
    double-posts);
  * is **server-authoritative** — a voucher that fails validation is **rejected with
    its reason** (the desktop surfaces it in a sync-error tray), the others still post.
* **`GET /api/sync/pull?since=<cursor>`** — `since=0` returns a full snapshot (masters
  + vouchers) for the initial local mirror; `since>0` returns only voucher changes
  past the cursor, from the `sync_changes` change-log (populated by ANY writer — web
  SaaS, another desktop, the importer — via the `RecordsSyncChanges` trait on
  `Voucher`).

**Schema (per tenant DB):** `vouchers.client_uuid` (idempotency) + `sync_changes`
(pull cursor). Migration `database/migrations/tenant/2026_07_11_000001_add_sync_infrastructure.php`
+ phpMyAdmin `_docs/phase7c_sync_schema.sql`. Existing tenants: `php artisan tenants:migrate`.

### `zerobook:prove-sync` (20/20)

```
1. Chronological push (offline batch in REVERSE date order — Sale sent before Purchase)
   [PASS] sale COST rate = 40 (weighted-avg AFTER purchase — NOT the opening-only 25) ← THE proof
2. Server-authoritative rejection (one out-of-balance voucher in the batch)
   [PASS] good payment posted · bad journal REJECTED with reason · bad one did NOT persist · uuid kept
3. Idempotency  [PASS] re-sent entry acknowledged as duplicate · no new voucher
4. Round-trip   [PASS] synced sale is a normal server voucher (same id, in the Day Book, carries client_uuid)
5. Pull         [PASS] snapshot (masters + vouchers) · incremental returns only new changes · cursor advances
```

---

## The browser-key gap — closed

ZeroBook's engine **already binds** the raw `f6→Receipt`, `f11→Features`,
`f12→Config` alongside their `Alt+` alternates; in a browser those raw keys lose the
`preventDefault` race to fullscreen/devtools. **Inside the Tauri window there is no
browser chrome, so `preventDefault` wins and the raw keys just work.** The only code
change: a desktop-runtime flag and neutralising the two keys we deliberately *don't*
fight in a browser.

`resources/js/engine/keys.js`:
* `isDesktopRuntime()` → true when `window.ZB_DESKTOP === true` (injected by the shell)
  or `window.__TAURI__` is present.
* `shouldHardBlock(c)` = the existing `HARD_BLOCK` **plus**, on the desktop, `Ctrl+W`
  and `Ctrl+T` (which would otherwise close/replace the window; the app assigns them
  no action — captured and ignored).
* `uncapturableKeys()` → **`[]` on the desktop** (the gap is closed); the web list
  otherwise.

| Key | Web behaviour | Desktop behaviour |
|---|---|---|
| **F11** | browser fullscreen wins → Alt+F11 alternate | **F11 → Features** (raw key) |
| **F12** | browser devtools wins → Alt+F12 alternate | **F12 → Config** (raw key) |
| **F6** | address bar → Alt+F6 alternate | **F6 → Receipt** (raw key) |
| **F3** | browser Find | captured & ignored (no Find bar) |
| **Ctrl+W / Ctrl+T** | not fought (would break the tab) | captured & ignored (window stays put) |

In the web edition `isDesktopRuntime()` is false, so behaviour is **byte-identical** —
verified by the frontend build + all proves still green.

---

## The Tauri app (`desktop/`) — build-ready

```
desktop/
  icon.png                         source icon (run `cargo tauri icon ../icon.png`)
  dist/                            the frontend the window loads
    index.html  shell.js  styles.css   the launcher shell (tenant picker)
    desktop-inject.js              injected into EVERY page: ZB_DESKTOP flag + sync worker + indicator
  src-tauri/
    Cargo.toml  build.rs  tauri.conf.json
    src/main.rs  src/lib.rs        window + menu + secure token (OS keychain) + updater + SQLite
    migrations/001_sync.sql        local SQLite: sync_outbox, local_vouchers, sync_meta
    capabilities/default.json      IPC grants (incl. scoped `remote` for ZeroBook's own tenant pages)
    icons/                         generated by `cargo tauri icon`
```

### Native shell
* Window 1280×800, min 1024×640, centered, **remembers size/position**
  (`tauri-plugin-window-state`), no browser chrome.
* Native menu **File** (Sign Out, Quit) · **Edit** (undo/redo/cut/copy/paste/select-all)
  · **View** (Reload, Toggle Full Screen) · **Help** (About, Check for Updates…). Menu
  items complement the keyboard workflow; the Phase-1 engine still owns in-app keys.
* `initialization_script` injects `window.ZB_DESKTOP = true` + `desktop-inject.js` into
  the local shell **and** the remote tenant page.

### Auth / tenants (`shell.js`)
Launcher lists remembered companies + an "add company" field → navigates the window to
`https://<subdomain>/app`. The tenant's **session is the webview's persisted cookie**
(so it survives restarts; if it lapses the app itself shows `/login` — the 7B tenant
login, unchanged). The **OS keychain** (`store_token`/`get_token`/`clear_token` via the
`keyring` crate — Windows Credential Manager) holds the per-company record; **Sign Out
clears the keychain entry** and POSTs `/logout`. Multiple tenants = multiple picker
entries (a CA with several clients); sign out / back in to switch.

### Offline (`desktop-inject.js` + local SQLite)
* First sign-in pulls a **snapshot** (`/api/sync/pull?since=0`) into local SQLite.
* Voucher entry offline: `resources/js/vouchers/screen.js` — when `ZB_DESKTOP` **and**
  `navigator.onLine === false` — queues the voucher into `sync_outbox` + a provisional
  Day-Book row, instead of the (unreachable) Livewire post. **The branch is inert in
  the web** (`ZB_DESKTOP` undefined), so the online path is untouched.
* A background worker (every 30 s, configurable) drains the outbox to `/api/sync/push`
  and pulls changes. A **keyboard-accessible indicator** (Alt+Y) shows *"Online —
  synced 30s ago"* / *"Offline — N pending"* / *"Sync error — N rejected"*.

### Updater / version
`tauri-plugin-updater` pointed at a static endpoint (a real release server is later);
**Help → Check for Updates…** runs a check and, if available, downloads + relaunches.
Version is stamped from `Cargo.toml`/`tauri.conf.json` and shown in **Help → About**.

---

## Build & run (on a Windows box with the toolchain)

**Prerequisites** (not present in the build environment used here):
1. **Rust** — <https://rustup.rs> (`rustup default stable`).
2. **MSVC C++ Build Tools** (Visual Studio Build Tools, "Desktop development with C++").
3. **WebView2 runtime** — already on Windows 11 (confirmed present here).
4. Tauri CLI — `cargo install tauri-cli --version "^2"` (or `npm i -g @tauri-apps/cli`).

```bash
cd desktop/src-tauri
cargo tauri icon ../icon.png          # first time — generates icons/
cargo tauri dev                       # launches the app → tenant picker → sign in → Gateway
cargo tauri build                     # produces target/release/bundle/msi/*.msi
```

Before publishing updates, generate a signing keypair (`cargo tauri signer generate`)
and put the **public** key in `tauri.conf.json → plugins.updater.pubkey`.

---

## Operational notes

* **Local data** lives in the OS app-data dir (Windows:
  `%APPDATA%\in.zerobook.desktop\zerobook_local.db`), never the install dir.
  **Reset** = delete that file (the next sign-in re-pulls a fresh snapshot).
* **The web SaaS is unaffected** — no service signatures changed; the only backend
  additions are the two additive sync endpoints + a nullable column + a log table. A
  user can run desktop at the office and the web SaaS on a laptop against the same
  tenant, concurrently (server-authoritative; last-write-wins with rejection surfaced).
* **Publishing an update** (later): build with a bumped version, sign the artifacts,
  host them + a version JSON at the updater endpoint. The client already polls it.

---

## What I could NOT verify in this environment (must be done on staging)

Honest list — everything here is code-complete but needs the Rust toolchain + a
display to exercise:
* `cargo tauri dev` launching the window and showing the tenant login.
* `cargo tauri build` producing the `.msi`.
* F11/F12/F6/F3/Ctrl+W/Ctrl+T captured *in the running window* (the harness check).
* An offline voucher landing in local SQLite, showing locally, and syncing on
  reconnect with the same id in the web Day Book — the **server** half of this is
  proven (`prove-sync`); the **client** half needs the built app.
* The updater client polling; Sign Out clearing the keychain entry.

Everything server-side and web-side **is** verified: `prove-sync` 20/20,
`prove-multi-tenant` 47/0, and the frontend build compiles with the web path unchanged.
