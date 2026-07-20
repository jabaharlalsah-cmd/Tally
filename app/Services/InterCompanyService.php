<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Ledger;
use App\Models\Voucher;
use App\Models\VoucherIntercompanyTag;
use App\Support\ActiveCompany;

/**
 * Phase 12B — the inter-company tagging authority.
 *
 * A voucher is INTER-COMPANY when any of its lines posts to a party ledger whose
 * linked_company_id names a company in the SAME GROUP as the active company, at
 * post time. The consequences, all server-derived (the client's declaration is
 * verified, never trusted):
 *
 *   • the payload MUST declare the tag (intercompany.counterparty_company_id);
 *   • the declared counterparty MUST equal the derived one;
 *   • a declared tag with NO justifying line is refused (a false-positive tag
 *     would corrupt 12C's elimination);
 *   • one voucher may touch ONE inter-company counterparty (a tag row holds one);
 *   • persist() re-derives everything and records the tag inside the posting
 *     transaction, stamping counterparty_ledger_id when exactly one ledger in the
 *     counterparty company links back to the active one (the reciprocal mirror).
 *
 * INERT BY DEFAULT — the CA-firm guarantee: derivation requires group membership
 * at post time, so an ungrouped tenant (or a linked ledger whose target left the
 * group) never sees any of this. Two same-tenant companies NOT in one group
 * transact as ordinary parties: no tag required, none allowed.
 *
 * Sits ABOVE the 12A isolation layer: every ledger read here is company-scoped;
 * the single cross-company read (the reciprocal-mirror lookup) uses the
 * documented withoutGlobalScope + explicit company_id pattern.
 */
class InterCompanyService
{
    /** Party-tracking roots — a ledger is linkable iff its group is/descends from one. */
    public const PARTY_GROUP_ROOTS = [
        'Sundry Debtors', 'Sundry Creditors',
        'Loans & Advances (Asset)', 'Loans (Liability)',
    ];

    private ?CompanyGroup $group = null;

    private bool $groupResolved = false;

    /** The active company's group (memoised per service instance), or null. */
    public function group(): ?CompanyGroup
    {
        if (! $this->groupResolved) {
            $this->group = CompanyGroup::forCompany(ActiveCompany::id());
            $this->groupResolved = true;
        }

        return $this->group;
    }

    /** True when the active company is a member of a group. */
    public function enabled(): bool
    {
        return $this->group() !== null;
    }

    /** Ids of the OTHER companies in the active company's group ([] if none). */
    public function groupmateIds(): array
    {
        $group = $this->group();

        if (! $group || ActiveCompany::id() === null) {
            return [];
        }

        return $group->companies()->whereKeyNot(ActiveCompany::id())
            ->pluck('companies.id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * Derive the inter-company counterparty from the payload's lines: the distinct
     * linked companies (of the lines' ledgers) that are GROUPMATES of the active
     * company. Returns [] — not inter-company; [id] — the counterparty; 2+ ids —
     * invalid (one voucher, one counterparty).
     */
    public function deriveCounterparties(array $lines): array
    {
        $mates = $this->groupmateIds();

        if ($mates === []) {
            return [];
        }

        $ledgerIds = array_values(array_filter(array_map(
            fn ($l) => (int) ($l['ledger_id'] ?? 0), $lines
        )));

        if ($ledgerIds === []) {
            return [];
        }

        // Company-scoped read — a crafted foreign ledger id resolves to nothing.
        $linked = Ledger::whereIn('id', $ledgerIds)
            ->whereNotNull('linked_company_id')
            ->pluck('linked_company_id')
            ->map(fn ($i) => (int) $i)
            ->unique()
            ->filter(fn ($id) => in_array($id, $mates, true))
            ->values()
            ->all();

        return $linked;
    }

    /**
     * THE SERVER-AUTHORITY HOOK (VoucherScreen::validatePayload after-chain).
     * Returns an error string, or null when the payload is consistent.
     */
    public function verifyPayload(array $payload): ?string
    {
        $declared = isset($payload['intercompany']['counterparty_company_id'])
            ? (int) $payload['intercompany']['counterparty_company_id']
            : null;

        // A forex REVALUATION journal (Phase 11) is a valuation adjustment of the
        // book's own carrying values, not a transaction WITH the counterparty — and
        // one journal may touch several linked parties at once. It is exempt from
        // the mandatory tag (12C treats FX revaluation separately); a DECLARED tag
        // on one is still refused so no false tag can ride the exemption.
        if (! empty($payload['forex_revaluation'])) {
            return $declared !== null
                ? 'A revaluation journal is a valuation adjustment — it cannot carry an inter-company tag.'
                : null;
        }

        // Ungrouped mode — inert. But a DECLARED tag is refused loudly rather than
        // silently dropped: it would vanish from 12C's books without this.
        if (! $this->enabled()) {
            return $declared !== null
                ? 'This company is not in a company group — an inter-company tag cannot be declared.'
                : null;
        }

        $derived = $this->deriveCounterparties($payload['lines'] ?? []);

        if (count($derived) > 1) {
            $names = Company::whereIn('id', $derived)->pluck('name')->implode('” and “');

            return "One voucher cannot touch two inter-company counterparties (“{$names}”) — split it into one voucher per counterparty.";
        }

        if ($derived === []) {
            return $declared !== null
                ? 'The payload declares an inter-company tag, but no line posts to a ledger linked to a company in this group.'
                : null;
        }

        $counterpartyId = $derived[0];

        if ($declared === null) {
            $name = Company::find($counterpartyId)?->name ?? ('#'.$counterpartyId);

            return "This voucher touches an inter-company party (“{$name}”) but the inter-company tag is missing.";
        }

        if ($declared !== $counterpartyId) {
            $derivedName = Company::find($counterpartyId)?->name ?? ('#'.$counterpartyId);
            $declaredName = Company::find($declared)?->name ?? ('#'.$declared);

            return "The ledger declares counterparty “{$derivedName}” but the payload claims “{$declaredName}”.";
        }

        return null;
    }

    /**
     * Record the tag inside the posting transaction. Everything is RE-DERIVED from
     * the persisted lines — verifyPayload() already proved the payload consistent,
     * and the derivation is the single source of truth.
     */
    public function persist(Voucher $voucher, array $data): void
    {
        if (! $this->enabled() || ! empty($data['forex_revaluation'])) {
            return; // ungrouped, or an exempt revaluation journal (see verifyPayload)
        }

        $derived = $this->deriveCounterparties($data['lines'] ?? []);

        if (count($derived) !== 1) {
            return; // not inter-company (deriveCounterparties>1 was rejected upstream)
        }

        VoucherIntercompanyTag::create([
            'voucher_id' => $voucher->id,
            'counterparty_company_id' => $derived[0],
            'counterparty_ledger_id' => $this->reciprocalLedgerId($derived[0]),
            'created_at' => now(),
        ]);
    }

    /** Alter/cancel — drop this voucher's tag before the rewrite re-derives it. */
    public function reverseFor(Voucher $voucher): void
    {
        VoucherIntercompanyTag::where('voucher_id', $voucher->id)->delete();
    }

    /**
     * The mirror ledger in the counterparty company — the ONE ledger there whose
     * linked_company_id points back at the active company. Null when none or
     * several (ambiguous). The one deliberate cross-company read in 12B, using the
     * documented withoutGlobalScope + explicit company_id pattern (CompanyWorkspace
     * precedent) — the isolation trait itself is untouched.
     */
    public function reciprocalLedgerId(int $counterpartyCompanyId): ?int
    {
        $ids = Ledger::withoutGlobalScope('company')
            ->where('company_id', $counterpartyCompanyId)
            ->where('linked_company_id', ActiveCompany::check())
            ->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /**
     * Ledger-master guard: may a ledger with this account group be linked to that
     * company? Returns an error string or null. Enforces: active company grouped;
     * target a GROUPMATE (same group, not self); ledger group party-tracking.
     */
    public function assertLinkable(?int $linkedCompanyId, ?int $accountGroupId): ?string
    {
        if ($linkedCompanyId === null) {
            return null; // clearing the link is always fine
        }

        if (! $this->enabled()) {
            return 'Linking a party to another company needs a company group — create one under Companies › Groups first.';
        }

        if ($linkedCompanyId === ActiveCompany::id()) {
            return 'A ledger cannot be linked to its own company.';
        }

        if (! in_array($linkedCompanyId, $this->groupmateIds(), true)) {
            $name = Company::find($linkedCompanyId)?->name ?? ('#'.$linkedCompanyId);
            $group = $this->group()?->name;

            return "“{$name}” is not a member of this company's group (“{$group}”) — only same-group companies can be linked.";
        }

        if (! $this->isPartyTrackingGroup($accountGroupId)) {
            return 'Only a party-tracking ledger (Sundry Debtors/Creditors, Loans & Advances, Loans) can be linked to another company.';
        }

        return null;
    }

    /** True when the account group is, or descends from, a party-tracking root. */
    public function isPartyTrackingGroup(?int $accountGroupId): bool
    {
        $group = $accountGroupId ? \App\Models\AccountGroup::find($accountGroupId) : null;
        $guard = 0;

        while ($group && $guard++ < 12) {
            if (in_array($group->name, self::PARTY_GROUP_ROOTS, true)) {
                return true;
            }
            $group = $group->parent_id ? \App\Models\AccountGroup::find($group->parent_id) : null;
        }

        return false;
    }

    /** Client boot payload — everything the screen needs to derive the badge with 0 network. */
    public function bootData(): array
    {
        $group = $this->group();
        $mates = $this->groupmateIds();

        return [
            'interCompanyEnabled' => $group !== null,
            'interCompanyGroupName' => $group?->name,
            'interCompanyGroupCompanyIds' => $mates,
            'interCompanyCompanyNames' => $mates === []
                ? (object) []
                : (object) Company::whereIn('id', $mates)->pluck('name', 'id')->all(),
        ];
    }
}
