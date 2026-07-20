# Phase 14B (Manual) — Manual payment recording & subscription management

The practical billing flow for pilots and bank-transfer customers: the customer pays by
bank / UPI / cheque, records it (or you spot it in your statement), an admin confirms with
the proof snapshot, an invoice is generated, and the tenant's subscription extends. No
payment-gateway API — that's a later **14B-Auto**. This manual flow stays permanently
useful even after automation lands (bank transfers, cheques, annual invoicing, corrections).

---

## Step 0 — audit result

All **25 prior `prove-*` commands pass** (re-verified before building; the full battery is
re-run after). **After 14B-Manual: 26/26 green** (`zerobook:prove-manual-payments`, 47
assertions). No tenant-DB change; nothing in any accounting service was touched.

---

## Payment lifecycle

```
   record (pending)  ──confirm──►  confirmed  ──reverse──►  reversed
        │                              ▲                        │
   customer claim                admin-record             recompute plan_ends_at
        │                        (starts confirmed)             │
        └────reject──► rejected                          (from remaining confirmed)
```

- **`pending`** — a customer claim (`notified_by_user_id` set); no subscription change yet.
- **`confirmed`** — admin verified it (or admin-recorded straight from the bank statement).
  Extends `plan_ends_at`, generates the invoice, emails the tenant, logs the action.
- **`rejected`** — admin declined a claim with a required reason; the customer is emailed.
- **`reversed`** — a confirmed payment corrected later, with a required reason. `plan_ends_at`
  is recomputed from the remaining confirmed payments.

Two entry points, one authority: `SubscriptionService`. `recordPayment()` creates the row
(pending or confirmed); `confirmPayment()` / `rejectPayment()` / `reversePayment()` move it;
and **`recomputePlanEnd()` is the single source of truth for `plan_ends_at`** — nothing
extends the date ad-hoc.

### Chronological recomputation (the crux)

`recomputePlanEnd()` replays the tenant's **confirmed** payments in `(received_at, id)` order,
chaining each period from the previous end (never backdating below a payment's own received
date): `start = max(previous_end, received_at)`, `end = start + billing_period_months`. It
writes the corrected period back onto every payment and sets `plan_ends_at` to the final end
(or NULL when none remain).

This is why a **mid-list reversal is correct**: three periods A→B→C bought in succession chain
A→B→C; reverse B and the replay is A→C, so C's period chains from **A's end**, not from C's own
recorded start. Proven: A(10-Jan→10-Feb), B(→10-Mar), C(→10-Apr); reverse B ⇒ `plan_ends_at` =
**10-Mar** (A's end 10-Feb + C's 1 month), not 10-Apr and not a wrong C start. A naive "sum the
durations" would get this wrong. If a reversal leaves nothing valid, the tenant flips to
`expired_subscription`.

---

## Proof-file access control

Uploaded proofs live in **private** storage: `storage/app/private/payment-proofs/{tenant_id}/{payment_id}.{ext}`
(max 5 MB; jpg/jpeg/png/pdf). Generated invoices live alongside in `payment-invoices/…`. Neither
is ever publicly linked — every read goes through an access-controlled controller:

- **Tenant side** (`/subscription/proof/{payment}`, `/subscription/invoice/{payment}`): behind
  `auth:tenant`; the controller aborts 403 unless `payment.tenant_id === tenant('id')`. So an
  unauthenticated request is redirected to login, and a user of tenant A requesting tenant B's
  proof is denied.
- **Admin side** (`/admin/payments/{payment}/proof`, `/invoice`): behind `auth:platform`; an
  operator may access any tenant's file.

Proven: own-proof access OK, cross-tenant access 403, platform-admin access OK, file on the
private disk and absent from the public disk.

---

## Grace period & enforcement

`plan_ends_at` is the source of truth for paid access; `trial_ends_at` (14A) still governs the
trial. Enforcement is **status-based** (14A semantics preserved):

- When `plan_ends_at` passes, the tenant **stays `active`** for a configurable grace period
  (`config('zerobook.grace_days')`, default 7) with a prominent renewal banner on every screen.
  Writes still work during grace.
- **`zerobook:trial-check`** (extended from 14A) runs daily and flips an active tenant to
  `expired_trial` (no paid sub, trial lapsed) or **`expired_subscription`** (paid sub past grace)
  once neither a trial nor an in-grace subscription remains. A tenant with **neither** date is a
  legacy/unlimited account and is never expired.
- `RequireActiveTenant` + `TenantGate` treat `expired_subscription` as read-only (it joins the
  read-only set): reads always work, writes are blocked with a clear message. The renew-payment
  route is whitelisted so an expired tenant **can still pay to renew**.

Proven: `plan_ends_at = yesterday` → trial-check keeps `active` + grace banner + writable; past
grace → `expired_subscription` → a voucher post is rejected while reads still work.

---

## Customer-notified vs admin-initiated flows

- **Customer-notified** (tenant subdomain → `Subscription` screen): the customer picks a public
  plan in their currency, enters amount / mode / reference / date, uploads a proof snapshot, and
  submits. A `pending` payment is created (`notified_by_user_id` set) and the platform admins are
  emailed. Rate-limited to `config('zerobook.max_claims_per_day')` (default 5) claims per tenant
  per day. The customer sees their pending + confirmed history on the same screen.
- **Admin-initiated** (admin → *Record a payment*): for money seen in the bank statement without a
  claim. The admin picks the tenant, plan, amount, currency, and (optional) proof; it is recorded
  **`confirmed`** immediately and extends the subscription. No `notified_by_user_id`.

The billing currency is the tenant's own (Nepal/VAT → NPR, else INR), derived from its regime.

---

## Invoice generation

On `confirmed`, `InvoiceGenerator` renders a single-page branded receipt PDF with **dompdf**
(`dompdf/dompdf ^3.1`), stores it privately, stamps the number (`ZB-<year>-<id>`) and path on the
payment, and it is attached to the confirmation email + downloadable from both screens. This is a
**receipt for the subscription payment**, not a GST tax invoice from ZeroBook to the customer
(ZeroBook's own GST on its revenue is a separate compliance surface — deferred).

---

## Notification emails (Laravel Mail, simple text)

claim → platform admin · confirm → tenant admin (invoice attached) · reject → tenant admin (reason)
· expiring in 7 days → tenant admin · expired → tenant admin. Reminder emails are sent by
`zerobook:subscription-reminders` (daily, after `trial-check`).

---

## Acceptance checklist → where it's proven

`php artisan zerobook:prove-manual-payments` (47 assertions):

| Criterion | §  |
|---|---|
| Customer claim → pending, notified_by, period, plan_ends_at unchanged, admin email | 1 |
| Admin confirms → confirmed, plan_ends_at extends, active, invoice PDF, email, log | 2 |
| Admin rejects with reason → rejected, plan_ends_at unchanged, customer email | 3 |
| Admin records directly (tenant B) → confirmed immediately, no notified_by | 4 |
| Chronological recompute after mid-list reversal (A's end + C's duration) | 5 |
| Reversal that lapses the subscription → expired_subscription, logged | 6 |
| Grace keeps active + banner; past grace → expired_subscription → **writes blocked**, reads work | 7 |
| Subscription reminder fires 7 days before | 8 |
| Proof access control (own OK · cross-tenant 403 · admin OK · private disk) | 9 |
| Plan pricing change not retroactive (past payment unchanged, new at new price) | 10 |
| Rate limiting — 6th claim/day rejected | 11 |

**Browser-verified:** the tenant Subscription screen renders (trial status, INR-priced public
plans, claim form, history) on `brightmart.localhost`; the admin Pending Payments / Record /
Plans screens and the tenant-detail payment history on `admin.localhost`. 0 console errors.

---

## Files

**Schema (central) + `_docs/phase14b_manual_central_schema.sql`:** migrations
`…000080_extend_plans_for_billing`, `…000090_add_plan_ends_at_to_tenants`,
`…000100_create_payments_table`; `Payment` model; `Plan`/`Tenant`/`PlatformAdmin` updates.

**Service (B/F):** `Services\Subscription\SubscriptionService`, `Services\Subscription\InvoiceGenerator`
(+ `resources/views/invoices/subscription.blade.php`).

**Tenant side (C):** `Livewire\SubscriptionStatus` (+ view), `Http\Controllers\Tenant\TenantSubscriptionController`,
`Support\TenantBilling`, `Support\SubscriptionBanner`, banner in `layouts/app.blade.php`, gateway
entry in `Support\Shell`.

**Admin side (D):** `Http\Controllers\Central\PaymentsController`, `…\PlansController`, views
`central/admin/{payments-pending,payment-record,plans}.blade.php`, payment history on
`central/admin/tenant-detail.blade.php`.

**Middleware/sched (E):** `TrialCheckCommand` (generalized), `SubscriptionRemindersCommand`,
`RequireActiveTenant` whitelist + `expired_subscription` read-only; schedule in `routes/console.php`.

**Notifications (G):** `PaymentClaimReceived`, `PaymentConfirmed`, `PaymentRejected`,
`SubscriptionExpiring`, `SubscriptionExpired`.

**Config:** `config/zerobook.php` (`grace_days`, `reminder_days`, `max_claims_per_day`, `invoice`).

**Proof:** `Console\Commands\ProveManualPaymentsCommand`.

Existing environments: `php artisan migrate` (central) + `composer require dompdf/dompdf`. No
tenant migration.

---

## Scope — NOT in 14B-Manual (honest notes)

- **Automated gateway integration (14B-Auto)** — Razorpay (India) / Khalti (Nepal) / Stripe
  (international). When it lands it will *add* a webhook-driven confirmed-payment path that reuses
  the SAME `SubscriptionService::recordPayment(status: confirmed)` + `recomputePlanEnd()` — so the
  subscription math, invoices, grace, and enforcement built here are already the shared spine; only
  the "who confirms" changes (a verified webhook instead of an admin). The manual flow remains for
  bank transfers, cheques, annual invoicing, corrections, and pilots.
- **GST invoicing from ZeroBook to its customers** — ZeroBook's own GST on subscription revenue is
  a separate compliance surface (ZeroBook's books, not the product's) — its own phase.
- **Per-seat / multi-user billing, coupons/discounts/referrals, auto-renew, mid-cycle proration** —
  deferred (flat per-tenant pricing; every renewal is a fresh manual action).
- **Refunds as a distinct concept** — a refund is a reversal (a bookkeeping action); the actual
  money return happens outside ZeroBook via your bank.
- **14C** — backups, data export, offboarding.
