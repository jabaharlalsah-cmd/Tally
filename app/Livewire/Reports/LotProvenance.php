<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\StockLot;
use App\Models\StockItem;
use App\Services\BalanceService;
use App\Services\StockLotService;
use App\Services\InterCompanyService;
use Carbon\Carbon;
use Livewire\Component;

/**
 * Phase 12C-1 — Lot Provenance: which units on hand came from group-internal
 * purchases, from whom, at what transfer price, and (best-effort) at what cost
 * to the group. This is exactly the data 12C-2's unrealised-profit elimination
 * consumes — surfaced early so users can see (and fix) unmatched lots.
 *
 * Read-only over the lot table; valuation untouched. With an as-of date the
 * remaining quantities are REPLAYED to that date in memory (the same
 * remainingLotsFor() call 12C-2 uses), so this screen never mutates state.
 */
class LotProvenance extends Component
{
    use GuardsActiveCompany;

    public ?int $stockItemId = null;

    public string $asOf = '';

    public function mount(): void
    {
        $this->asOf = Carbon::today()->toDateString();
    }

    public function render()
    {
        $ic = app(InterCompanyService::class);
        $lotSvc = app(StockLotService::class);
        $asOf = $this->asOf ? Carbon::parse($this->asOf) : Carbon::today();

        $itemIds = $this->stockItemId
            ? [$this->stockItemId]
            : StockLot::query()->distinct()->pluck('stock_item_id')->all();

        $rows = [];
        $unmatchedLotIds = [];
        foreach ($itemIds as $itemId) {
            $item = StockItem::with('unit')->find($itemId);
            if (! $item) {
                continue;
            }
            foreach ($lotSvc->remainingLotsFor($itemId, null, $asOf) as $entry) {
                $lot = $entry['lot'];
                if ($lot->source_voucher_id === null) {
                    $unmatchedLotIds[] = $lot->id; // distinct roots — splits share the id
                }
                $rows[] = [
                    'item' => $item->name,
                    'unit' => $item->unit?->symbol,
                    // the entry's EFFECTIVE godown (a transfer-split portion reports
                    // its destination, not the root's receiving godown)
                    'godown' => ($entry['godown_id'] ?? null) !== null
                        ? (\App\Models\Godown::find($entry['godown_id'])?->name ?? '—')
                        : '—',
                    'source_company' => $lot->sourceCompany?->name ?? '—',
                    'received_date' => $lot->received_date?->format('d-M-Y'),
                    'receiving_voucher_id' => $lot->voucher_id,
                    'receiving_voucher' => $lot->voucher?->displayNumber() ?? ('#'.$lot->voucher_id),
                    'source_voucher_id' => $lot->source_voucher_id,
                    'original' => (float) $lot->original_qty,
                    'remaining' => $entry['remaining'],
                    'received_rate' => BalanceService::money($lot->received_rate_paise),
                    'source_cost' => $lot->source_cost_paise !== null ? BalanceService::money($lot->source_cost_paise) : null,
                    // The markup hint only makes sense on PURCHASE-side lots: a
                    // return-leg lot (Credit Note / Rejection In) carries our original
                    // cost as received_rate and the counterparty's (marked-up) average
                    // as source_cost — 12C-2 treats those separately (see README).
                    'unrealised_hint' => ($lot->source_cost_paise !== null
                            && in_array($lot->voucher?->type, ['purchase', 'receipt_note'], true))
                        ? BalanceService::money((int) round(($lot->received_rate_paise - $lot->source_cost_paise) * $entry['remaining']))
                        : null,
                ];
            }
        }

        return view('livewire.reports.lot-provenance', [
            'enabled' => $ic->enabled(),
            'groupName' => $ic->group()?->name,
            'rows' => $rows,
            'unmatchedCount' => count(array_unique($unmatchedLotIds)),
            'items' => StockItem::orderBy('name')->get(['id', 'name']),
            'asOfLabel' => $asOf->format('d-M-Y'),
        ]);
    }
}
