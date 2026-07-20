<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StockItem;

/**
 * Phase 16B — the canonical JSON shape of a stock item master.
 *
 * `costing_locked` tells an integration up-front that costing_method can no longer change (the
 * item has movements) — so a client can avoid a doomed PUT rather than discovering the 409.
 */
class StockItemResource
{
    public static function make(StockItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'alias' => $item->alias,
            'stock_group_id' => $item->stock_group_id,
            'stock_group_name' => $item->group?->name,
            'unit_id' => $item->unit_id,
            'unit_name' => $item->unit?->name,
            'opening_qty' => (string) $item->opening_qty,
            'opening_rate' => (string) $item->opening_rate,
            'opening_godown_id' => $item->opening_godown_id,
            'gst_rate' => $item->gst_rate !== null ? (string) $item->gst_rate : null,
            'hsn_sac' => $item->hsn_sac,
            'costing_method' => $item->costing_method,
            'costing_locked' => $item->hasStockMovements(),
            'reorder_level' => $item->reorder_level !== null ? (string) $item->reorder_level : null,
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }
}
