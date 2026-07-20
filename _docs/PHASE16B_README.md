# Phase 16B — Core REST API (vouchers, masters, reports)

The phase where the accounting engine — 32 phases of proven correctness — goes on the wire. A
customer's HMS POSTs a patient's bill and gets back a posted Sales voucher; their transport site
records a booking payment; any integration reads a party's outstanding balance or the day book.

The correctness spine is **one rule above all others**: every write goes through the *same*
posting path the UI uses. Not a copy of it — the identical method. That is what makes an
API-posted voucher and a UI-posted voucher provably the same voucher.

---

## Step 0 — audit result

**Before 16B: 32/32 prior `prove-*` commands pass. After 16B: 33/33 green**, with
`zerobook:prove-api-core` adding **69 assertions across 13 sections** — all driven through real
kernel-dispatched HTTP, like `prove-api-foundation`.

16B is **additive to the accounting engine**: no accounting service, model, or query was changed.
The API is new controllers, a payload translator, an idempotency service, one new tenant table,
and routes. The edits to shared files are a new render arm in `bootstrap/app.php` (scoped to
`api/v1/*`), route additions, and a scheduler entry — none of which touch the web or sync surfaces.

---

## The cardinal-rule question, settled by experiment

**No `VoucherPostingService` extraction — and building one would have been the wrong call.**

`VoucherScreen::post(array $payload): array` reads no mount/session/auth state — only its payload
argument and `ActiveCompany` (which 16A pins). It is *already* driven headlessly in production by:

- the **Tally importer** (`VoucherImporter` → `new VoucherScreen; ->post()`),
- the **desktop sync engine** (`SyncService` — itself a machine API — does the same),
- and **all 32 proves** (`ProveGst`, `ProveItemInvoice`, `ProveTds`, … all `new VoucherScreen; ->post()`).

I confirmed it directly: a headless `post()` from a plain script creates a real voucher
(`scenario_id=NULL`), balanced, with the balance gate firing on bad input. So the API controller
does exactly what the importer and every prove already do:

```php
$result = (new VoucherScreen)->post($internalPayload);
```

This is the sanctioned "no parallel path" pattern, documented as such in `VoucherImporter` and
`SyncService`. It makes byte-identical posting **structural** — the API calls the identical method,
so any divergence would be a payload-translation bug in the API, which the crown jewel catches.

---

## The crown jewels — byte-identical posting (proof §2, §3)

The proof posts the SAME logical invoice two ways and diffs the resulting rows:

- **UI path**: `(new VoucherScreen)->post($handAssembledPayload)` — built the way `ProveItemInvoice`
  / `ProveGst` build it.
- **API path**: a real `POST /api/v1/vouchers` through the kernel → the controller translates →
  the *same* `post()`.

Because a sale's weighted-average cost depends on prior stock, the two post into **two
identically-seeded companies** (same opening purchase), and the diff is keyed by ledger/item
**name** (ids differ between companies). Asserted identical, row for row:

| Table | Natural key | Compared |
|---|---|---|
| `voucher_entries` | ledger name + dr_cr | paise |
| GST legs | (they *are* voucher_entries on duty ledgers) | Output CGST/SGST paise |
| `stock_entries` | item name + direction | qty / rate / value |
| `bill_allocations` | ledger + ref_type + ref_name | paise |

**§2** — an item-invoice Sales (2 items, 18% GST intra-state, bill-wise New Ref): identical.
**§3** — the ₹40k/₹15k → **₹5,500** TDS catch-up payment: identical, including the server-computed
TDS Payable leg (the client never sends the ₹5,500).

---

## Tax is compute-then-verify

The critical architectural point the readers surfaced: **the posting path never injects tax
lines** — it *re-verifies* them. So the controller:

1. computes the duty lines with the **same** `GstService::computeInvoiceTax()` /
   `VatService::computeInvoiceTax()` / `TdsService::computeDeduction()` that `post()`'s verify
   hooks recompute with,
2. assembles the balanced `lines` (party leg = base + tax; taxable legs; tax legs; TDS leg),
3. hands them to `post()`, which independently re-checks every number to the paise.

Using the same compute methods on both sides means verification passes by construction *and* the
rows match the UI byte-for-byte. Discipline observed:

- **Rounding is per rate-slab** (CGST/SGST each `round(base·rate/200)` independently). The API never
  re-implements tax math — it calls `computeInvoiceTax`.
- **Item mode** replicates `GstService::taxableFromItems` exactly (one taxable entry per item:
  `round(qty·rate,2)` at the item's server-side `gst_rate`), so `post()`'s item-invoice authority
  (`Σ qty·rate == revenue leg`) reconciles.
- **Intra/inter-state is server-derived** from company vs party `Ledger.state`; the client cannot
  supply it.
- **TDS**: the client sends only `{deductee_ledger_id, tds_section_id, base_amount}`; the server
  computes and posts the deducted amount, and `post()` re-verifies it against the deductee's YTD
  state. A client cannot set, inflate, or suppress it.
- **`expected_total`** (optional) is checked *before* posting — a disagreement is a `422
  total_mismatch` with both numbers, and nothing is written.

---

## Idempotency — insert-first, company-aware

Every write (`POST`, `PUT`, cancel) requires an `Idempotency-Key` header (≤255 chars). Missing → a
`422 idempotency_key_required` at the middleware gate, before any work.

The arbiter is **insert-first**, not check-then-insert:

```
INSERT a pending row (its OWN statement, before post())
  ├─ 1062 unique violation on (api_key_id, idempotency_key) → someone else holds it
  │    ├─ their body hash ≠ ours  → 409 idempotency_key_reused
  │    ├─ their row still pending  → 409 idempotency_key_in_flight + Retry-After: 2
  │    └─ their row completed      → replay the stored response + X-Idempotency-Replay: true
  └─ inserted (we own it)
       ├─ post() succeeds → UPDATE the row with the response, return it
       └─ post() throws   → DELETE the row (a corrected retry may proceed), rethrow
```

**Why the insert is a separate statement before `post()`:** `post()` *also* throws `QueryException
1062` — for voucher-*number* collisions, which it retries internally. Doing the idempotency insert
as its own statement makes its 1062 unambiguously the key collision, never a numbering one.

**Two concurrent identical requests → exactly one voucher**: the unique index lets one win the row
and post; the loser gets 1062 and never reaches `post()`. Proven via the deterministic
constraint-violation path (a pre-claimed pending row); true OS-thread concurrency is not
reproducible in one console process on Windows, which the proof states.

### The bug this found: cross-company idempotency replay

Caught proactively (and independently flagged by the adversarial review). The fingerprint was
`sha256(body)`, but the company is chosen by the **X-Company-Id header**, not the body. So the same
key + byte-identical body sent to company A then company B **replayed company A's voucher into the
company-B response and created nothing in B** — a wrong-company replay. Confirmed empirically
(company B got voucher id 1 from company A with `X-Idempotency-Replay: true`).

Fixed: the fingerprint folds in the active company — `sha256(companyId : body)` — so
same-key-different-company is a safe `409 idempotency_key_reused` (a client must use a distinct key
per company; it can never receive another company's resource). Asserted in proof §7.

`api_idempotency_keys` is a per-tenant table with a **real same-DB FK** to `api_keys`. A daily
`zerobook:api-idempotency-prune` sweeps the 48h TTL, per tenant.

---

## Money

Amounts cross the wire as **decimal strings** (`"1500.00"`). Internally everything is integer
paise; `ApiMoney::toPaise` parses the string **exactly** (split on `.`, never `× 100` float), and
the value handed to `post()` is `paise / 100` — the exact form the UI's own payload builder uses,
so both feed the shared path byte-identical inputs. A JSON number is accepted but documented as
risky (it can lose bits before the server sees it). A malformed or non-scalar amount is a **422**,
never a 500 (see the review notes).

---

## Endpoints (all under `/api/v1`, all scope-gated)

| Method | Path | Scope | Idempotent |
|---|---|---|---|
| POST | `/vouchers` | `voucher:create` | ✓ |
| GET | `/vouchers/{voucher}` | `voucher:read` | — |
| GET | `/vouchers` (type/from/to/party/cursor/limit) | `voucher:read` | — |
| PUT | `/vouchers/{voucher}` | `voucher:alter` | ✓ |
| POST | `/vouchers/{voucher}/cancel` | `voucher:cancel` | ✓ |
| GET/POST | `/ledgers`, `/ledgers/{ledger}` (GET/PUT) | `master:read` / `master:write` | writes ✓ |
| GET/POST | `/stock-items`, `/stock-items/{stockItem}` (GET/PUT) | `master:read` / `master:write` | writes ✓ |
| GET | `/reports/trial-balance?as_of=` | `report:read` | — |
| GET | `/reports/ledger-balance/{ledger}?as_of=` | `report:read` | — |
| GET | `/reports/party-outstanding/{ledger}?as_of=` | `report:read` | — |
| GET | `/reports/day-book?date=` | `report:read` | — |

Every route declares its scope via `->defaults(EnforceApiPermissions::SCOPE_DEFAULT, …)` — the
group-attached enforcement refuses a route that declares nothing, so `prove-api-foundation` §10
stays green (re-verified). Writes carry `RequiresIdempotencyKey`; reads never do.

**8 voucher types** are exposed — `sales, purchase, receipt, payment, journal, contra,
credit_note, debit_note` — passed through as the identical internal strings. Order/stock/workflow
types are refused (`422 unsupported_voucher_type`); they have no GET routes.

### Behaviours worth knowing

- **Cross-company** id → `404` (route-model binding through the `BelongsToCompany` scope; 16A hoists
  `IdentifyTenantByApiKey` ahead of `SubstituteBindings`). `X-Company-Id` for an unauthorized
  company → `403` (16A).
- **Real-books-only**: lists and reports filter `scenario_id IS NULL` structurally; `show`/`update`
  refuse a scenario voucher (`404`), and `cancel` scopes its lookup the same way. A scenario voucher
  cannot enter any API response by any parameter or by direct id.
- **Sync outbox**: an API-posted voucher enters `sync_changes` automatically — the shared path fires
  the `RecordsSyncChanges` model trait.
- **StockItem costing lock**: the model's `updating` observer throws `CostingMethodLockedException`;
  the controller catches it and emits `409`, not a 500.
- **Cancel** takes a plain id (not a bound model), so a same-key retry can replay the stored 200
  after the voucher is hard-deleted, instead of 404ing at binding.

---

## Error codes (the machine contract)

`{"error": {"code": "<snake_case>", "message": "…", "details": {…}}}` — inherited from 16A. 16B
adds: `idempotency_key_required` (422), `idempotency_key_reused` (409),
`idempotency_key_in_flight` (409 + Retry-After), `invalid_amount` (422),
`unsupported_voucher_type` (422), `total_mismatch` (422), plus `bad_request` (400) for malformed
cursor/date params. `post()`'s own `ValidationException` keys (`balance`, `gst`, `vat`, `tds`,
`billwise`, `items`, …) surface as `validation_failed` (422) `details`.

---

## Adversarial review — 5 findings, 0 confirmed, 3 fixed anyway

A multi-agent review (6 attackers → 3 independent skeptics per finding) attacked the surface the
brief named: byte divergence, idempotency races, tax smuggling, cross-company/scenario reads,
balance-gate breaks, error leaks. **Nothing survived the 2-of-3 refutation** — the architecture
(shared `post()`, compute-then-verify, insert-first idempotency, real-books-only) held. But three
findings, while "refuted" against the *wrong-accepted-result* bar, described real **contract**
defects — a malformed client input returning **500 instead of 4xx** — and all three are now fixed
and asserted:

1. A non-scalar JSON `amount` (`[]`/`{}`) threw a `TypeError` → 500. Now `ApiMoney` rejects
   non-scalars as a clean **422**.
2. A malformed `?cursor=` threw → 500 + a spurious error-log line. Now a **400 bad_request**.
3. A malformed `?as_of=`/`?date=` on a report threw → 500. Now a **400 bad_request**.

None was a security hole (each is a rejection — no wrong accept, no leak, no double-post), but a
good API answers bad input with 4xx, not 5xx. The **cross-company idempotency replay** (above) was
the one genuinely-important bug; I caught and fixed it before the review returned, and the review
independently confirmed the analysis.

### Two refuted findings recorded honestly (not bugs)

- **Half-cent JS-vs-PHP rounding.** At a line value that rounds differently under JS `Math.round`
  than PHP `round`, the UI's client-side pre-rounding makes `post()` reject the UI's *own*
  submission, while the API (PHP round throughout, matching `post()`'s verify) posts a correct
  voucher. So there is no divergence between two *accepted* vouchers — only the API can post that
  input, and it posts it correctly. This is a pre-existing UI JS quirk, not a 16B defect.
- **`array_filter(null)` on master updates.** `default_tds_section_id` and `deductee_type` cannot be
  set back to null via a master PUT. Verified to have **zero computational consequence**: the TDS
  engine treats a null and an empty `deductee_pan` identically (both trigger the 206AA no-PAN
  floor), and a wrong default section is corrected by sending the right id. Left as-is; the filter
  deliberately prevents accidental nulling of unspecified fields on a partial update.

---

## Verification

```
php artisan zerobook:prove-api-core        # 33rd proof, 69 assertions, real kernel dispatch
```

| § | Covers |
|---|---|
| 1 | Provision + issue keys (scoped) |
| 2 | **CROWN JEWEL** — byte-identical Sales (2 items, GST intra, bill-wise) |
| 3 | **CROWN JEWEL** — byte-identical ₹5,500 TDS catch-up payment |
| 4 | Server-authoritative tax (expected_total mismatch), balance gate, non-scalar amount → 422 |
| 5 | Idempotency — missing key, replay, conflict, in-flight, **exactly one voucher** |
| 6 | Cancel (idempotent replay after hard-delete) + alter through the shared path |
| 7 | Cross-company 404/403 + **cross-company idempotency → 409** |
| 8 | Real-books-only — scenario voucher absent from list, report, and direct-id (404) |
| 9 | Sync outbox sees API vouchers |
| 10 | Reports equal `BalanceService`/`BillService` (paise), malformed date → 400 |
| 11 | Per-route scope enforcement truth table |
| 12 | Cursor pagination — stable ordering, no overlap, limit capped at 100, malformed cursor → 400 |
| 13 | Every `/api/v1` route declares a scope; every write requires idempotency |

**Also verified end-to-end** through a real throwaway tenant + kernel dispatch during development:
issuance, journal + GST sales posting, replay, `total_mismatch`, reports, list — all through the
actual middleware chain.

---

## Scope notes — deferred, as the brief directs

- **Not exposed (404 / rejected, not stubbed):** stock journals, physical stock, order-flow
  vouchers (`sales_order` etc.), multi-currency voucher creation (forex lines via API), scenario
  tagging, consolidation reports, return-file generation, budgets/ratios.
- **Later phases:** outbound webhooks (16C), inbound events (16D), OpenAPI spec + polling (16E).
- **Master field clearing:** `default_tds_section_id`/`deductee_type` cannot be nulled via a master
  PUT (see the review notes) — no computational effect; revisit if a customer needs it.

---

## Files

**Schema** — `database/migrations/tenant/2026_07_28_000001_add_api_idempotency.php` ·
`_docs/phase16b_tenant_schema.sql`

**Services** — `App\Services\Api\VoucherPayloadBuilder` (API shape → internal payload + tax
assembly) · `IdempotencyService` + `IdempotencyOutcome` + `IdempotentOperation` ·
`ApiVoucherException`

**Support** — `App\Support\ApiMoney` (decimal ↔ paise) · `App\Support\Api\CursorPage`

**Middleware** — `RequiresIdempotencyKey`

**Controllers** — `App\Http\Controllers\Api\V1\{VouchersController, LedgersController,
StockItemsController, ReportsController}`

**Resources** — `App\Http\Resources\Api\V1\{VoucherResource, LedgerResource, StockItemResource}`

**Console** — `App\Console\Commands\ApiIdempotencyPruneCommand` (scheduled daily) ·
`ProveApiCoreCommand`

**Wiring** — `routes/api.php` (16B routes) · `routes/console.php` (prune schedule) ·
`bootstrap/app.php` (ApiVoucherException render arm) · `resources/views/docs/api.blade.php` (docs)

---

## For 16C (outbound webhooks)

1. A webhook fires on a voucher/master change. The cleanest trigger is the same `RecordsSyncChanges`
   model event the sync outbox already uses — an API post and a UI post both fire it, so webhooks
   cover every source for free.
2. Idempotency delivery is the mirror of 16B's: sign with HMAC, retry with backoff, and dedupe on
   the receiver via an event id. The `api_request_log` (16A) already stamps a request id per call.
3. Scope is `webhook:manage` (already in the 16A catalog). Declare it on the new routes with
   `->defaults(EnforceApiPermissions::SCOPE_DEFAULT, 'webhook:manage')`.
4. The company-aware idempotency fingerprint (`IdempotencyService::fingerprint`) is the pattern to
   reuse for any new write — never hash the body alone when a header selects the target.
