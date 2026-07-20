<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 12C-1 → Phase 13 — one costing lot of inventory.
 *
 * TWO PURPOSES, ONE TABLE:
 *   • INTER-COMPANY provenance (12C-1) — units received from a groupmate; source_company_id +
 *     best-effort source_voucher_id/source_cost_paise are populated; costing_method = 'fifo'
 *     (12C-1 depletes FIFO by received_date). Consumed by the Lot Ledger + 12C-2 elimination.
 *   • GENERAL FIFO/LIFO costing (13) — a lot written for every IN movement of an item flagged
 *     'fifo' or 'lifo'; the source_* columns are null; costing_method snapshots the item's method.
 *     Here the lot IS valuation: an OUT costs from the specific lots it depletes.
 *
 * remaining_qty is a PURE FUNCTION of (root lots + chronological real OUT/transfer events), so alter
 * and cancel repair it by replay (StockLotService::refoldItem). Weighted-average items write no lots.
 */
class StockLot extends Model
{
    use BelongsToCompany;

    protected $table = 'stock_lots';

    protected $fillable = [
        'stock_item_id', 'godown_id', 'voucher_id', 'stock_entry_id', 'parent_lot_id',
        'source_company_id', 'source_voucher_id', 'original_qty', 'remaining_qty',
        'source_cost_paise', 'received_rate_paise', 'received_date', 'costing_method',
    ];

    protected $casts = [
        'original_qty' => 'float',
        'remaining_qty' => 'float',
        'source_cost_paise' => 'integer',
        'received_rate_paise' => 'integer',
        'received_date' => 'date',
    ];

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }

    public function godown()
    {
        return $this->belongsTo(Godown::class);
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function stockEntry()
    {
        return $this->belongsTo(StockEntry::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_lot_id');
    }

    public function sourceCompany()
    {
        return $this->belongsTo(Company::class, 'source_company_id');
    }
}
