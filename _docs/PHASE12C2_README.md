# ZeroBook — Phase 12C-2: group consolidation reports

**Status: done and proven. This closes the multi-company arc.**
`php artisan zerobook:prove-consolidation` → **41 assertions, 0 failures** — every checklist number
exact. Full cold regression: **24/24** prove-commands green. **No migration** — consolidation is a
read-time computation over 12B's tags and 12C-1's lots; no settings table was needed (the
reserve-ledger question resolves to a report line, below). An adversarial multi-agent review
(4 lenses × 2-refuter verification) ran over the finished build — see "Adversarial review".

Three reports — **Group Trial Balance, Group Balance Sheet, Group P&L** — sum a group's member
companies and apply two elimination layers, **without posting anything anywhere**: the individual
books are untouched, which is the audit requirement.

---

## Step 0 — audit result

12C-1's true-final regression closed at **23/23 green**, zero changes since. The elimination sources
were read before building: `voucher_intercompany_tags` (both-sides-in-group filter), 12C-1's
`remainingLotsFor(item, godown, asOf)` (the self-healing in-memory replay), `BalanceService`
(`ledgerBalances`' per-ledger opening/net/closing in Dr-paise is the aggregation foundation), and the
`withoutGlobalScope + whereIn(company_id, members)` cross-company pattern — reused, never widened.
**The retained-earnings question (0.4):** the seeded chart has a *Reserves & Surplus* group but **no
reserve ledger** — so the unrealised adjustment lands on an explicit **"Consolidation Adjustments"**
report line (never a posting). Documented choice.

## The elimination rules — with the exact worked numbers

**The canonical scenario** (asserted to the paise): A buys 100 widgets @₹100 outside (₹10,000);
A sells all 100 to B @₹120 (₹12,000, tagged); B sells 30 outside @₹150 (₹4,500).

| Group figure | Value | Why |
|---|---|---|
| Sales revenue | **₹4,500** | A's ₹12,000 inter-company sale eliminated |
| Purchases | **₹10,000** | B's ₹12,000 inter-company purchase eliminated |
| Unrealised elimination | **₹1,400** | 70 units on hand × (₹120 − ₹100) |
| Group Stock-in-Hand | **₹7,000** | 70 × ₹100 group cost — not B's ₹8,400 |
| Group Net Profit | **₹1,500** | = ΣNets ₹2,900 (A ₹2,000 + B ₹900) − ₹1,400 — **the tie check** |
| Balance Sheet | **₹11,500 = ₹11,500** | receivable/payable pair (₹12,000 each) vanished; outside balances intact |

**Complete elimination** joins `voucher_entries ⋈ voucher_intercompany_tags` where **both sides are
current members** (the 12B stale-tag question, decided: current membership; a tag whose counterparty
left the group no longer eliminates — and, per the adversarial review below, is surfaced rather than
dropped), summed signed per (company, ledger), categorised for the panel (sales / purchases /
receivables / payables / other). Balance-Sheet basis: since book start; P&L display basis: the period.
A one-sided flow (only one company posted its half) shows as an **elimination mismatch** and a failed
tie check — surfaced, never hidden.

**Unrealised-profit elimination** consumes `remainingLotsFor(asOf)` per receiving member (under
`runAs` — the scoped, self-healing 12C-1 query, verbatim): matched lots eliminate
`(received_rate − source_cost) × remaining`; **unmatched lots** (null source cost) are listed with
quantity and at-receipt value and eliminate **nothing** — proven: rigging a lot unmatched left the
matched elimination at ₹1,400, showed "10 units, ₹1,300", kept the BS balanced, and kept the tie.

## The arithmetic spine

One foundation, three reports: per-member **adjusted ledger rows** = `ledgerBalances()` − tagged
contributions, each row carrying its account-group name (the cross-company identity), root and nature.

- **Group TB** — adjusted rows rolled up by group name; Dr/Cr recomputed *from the adjusted rows*, so
  the balance flag reflects reality (asymmetric eliminations show).
- **Group P&L** — 6D's exact stock math on the closing basis, with
  `opening_stock − unrealised(from−1)` and `closing_stock − unrealised(to)`: the P&L recognises only
  the *movement* in unrealised profit. **The tie check** — `net == ΣmemberNets − Δunrealised` — is
  computed and displayed on the report itself; when it fails, the books have a real inconsistency (a
  one-sided flow), and the panel says which kind.
- **Group BS** — nature rollup + group stock injection + the group net + **Consolidation
  Adjustments = −unrealised(from)** (the prior-period portion that cannot flow through this period's
  P&L). Balanced by construction; any residual `difference` is the members' own Tally-style
  opening-balance difference, **inherited faithfully** and rendered with the per-company convention's
  label (verified live: group difference == Σ member differences, to the paise).

## The screens

Three Livewire reports under **Group Reports** (Gateway letter **7**, Go To keywords group trial /
group balance / group p&l / consolidation) — **visible only when the active company is in a group**
(verified: ungrouped tenant → 0 nav entries, 0 gateway entry). Group picker (multi-group tenants
consolidate independently), F2 period, ↑/↓ + Enter drill from group figures into per-company ledger
rows with "eliminated ₹X" badges (cross-company voucher drill is by design a company switch away —
the 12A binding scope 404s foreign URLs). Every screen carries the **Consolidation Adjustments audit
panel**: elimination totals by category, tagged-voucher count, unrealised total, ⚠ unmatched lots,
⚠ unaccounted (untagged) inter-company vouchers, ⚠ elimination asymmetry — the CA's first stop.

## Proof beyond the canonical numbers (`prove-consolidation`, 41/0)

- **Untagged inter-group voucher** (posted pre-group, when 12B was inert) → surfaced in
  "Unaccounted" with type and amount; its ₹100 residue visible in Sundry Debtors — never
  silently double-counted.
- **Read-only guarantee** — after all three reports: both members' individual TBs still balanced and
  zero vouchers exist outside the two companies.
- **Single-member group** — group net == member net, zero eliminations, TB balanced (consolidation
  degenerates to the individual reports, as specified).
- **Mixed base currencies** — refused naming both codes (INR, USD) on every report surface; restored
  and re-runs.
- Browser-verified live on a two-company group: all three reports render, the audit panel shows the
  demo tenant's real unmatched lot and untagged (pre-group + revaluation) vouchers, keyboard context
  active with Enter-to-expand drills, **0 console errors**.

## Adversarial review — found and FIXED before delivery

A 4-lens hunt (consolidation arithmetic, isolation/read pattern, elimination edges, screens/lifecycle)
surfaced findings whose automated verification was cut short by a session limit, so they were
adjudicated by hand against the code and live probes. **Real defects found and fixed:**

1. **(HIGH) the elimination-asymmetry detector was structurally dead.** It was `Σ Dr − Σ Cr` over the
   eliminated contributions — but every eliminated voucher is internally Dr = Cr, so that difference is
   **identically zero by construction** and could never fire. A one-sided tagged *settlement* (A
   records paying B; B never records the receipt — balance-sheet-only legs, no P&L, so the tie check
   also can't see it) was silently absorbed. **Fix:** the detector is now the **signed sum of
   eliminations on linked party ledgers** — across a symmetric group A's receivable-from-B and B's
   payable-to-A are equal and opposite (net 0); a non-zero total is a genuinely one-sided
   inter-company balance. Proven: a ₹500 one-sided settlement now surfaces (`mismatch = 50000`), the
   panel flags it, the TB still balances (it's *surfaced*, not injected), and posting B's half closes
   it to 0 — plus the canonical symmetric flow stays at 0.
2. **(MEDIUM) the group reports recomputed the whole pipeline 3–4× per render** (Balance Sheet →
   P&L, each re-deriving eliminations + adjusted rows + the FIFO unrealised replay). **Fix:** a
   per-request memo on the three heavy pure functions — one resolved instance per request, so the
   cache never crosses requests (the proof, which mutates between views, resolves a fresh instance per
   view to model that faithfully). The date inputs also moved from `wire:model.live` (a fire per
   date-segment keystroke) to `wire:model.blur`.
3. **(MEDIUM) the keyboard context hijacked arrows/Enter inside the group select and date inputs.**
   **Fix:** an `inField()` guard (the sibling reports' `inPeriodInput` discipline) — row movement and
   section-expand no-op while a form control is focused, and Enter/F2 are `allowInInput`.
4. **(LOW) an ungrouped company reaching a group-report URL directly got the first group's books.**
   Verified *not* a privilege escalation (tenant users already see all companies — groups are
   tenant-visible by the 12B decision), but untidy. **Fix:** the default is now the *active company's*
   group only; an ungrouped company sees "your active company isn't in a group — switch or pick one",
   never a surprise consolidation.

**Adjudicated as design/scope, not defects, and documented:** a cross-classified inter-company transfer
(A books a *sale*, B capitalises a *fixed asset*) is outside 12C-2's inventory-focused scope and
already surfaces as a **failed tie check** with the panel to investigate — the honest boundary, not a
silent miscalc. A tagged voucher against a company that has since *left* the group is treated as an
ordinary external transaction (verdict: aggregation is correct — an ex-member is external). Livewire
morph collapses an expanded drill on a data change — correct, the data changed.

---

## Files

**Engine** — `app/Services/GroupConsolidationService.php` (member scope, currency guard, complete +
unrealised eliminations, untagged surfacing, adjusted rows, the three reports, the panel).

**Screens** — `app/Livewire/Reports/{GroupReportBase, GroupTrialBalance, GroupBalanceSheet,
GroupProfitAndLoss}.php` · three report blades + `partials/group-report-shell` (controls + audit
panel) · wrappers · routes (`reports/group-*`) · Shell nav + gateway '7' (grouped-only) ·
`groupReport()` keyboard controller in `reports/screen.js`.

**Proof** — `app/Console/Commands/ProveConsolidationCommand.php` (37/0).

---

## Out of scope (named, not stubbed)

- **Auto-posting consolidation entries** — never; the consolidation is a view. Individual books stay
  untouched (audit-critical), mirroring how Tally treats group companies.
- **Multi-level groups** — flat, matching 12B; sub-consolidation into a parent group is a future tier.
- **Cross-currency consolidation** — mixed-base groups are refused with a clear message; period-end
  translation (CTA, closing/average rates) is its own subsystem.
- **Minority / non-controlling interest** — 100% ownership assumed; partial-ownership eliminations are
  a real corporate feature, deferred.
- **Consolidated cash flow** — the individual companies have no cash-flow statement yet; group-level
  first would be backwards.
- **Inter-company loan interest** — ordinary income/expense; it eliminates when its journal vouchers
  carry the 12B tag (which properly-linked party ledgers give them automatically).

No migration this phase. Existing tenants need nothing beyond 12C-1's state.

**The multi-company arc — 12A isolation, 12B tagging, 12C-1 lot provenance, 12C-2 consolidation — is
complete: 24/24 proofs green.** What remains (13 FIFO/LIFO general costing, 14 SaaS operations,
15 budgets/ratios/scenarios) doesn't block selling; sequence by customer demand.
