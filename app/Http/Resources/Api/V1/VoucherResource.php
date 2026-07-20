<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Voucher;
use App\Support\ApiMoney;

/**
 * Phase 16B — the canonical JSON shape of a voucher. ONE place, so create/read/list/alter never
 * disagree on the shape.
 *
 * Money is decimal strings (via ApiMoney, parsed exactly from the decimal:2 columns — never a
 * float). Dates are ISO-8601. Ids are integers. The `amount` is the voucher total (Σ Dr) in the
 * same paise arithmetic the books use.
 */
class VoucherResource
{
    /** @param  Voucher  $voucher  eager-loaded with entries.ledger, stockEntries.stockItem+godown, billAllocations */
    public static function make(Voucher $voucher): array
    {
        $totalPaise = 0;
        foreach ($voucher->entries as $e) {
            if ($e->dr_cr === 'Dr') {
                $totalPaise += ApiMoney::toPaise((string) $e->amount);
            }
        }

        return [
            'id' => $voucher->id,
            'type' => $voucher->type,
            'type_label' => Voucher::TYPES[$voucher->type]['label'] ?? ucfirst($voucher->type),
            'number' => (int) $voucher->number,
            'display_number' => $voucher->displayNumber(),
            'date' => $voucher->date->toDateString(),
            'narration' => $voucher->narration,
            'party_ledger_id' => $voucher->party_ledger_id,
            'reference_no' => $voucher->reference_no,
            'reference_date' => $voucher->reference_date?->toDateString(),
            'reference_voucher_id' => $voucher->reference_voucher_id,
            'amount' => ApiMoney::fromPaise($totalPaise),
            'lines' => $voucher->entries->map(fn ($e) => [
                'ledger_id' => (int) $e->ledger_id,
                'ledger_name' => $e->ledger?->name,
                'dr_cr' => $e->dr_cr,
                'amount' => ApiMoney::fromPaise(ApiMoney::toPaise((string) $e->amount)),
            ])->values()->all(),
            'items' => $voucher->stockEntries->map(fn ($s) => [
                'stock_item_id' => (int) $s->stock_item_id,
                'stock_item_name' => $s->stockItem?->name,
                'godown_id' => $s->godown_id,
                'direction' => $s->direction,
                'quantity' => (string) $s->quantity,
                'rate' => (string) $s->rate,
                'value' => ApiMoney::fromPaise(ApiMoney::toPaise((string) $s->value)),
                'sale_rate' => $s->sale_rate === null ? null : (string) $s->sale_rate,
                'sale_value' => $s->sale_value === null ? null : ApiMoney::fromPaise(ApiMoney::toPaise((string) $s->sale_value)),
            ])->values()->all(),
            'bill_allocations' => $voucher->billAllocations->map(fn ($b) => [
                'ledger_id' => (int) $b->ledger_id,
                'ref_type' => $b->ref_type,
                'ref_name' => $b->ref_name,
                'amount' => ApiMoney::fromPaise(ApiMoney::toPaise((string) $b->amount)),
                'due_date' => $b->due_date?->toDateString(),
            ])->values()->all(),
            'created_at' => $voucher->created_at?->toIso8601String(),
        ];
    }
}
