<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\Godown;
use App\Models\StockItem;
use App\Models\StockLot;
use App\Services\BalanceService;
use App\Services\StockLotService;
use Carbon\Carbon;
use Livewire\Component;

/**
 * Phase 13 — Lot Ledger: the surviving FIFO/LIFO tranches of ONE stock item as
 * of a chosen date. Where Lot Provenance traces inter-company lots, this screen
 * exposes the plain costing ledger for ANY perpetual item — each receipt lot,
 * how much of it is still on hand, at what rate, and its remaining value.
 *
 * Read-only over the lot table: remainingLotsFor() REPLAYS depletion to the
 * as-of date in memory (the same call StockService::closingBalance() uses), so
 * this screen never mutates state and always agrees with valuation.
 */
class LotLedger extends Component
{
    use GuardsActiveCompany;

    public ?int $stockItemId = null;

    public ?string $asOf = null;

    public function mount(): void
    {
        // Default to the first item (by name) that actually carries lots, so the
        // screen lands on something meaningful instead of an empty picker.
        if ($this->stockItemId === null) {
            $ids = StockLot::query()->distinct()->pluck('stock_item_id')->all();
            if (! empty($ids)) {
                $this->stockItemId = StockItem::whereIn('id', $ids)->orderBy('name')->value('id');
            }
        }

        if ($this->asOf === null) {
            $this->asOf = Carbon::today()->toDateString();
        }
    }

    public function render()
    {
        $lotSvc = app(StockLotService::class);
        $asOf = $this->asOf ? Carbon::parse($this->asOf) : Carbon::today();

        $items = StockItem::orderBy('name')->get(['id', 'name', 'costing_method']);

        $item = $this->stockItemId ? StockItem::with('unit')->find($this->stockItemId) : null;
        $method = $item?->costing_method;

        $rows = [];
        if ($item) {
            foreach ($lotSvc->remainingLotsFor($item->id, null, $asOf) as $entry) {
                $lot = $entry['lot'];
                $rows[] = [
                    'received_date' => $lot->received_date?->format('d-M-Y'),
                    'voucher' => $lot->voucher?->displayNumber() ?? ('#'.$lot->voucher_id),
                    'voucher_id' => $lot->voucher_id,
                    // The entry's EFFECTIVE godown (a transfer-split portion reports
                    // its destination, not the root lot's receiving godown).
                    'godown' => ($entry['godown_id'] ?? null) !== null
                        ? (Godown::find($entry['godown_id'])?->name ?? '—')
                        : '—',
                    'original' => (float) $lot->original_qty,
                    'remaining' => $entry['remaining'],
                    'rate' => BalanceService::money($lot->received_rate_paise),
                    'value' => BalanceService::money((int) round($entry['remaining'] * $lot->received_rate_paise)),
                    'source' => $lot->sourceCompany?->name,
                ];
            }
        }

        return view('livewire.reports.lot-ledger', [
            'items' => $items,
            'item' => $item,
            'method' => $method,
            'weightedAverage' => $method === 'weighted_average',
            'rows' => $rows,
            'asOfLabel' => $asOf->format('d-M-Y'),
            'unit' => $item?->unit?->symbol,
        ]);
    }
}
