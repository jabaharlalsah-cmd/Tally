<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Ledger;
use App\Support\ApiMoney;

/**
 * Phase 16B — the canonical JSON shape of a ledger master.
 *
 * Deliberately narrower than the in-app Ledger::toCache(): it exposes the party/tax identity a
 * master:read integration legitimately needs, but this is an authenticated, scope-gated surface,
 * so it is the master endpoint that discloses gstin/state — the ping diagnostic never does.
 */
class LedgerResource
{
    public static function make(Ledger $ledger): array
    {
        return [
            'id' => $ledger->id,
            'name' => $ledger->name,
            'alias' => $ledger->alias,
            'group_id' => $ledger->group_id,
            'group_name' => $ledger->group?->name,
            'opening_balance' => ApiMoney::fromPaise(ApiMoney::toPaise((string) ($ledger->opening_balance ?? '0'))),
            'opening_balance_type' => $ledger->opening_balance_type,
            'is_reserved' => (bool) $ledger->is_reserved,
            'maintain_bill_by_bill' => (bool) $ledger->maintain_bill_by_bill,
            'cost_centres_applicable' => (bool) $ledger->cost_centres_applicable,
            // GST party identity.
            'state' => $ledger->state,
            'gstin' => $ledger->gstin,
            'gst_rate' => $ledger->gst_rate !== null ? (string) $ledger->gst_rate : null,
            'hsn_sac' => $ledger->hsn_sac,
            // TDS deductee identity.
            'deductee_pan' => $ledger->deductee_pan,
            'deductee_type' => $ledger->deductee_type,
            'default_tds_section_id' => $ledger->default_tds_section_id,
            'created_at' => $ledger->created_at?->toIso8601String(),
        ];
    }
}
