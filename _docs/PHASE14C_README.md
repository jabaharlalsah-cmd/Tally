# Phase 14C — Backups, data export & offboarding

The operational safety net for a real SaaS: **verified** per-tenant backups you can actually
restore, a complete **customer-initiated data export** (their books, in a form they can read and
re-import), and a **clean offboarding lifecycle** that is reversible right up until the final,
deliberate purge. Closes Phase 14.

Three rules shaped every design decision here:

1. **A backup that isn't proven restorable is worse than no backup.** Every archive is
   integrity-checked before it's recorded, and restore lands in a *fresh* database that is
   verified (structure + counts + double-entry balance) before anyone trusts it.
2. **An export must be complete.** A *silent* omission is the worst kind of data loss, so the
   proof byte-checks every CSV against the live row counts.
3. **Deleting a customer's data is irreversible, so make it hard to do by accident** — a typed
   confirmation, a long reversible window, and audit trail at every step. The central billing
   row and payment history are **never** deleted (legal retention).

---

## Step 0 — audit result

All **26 prior `prove-*` commands pass** (re-verified before building; the full battery is
re-run after). **After 14C: 27/27 green** — the new `zerobook:prove-backups-exports-offboarding`
adds **74 assertions across 12 sections**. No tenant-DB schema change; nothing in any accounting
service was touched. Two changes to existing behaviour, both found while verifying: a
collision-proof backup filename (see *Backups*), and a guard so the legacy 14A plain-reactivate
refuses an offboarding tenant (see *Offboarding lifecycle*).

> **Running the full battery:** 17 proofs self-provision throwaway tenants and run green bare;
> the other 10 need a tenant-DB context —
> `DB_DATABASE=tenant<slug> php artisan zerobook:prove-<name>` for the nine single-company proofs
> (`balance, sales-purchase, gst, vat, billwise, costcentre, item-invoice, stock-journal,
> inventory-integration`) and `--tenant=<slug>` for `tally-import`. Running those ten bare fails
> with *"no companies table"* because the default connection is the central DB.

---

## Backups

`Services\Backups\BackupService` — dump → gzip → **verify** → record.

- **Dump.** `mysqldump --single-transaction --skip-lock-tables --no-tablespaces
  --default-character-set=utf8mb4` via Symfony Process; the password is passed through the
  `MYSQL_PWD` env var so it never appears in the process argument list. The mysql/mysqldump
  binaries are located from `config('zerobook.mysql_bin_dir')` (falling back to `PATH`).
- **Store.** `gzencode()` to `storage/app/private/backups/{tenant}/{Y-m-d-His}-{rand}.sql.gz`.
  The random suffix keeps two backups taken in the same second (a manual *backup now* right after
  the scheduled run) from overwriting each other.
- **Verify (the gunzip -t equivalent, in PHP).** The written file is `gzdecode()`d and must
  contain `CREATE TABLE`. **A failed check deletes the file, alerts the platform admins
  (`BackupFailed`), and records NO row** — an unverified backup is never kept.
- **Record.** `tenant_backups` row with `type` (`scheduled` | `on_demand` | `pre_offboarding`),
  size, `verified_at`, `expires_at`, and a `meta` JSON of voucher / entry / ledger counts (checked
  on restore).

### Retention

`config('zerobook.backup_retention')` — **7 daily / 4 weekly / 12 monthly** (default);
**14 daily / 8 weekly / 24 monthly** for any plan whose tier contains `enterprise`. `expiryFor()`
grants each backup the **longest** window it qualifies for: a 1st-of-month backup keeps 12 months,
a Sunday backup 4 weeks, an ordinary weekday backup 7 days. `pruneBackups()` deletes (file + row)
everything past its `expires_at`; it runs at the end of the nightly backup sweep.

### Restore — always into a FRESH database

`restoreTenant($original, $backup)` decompresses the archive, creates a **new** tenant
(`{slug}rst{His}`, status `restored`), `CreateDatabase`, imports via the `mysql` CLI, then
**verifies**: required tables exist, voucher/entry counts equal the backup metadata, and
`Σ Dr === Σ Cr` (the prove-balance equivalent). On any failure the fresh DB is dropped and the row
removed. **The original database is never touched** — restore is non-destructive by construction,
so you can restore-to-inspect without risking live books.

`restoreInPlace($tenant, $backup)` is the one deliberate exception, used only by reactivation
(below): it first proves the backup restores + balances in a throwaway DB, and *only then* drops
and recreates the tenant's own database from the verified snapshot.

### Commands & schedule (`routes/console.php`)

| Command | What |
|---|---|
| `zerobook:tenant-backup-all` | Back up every live tenant (isolated try/catch each), then prune. **Scheduled daily 01:00.** |
| `zerobook:tenant-restore {tenant} {backup} {--force}` | Restore a specific backup into a fresh DB (ops tool). |
| `zerobook:offboarding-advance` | Step every offboarding tenant one state if due; send deadline reminders. **Scheduled daily 03:00.** |

On-demand backup + restore are also one click each from the admin tenant-detail screen.

---

## Data export

Customer-initiated, complete, human-readable — `Services\Exports\ExportService` +
`Jobs\ExportTenantJob` (async; runs inline under the `sync` queue, truly background once a worker
is added).

**The archive** (`storage/app/private/exports/{tenant}/{Y-m-d-His}-export.zip`) contains:

```
README.txt                     — what every file is; amounts are in paise, dr_cr = Dr|Cr.
database.sql.gz                — full mysqldump for a raw restore elsewhere.
companies/<company>/
  account_groups.csv, ledgers.csv
  vouchers_<type>.csv          — one CSV per voucher type present…
  voucher_entries.csv          — …plus every debit/credit line.
  stock_*.csv, units.csv, godowns.csv, stock_entries.csv, bill_allocations.csv,
  cost_allocations.csv, tds_*.csv, inter_company_stock_lots.csv, order_*.csv …
  company_features.json        — the F11 profile.
  reports/                     — authoritative snapshots at export time:
    Trial Balance.csv, Balance Sheet.csv, Profit and Loss.csv
```

**Completeness is the invariant.** Every company-scoped table is dumped verbatim, one CSV each,
with the header row **always** written (from `Schema::getColumnListing`) even when the table is
empty — so an importer always sees the columns and nothing is silently omitted. The proof opens
the produced zip and asserts each CSV holds *exactly* the live row count.

**Delivery & limits.** On completion the owner is emailed (`ExportReady`) a **signed, relative,
host-independent** download link (`URL::temporarySignedRoute('export.download', …, absolute:false)`
+ tenant-host prepend) that **expires in 7 days** (`config('zerobook.export_link_days')`). Download
goes through `DataExportController@download`: signed middleware + a `tenant_id === tenant('id')`
guard + a completed/unexpired/file-exists check, then streams from private storage and stamps
`downloaded_at`. **Rate-limited to one export per 24h** (`config('zerobook.export_min_hours')`).

---

## Offboarding lifecycle

`Services\Offboarding\OffboardingService` — a state machine, reversible until the last step.

```
active ──initiate──► suspended(+offboarding) ──advance──► archived ──advance──►
                          │  (read-only)          │  (access-closed)
                          └──────── reactivate ◄───┘                 purge_scheduled
                                (restore in place)                        │ advance
                                                                          ▼
                                                                       purged  ✗ irreversible
```

- **`initiate`** (customer via *Data & Privacy → Close account*, typed-confirmation guarded; or an
  admin): status → `suspended`, `offboarding_initiated_at` stamped, `archive_scheduled_for` = +30d,
  `purge_scheduled_for` = +150d. Takes a **`pre_offboarding` safety backup** and generates a **data
  export** at the moment of closure, emails the owner the timeline. Rate-limited
  (`config('zerobook.offboard_min_days')`, default 7).
- **`advance`** (nightly): `suspended(offboarding) → archived` at +30d; `archived → purge_scheduled`
  at +120d (notifies `PurgeScheduled`); `purge_scheduled → purged` at +150d. Between transitions it
  sends approaching-deadline reminders (`ArchiveReminder` −7d, `ArchiveImminent` −1d,
  `PurgeImminent` −7d).
- **`reactivate`** (admin): brings a `suspended`/`archived`/`purge_scheduled` tenant back to
  `active` and clears the flags. From `archived`/`purge_scheduled` it **restores the pre-offboarding
  snapshot in place** first (the live DB was access-closed). Refused once `purged`. The legacy 14A
  plain-reactivate (which only flips status) is **hidden and server-side refused** for an offboarding
  tenant — otherwise it would strand `archive_scheduled_for` / `purge_scheduled_for`; all
  reactivation of an offboarding account goes through `OffboardingService::reactivate`.
- **`purge`** (auto at +150d, or admin with a typed confirmation): drops the tenant database,
  deletes every backup + export file and row, removes the domains — then marks the central row
  `purged`. **The central `tenants` row and all `payments` are kept** — payment history is retained
  for legal/accounting reasons even though the customer's books are gone for good.

**Access enforcement.** `Tenant::NO_ACCESS_STATUSES = [archived, purge_scheduled, purged]`.
`RequireActiveTenant` logs out and blocks any closed tenant *before* the write checks;
`TenantAuthController` refuses login for a closed tenant. `suspended(offboarding)` is read-only
(reads work, writes blocked) — the customer can still export and download during the window.

Every transition writes a `tenant_lifecycle_events` row (`event`, `triggered_by_user_id` **or**
`triggered_by_admin_id`, notes) — a full audit trail, surfaced on the admin tenant-detail screen
and the **Lifecycle queue** (`/admin/lifecycle`, every non-active tenant ordered by next
transition).

---

## Notifications (Laravel Mail, simple text)

`BackupFailed` (→ platform admins) · `ExportReady` (→ owner, signed link) · `OffboardingInitiated`
· `ArchiveReminder` · `ArchiveImminent` · `PurgeScheduled` · `PurgeImminent` · `TenantPurged`
(all → owner).

---

## Acceptance checklist → where it's proven

`php artisan zerobook:prove-backups-exports-offboarding` (74 assertions, 12 sections):

| Criterion | § |
|---|---|
| Backup dumped, **verified**, gzipped, recorded (+ on-demand, distinct files) | 1 |
| Retention: monthly > weekly > daily; prune deletes only the expired | 2 |
| **Restore into a FRESH DB** — different DB, counts match, balance holds, original untouched | 3 |
| Corrupt archive rejected — no row kept, admins alerted | 4 |
| Export is **complete** — README + mysqldump + every CSV row-count byte-checked, reports present | 5 |
| Export: signed 7-day link + one-per-24h rate limit | 6 |
| Offboarding initiate → suspended, safety backup + export, email, **typed-confirm guard** | 7 |
| Reactivate from suspended(offboarding) → active + writable | 8 |
| Auto-advance to archived, then reactivate → **pre-offboarding snapshot restored in place** | 9 |
| Advance → purge_scheduled → purged (DB dropped, files gone, **central row + payments KEPT**) | 10 |
| Purge is irreversible — reactivation refused | 11 |
| Every transition left a lifecycle audit trail (attributed to user vs admin) | 12 |

**Browser-verified** (on `uitest.localhost` / `admin.localhost`, 0 console errors):
- Admin — *Backups & data* card renders; a backup shows in the table with a Restore button.
- Admin — *Offboarding lifecycle* card renders both states (Active → Initiate; Suspended → Reactivate
  with the full attributed audit trail: initiated/backup/export events); the *Lifecycle queue*
  renders empty and then lists the suspended tenant with its next-transition date.
- Admin — the offboarding *Reactivate* button reactivates end-to-end (status active, schedule flags
  cleared, `reactivated` event logged); the legacy 14A reactivate is correctly hidden during offboarding.
- Tenant — *Data & Privacy* renders (export button, close-account typed-confirm), export history +
  the signed download link, which streams the real archive (HTTP 200, `application/zip`, valid ZIP).

---

## Files

**Schema (central) + `_docs/phase14c_central_schema.sql`:** migration
`…000110_create_14c_backup_export_offboarding` (`tenant_backups`, `tenant_exports`,
`tenant_lifecycle_events`; `tenants` += `offboarding_initiated_at`, `archive_scheduled_for`,
`purge_scheduled_for`); models `TenantBackup`, `TenantExport`, `TenantLifecycleEvent`; `Tenant`
updates (`NO_ACCESS_STATUSES`, relations, `isArchived/isPurged/isClosed/isOffboarding/lifecycleLabel`,
`writeBlockMessage`).

**Backups:** `Services\Backups\BackupService`; `Console\Commands\{TenantBackupAllCommand,
TenantRestoreCommand, OffboardingAdvanceCommand}`; schedule in `routes/console.php`.

**Export:** `Services\Exports\ExportService`, `Jobs\ExportTenantJob`,
`Http\Controllers\Tenant\DataExportController`, `export.download` route in `routes/tenant.php`.

**Offboarding:** `Services\Offboarding\OffboardingService`.

**Tenant UI:** `Http\Controllers\Tenant\DataPrivacyController`,
`resources/views/tenant/data-privacy.blade.php`, `account.data{,.export}` + `account.close` routes,
`Support\Shell` menu entry, nav link in `livewire/subscription-status.blade.php`.

**Admin UI:** `Http\Controllers\Central\{BackupsController, OffboardingController}`,
`PlatformController::lifecycle()` + `show()` extension, routes in `routes/web.php`, views
`central/admin/lifecycle.blade.php` + *Backups & data* / *Offboarding lifecycle* cards on
`central/admin/tenant-detail.blade.php`.

**Middleware/gate:** `RequireActiveTenant` (closed-tenant block + whitelists),
`Http\Controllers\Tenant\TenantAuthController` (closed-tenant login refusal).

**Notifications:** `BackupFailed`, `ExportReady`, `OffboardingInitiated`, `ArchiveReminder`,
`ArchiveImminent`, `PurgeScheduled`, `PurgeImminent`, `TenantPurged`.

**Config:** `config/zerobook.php` (`backup_retention`, `offboarding`, `export_link_days`,
`export_min_hours`, `offboard_min_days`, `mysql_bin_dir`).

**Proof:** `Console\Commands\ProveBackupsExportsOffboardingCommand`.

**Existing environments:** `php artisan migrate` (central only) + ensure `ext-zip` is enabled and
`mysqldump`/`mysql` are reachable (`ZEROBOOK_MYSQL_BIN` or `PATH`). No tenant migration.

---

## Scope — NOT in 14C (honest notes)

- **Cross-region replication, encryption-at-rest with customer-managed keys, point-in-time
  restore** — deliberately out of scope. Backups are single-region, gzipped mysqldumps on the
  app's private disk; move the `backups/` disk to S3/off-box object storage for durability. This is
  the pragmatic pilot-scale spine, not a bank-grade DR product.
- **Off-box backup storage** — the `local` private disk is the default; point the disk at S3 (no
  code change beyond the disk config) for off-server durability before relying on it in anger.
- **Self-service reactivation** — reactivation is an admin action (it restores data in place); a
  customer reactivates by contacting support within the window. Deliberate: it's a rare, careful
  operation.
- **Partial / selective export or restore** (single company, date range, one voucher type) — export
  is all-or-nothing per tenant; restore is whole-database. Fine at pilot scale.
- **Purge of the billing row** — never. The central `tenants` row + `payments` are retained after
  purge by design.
