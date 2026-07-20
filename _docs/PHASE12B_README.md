# ZeroBook — Phase 12B: company groups + inter-company transaction tagging

**Status: done and proven.** `php artisan zerobook:prove-intercompany` → **52 assertions, 0 failures.**
Full cold regression: **22/22** prove-commands green (21 prior + `prove-intercompany`; `prove-multi-tenant`
re-runs the 10 legacy proofs inside two tenants, `prove-multi-company` re-checks the 12A isolation
backbone). The phpMyAdmin script `_docs/phase12b_schema.sql` was verified to reproduce the migration
schema **byte-identically** (provision → revert via the migration's own `down()` → apply script →
`SHOW CREATE TABLE` diff = 0). An adversarial multi-agent review (4 lenses × 2-refuter verification) ran
over the finished build — see "Adversarial review" below.

12B is the plumbing that makes 12C's consolidation math trustworthy: a **group** declares a tenant's
companies as related, and inside a group every transaction between member companies carries a
**server-derived tag** — the rows 12C eliminates to avoid double-counting inter-company sales,
receivables/payables, and loans.

**The design principle, proven end-to-end: grouping is opt-in and inert by default.** A tenant that
never creates a group behaves exactly as a 12A tenant — no UI, no enforcement, no behaviour change.
CA-firm workflows (many unrelated client books) never see any of this.

---

## Step 0 — audit result

All 21 prior prove-commands were re-run at the close of 12A (the true-final post-fix regression):
**21/21 green**, zero changes since. The 12A isolation surface (`BelongsToCompany`, `ActiveCompany`)
was read before building and is **untouched by 12B** — every 12B behaviour plugs in above it.

---

## The data model

Nothing is seeded — groups are user-created. Nothing central changes.

- **`company_groups`** — `name`/`slug` unique, `notes`. A TENANT-level master (deliberately **no**
  `company_id`: a group spans companies, so it sits above the 12A scope, like `companies` itself).
- **`company_group_members`** — `company_group_id` (FK cascade) + `company_id` (FK cascade),
  **`UNIQUE(company_id)`**: a company belongs to at most **one group at a time**, enforced by the
  database, not convention. Deleting a group cascades the membership and touches no company. A
  single-member group is a valid stepping stone that produces no tagging behaviour (no groupmates
  exist to tag against — proven).
- **`ledgers.linked_company_id`** (nullable FK, `SET NULL` on company delete) — marks a party ledger
  in company A as "this party IS company B". Only **party-tracking** ledgers can be linked: the group
  is, or descends from, *Sundry Debtors, Sundry Creditors, Loans & Advances (Asset), Loans (Liability)*
  (verified against the seeded chart — Bank OD A/c and Secured/Unsecured Loans qualify as descendants).
  Enforced in the Ledger master via `InterCompanyService::assertLinkable()`; the schema stays
  permissive so a link can outlive a deleted group, where it is simply **inert**.
- **`voucher_intercompany_tags`** — one row per inter-company voucher (**`UNIQUE(voucher_id)`**).
  `company_id` = the posting side (the 12A every-operational-table discipline; the model carries
  `BelongsToCompany`, so 12C scans "my company's tags" through the scope); `counterparty_company_id` =
  the other side; `counterparty_ledger_id` = the reciprocal mirror when one is unambiguous (below);
  `created_at` only — a tag is never updated, only re-derived (delete + insert on alter; the voucher FK
  cascades it on cancel). A `(company_id, counterparty_company_id)` index serves 12C's elimination scan.

## The server-authority derivation rule

`InterCompanyService::verifyPayload()` runs in `VoucherScreen::validatePayload()`'s after-hook chain —
the same seat as GST/VAT/bill-wise/cost-centre/TDS/forex, error key `intercompany`:

1. **Derive**: collect the distinct `linked_company_id`s of the payload lines' ledgers (a company-scoped
   read — a crafted foreign ledger id resolves to nothing) that are **groupmates of the active company
   at post time**. Grouping is checked live: link-without-group derives nothing (inert).
2. **Enforce** (each case proven with its specific message):
   - derived = 1 and no tag declared → **rejected**: *"touches an inter-company party but the tag is missing"*;
   - declared ≠ derived → **rejected**: *"the ledger declares counterparty X but the payload claims Y"*;
   - tag declared but derived = 0 → **rejected** (a false-positive tag would corrupt 12C's elimination);
   - derived > 1 → **rejected**: one voucher, one counterparty — split it;
   - ungrouped company + declared tag → **rejected** loudly (never silently dropped).
3. **Persist** (`persist()`, inside the posting transaction, after `TdsService::persistChallan`):
   everything is **re-derived from the persisted lines** — the verified payload's declaration is never
   copied. Alter runs `reverseFor()` in the reset step and re-derives; cancel cascades via the FK.

Stock and inventory-workflow vouchers post no ledger lines, so they can never carry a tag, by
construction. The Trial Balance is untouched — a tag is metadata, never a posting.

## The reciprocal-ledger convenience (soft)

A CA setting up a multi-business owner clicks **"Create reciprocal ledger in linked company"** on the
Ledger master: `ActiveCompany::runAs()` (the CompanyProvisioner precedent — the isolation trait itself
untouched) creates the mirror party in the counterparty company, linked back. Mirror natures:
Sundry Debtors ↔ Sundry Creditors; Loans & Advances (Asset) ↔ Unsecured Loans (children like Bank OD
resolve via their ancestor). An existing same-name ledger gains the back-link instead of a duplicate.

Where exactly **one** ledger in the counterparty company links back to the poster, the tag records it
as `counterparty_ledger_id` (none/ambiguous → null) — so 12C can match eliminations precisely, not by
amount. It is deliberately **soft**: nothing prevents non-mirrored inter-company posting.

## UI

- **Voucher screen** — any line (or invoice party) whose ledger is linked to a groupmate shows a
  derived, non-editable **"Inter-Company → <name>"** chip (evergreen, `zb-ic-badge`); the client
  pre-fills `intercompany.counterparty_company_id` from bootstrapped data with **zero network**
  (`Ledger::toCache()` now carries `linked_company_id`; the group ships in bootData). The derivation
  deliberately mirrors the payload: invoice mode includes the party ledger (not in `this.lines`), and
  stock/workflow vouchers derive nothing. The server re-verifies on accept, as always.
- **Top bar** — `Company: Alpha Ltd · Group: Global Holdings` when grouped, hidden otherwise. The group
  rides a **separate** config key so print letterheads and the F1 picker keep the clean company name.
- **Companies › Groups** (`/companies/groups`, Go To: "group / consolidation / inter-company") —
  create groups, add/remove members (only ungrouped companies offered), delete a group
  (`wire:confirm`, explains that books are untouched and tagging simply stops).
- **Ledger master** — a "Linked to company" select (groupmates only; hidden entirely when ungrouped)
  + the reciprocal button. Guards live in the service; the workspace surfaces the specific refusals.

---

## `prove-intercompany` — 52/0 (the worked scenario)

Provisions a tenant with **Alpha Ltd** (A), **Beta Mfg Co** (B), **Gamma Traders** (C). Highlights:

- **Ungrouped baseline**: a Sundry Debtor in A linked to B *without any group* — a Sale posts with no
  tag required and none created; a *declared* tag is refused; TB balances. **Linking without grouping
  is inert** — the CA-firm guarantee, asserted not assumed.
- **Group CRUD**: single-member group flags nothing; A cannot join a second group (DB unique fires);
  deleting a scratch group cascades membership and leaves the company untouched.
- **Linking guards**: to non-groupmate Gamma → refused ("not a member of this company's group");
  on a Sales Accounts ledger → refused ("party-tracking"); on Unsecured Loans (a Loans (Liability)
  descendant) → allowed.
- **The tag**: grouped Sale in A on the linked ledger → tag row `(company=A, counterparty=B,
  mirror=null)`. **Tampered** (claims Gamma) → rejected naming both companies. **Missing** → rejected.
  **False positive** → rejected. **Two counterparties in one voucher** → rejected.
- **Reciprocal**: one click creates "Alpha Ltd" in B under Sundry Creditors, linked back; the next
  Sale's tag records `counterparty_ledger_id` = the mirror.
- **Alter** re-derives (exactly one tag, same counterparty + mirror); **cancel** cascades the tag away.
- **CA-firm unaffected**: ungrouped C — `enabled()` false, bootData off, ordinary posting, and the 12A
  isolation spot-check (C sees only its own voucher). Per-company TBs balance throughout.

Browser-verified on a live two-company tenant: group created through the Groups screen; top bar shows
`GROUP: FX GROUP`; the Ledger master offers only the groupmate; reciprocal click creates the mirror in
the other company; the voucher screen shows **"Inter-Company → Beta Client Books"** on the party line
and the posted invoice's tag row carries the mirror ledger id; **0 console errors**.

## Adversarial review — found and FIXED before delivery

A 4-lens multi-agent hunt (derivation correctness, 12A-isolation regression, group lifecycle edges,
client/UI mirror fidelity; 16 raw findings, two adversarial refuters each) confirmed **6 real defects
plus one contested-but-real importer gap — all fixed, proof-covered, and re-proven**:

1. **Forex revaluation journals were unpostable for a grouped book** — the revaluation payload carries
   no tag, so a linked foreign-currency party (the natural inter-company USD loan) made period-end
   revaluation fail "tag is missing" — and a journal touching two linked parties could never be split
   around. Fix: a revaluation is a **valuation adjustment, not a transaction with the counterparty** —
   exempt from the mandatory tag (a *declared* tag on one is still refused, so nothing false rides the
   exemption). Proven both ways.
2. **`createReciprocal` could stamp the back-link onto an arbitrary same-name ledger** — a NON-party
   ledger in the counterparty company that happened to carry the poster's name (e.g. an expense ledger
   "Alpha Ltd") would be silently linked, making every real third-party voucher on it derive as
   inter-company (false tags → 12C would eliminate real revenue) and nulling reciprocal precision.
   Fix: the matched ledger must pass the same guards as a manual link — party-tracking group, not
   already linked elsewhere — with distinct refusal messages; the already-linked case now reports
   honestly instead of claiming creation. Proven with a decoy ledger.
3. **A stale link froze its ledger after group deletion** — `saveAlter` re-asserted linkability on the
   unchanged link while the blade (correctly hiding the field for ungrouped tenants) also hid the
   error: every field of that ledger became silently un-editable. Fix: linkability is asserted only
   when the link is **set or changed**; an unchanged stale link passes through (documented-inert), and
   the alter form now shows a stale link with an explicit "(link inert — no shared group)" option so it
   is always visible and clearable. Proven: unchanged-link edit saves, changing while ungrouped is
   refused, clearing is allowed.
4. **Client derivation missed the single-entry Account leg** — a linked ledger picked as the
   "Account (paid from / received to)" derived server-side but not client-side → post rejected with no
   visible cause. Fixed: the derivation now includes the single-entry account leg.
5. **Client derived from all lines while the payload ships only filled ones** — a leftover zero-amount
   linked line made the client declare a tag the server (deriving from the shipped lines) refused.
   Fixed: the client derives from `filledLines`, mirroring the payload exactly.
6. **The Tally importer could never import a grouped book** — importer payloads carry no tag, so every
   historical voucher touching a linked party hard-failed. Fix: the importer (a machine replaying
   history, not a user declaring intent) **self-declares exactly what the server derives** — `persist`
   re-derives regardless, so nothing is trusted; `prove-tally-import` stays green.

Notable refuted findings (correct as built): the double derivation at verify and persist cannot
disagree within one request (same transaction, same service pattern as every other hook); crafted
nonexistent counterparty ids are rejected by the mismatch check before any FK; stock/workflow vouchers
have no path to a tag; desktop sync entries recorded *before* a group existed are rejected by the
server with a named reason on replay — the documented server-authoritative sync posture.

---

## Files

**Schema** — `database/migrations/tenant/2026_07_21_000001_add_company_groups.php` ·
`_docs/phase12b_schema.sql` (byte-diff verified).

**Domain** — `app/Services/InterCompanyService.php` (the authority: enabled / deriveCounterparties /
verifyPayload / persist / reverseFor / reciprocalLedgerId / assertLinkable / bootData) ·
`app/Models/CompanyGroup.php` (tenant-level, no scope trait — deliberate) ·
`app/Models/VoucherIntercompanyTag.php` (scoped) · `Company::group()/groupmateIds()` ·
`Ledger::linkedCompany()` + `linked_company_id` in fillable/casts/toCache.

**Posting path** — `app/Livewire/VoucherScreen.php`: `intercompany` rules + after-hook after `forex`,
`persist()` after `persistChallan()`, `reverseFor()` + re-persist in `persistAlter()`.

**UI** — `app/Livewire/CompanyGroupWorkspace.php` + blade + `/companies/groups` route ·
`app/Livewire/LedgerWorkspace.php` (link field on create/alter + `createReciprocal`) + blade ·
`resources/js/vouchers/screen.js` (boot state, `lineInterCompanyId`, `interCompanyCounterparty`,
payload tag) · voucher blade badges + `zb-ic-badge` CSS · Shell `companyGroup` key + Go To entry ·
top-bar group suffix.

**Proof** — `app/Console/Commands/ProveInterCompanyCommand.php` (`zerobook:prove-intercompany`, 42/0).

---

## Out of scope (named, not stubbed)

- **Consolidation reporting** — 12C reads the tags this phase writes; nothing here aggregates across
  companies.
- **Auto-mirror voucher creation** — posting a Sale in A does *not* create the Purchase in B. That is a
  workflow feature with its own correctness concerns (whose numbering? whose date? partial mirrors?) —
  deferred to 12C or later, deliberately.
- **Multi-level groups** — a group is flat; `UNIQUE(company_id)` also means no cross-group membership.
- **Inter-tenant transactions** — a parent in one tenant and a subsidiary in another is a real scenario
  but crosses the 7B security boundary; 12B stays intra-tenant.
- **Inter-company loan interest** — loans work as ordinary ledger accounting; interest is manual.
- **Stale-tag reads after a group change** — tags survive group deletion (historically true statements:
  "this voucher was inter-company when posted"). 12C decides whether to eliminate against current or
  historical membership; documented as a 12C design input, not a 12B defect.

Existing tenants: `php artisan tenants:migrate` (no central migration).
