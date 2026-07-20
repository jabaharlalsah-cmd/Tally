<?php

namespace App\Services;

use App\Models\OrderFulfillment;
use App\Models\OrderLine;
use App\Models\Voucher;
use App\Support\ScenarioContext;
use Illuminate\Validation\ValidationException;

/**
 * Phase 8B — the order/commitment reconciliation engine.
 *
 * Sales/Purchase Orders persist commitment lines here (no stock, no accounting). When
 * a Delivery/Receipt Note references an order, each delivered line adds an
 * order_fulfillment and pushes up the order line's delivered_qty — which is ALWAYS
 * kept equal to the sum of its fulfillments, so reversal on alter/cancel is a delete
 * + re-sum. Over-delivery beyond ordered_qty is refused server-side. Getting this
 * wrong leaves inventory in phantom limbo, so it is the one thing proved to the unit.
 */
class OrderService
{
    /** Persist the commitment lines of a Sales/Purchase Order (no stock, no ledger). */
    public function persistOrderLines(Voucher $order, array $items): void
    {
        $lineNo = 1;
        foreach ($items as $it) {
            if (empty($it['stock_item_id'])) {
                continue;
            }
            $qty = (float) ($it['qty'] ?? 0);
            $rate = (float) ($it['rate'] ?? 0);
            OrderLine::create([
                'voucher_id' => $order->id,
                'stock_item_id' => (int) $it['stock_item_id'],
                'godown_id' => ! empty($it['godown_id']) ? (int) $it['godown_id'] : null,
                'ordered_qty' => $qty,
                'delivered_qty' => 0,
                'rate' => $rate,
                'amount' => round($qty * $rate, 2),
                'line_no' => $lineNo++,
            ]);
        }
    }

    /**
     * Apply a Delivery/Receipt Note's stock rows against the order it references:
     * one fulfillment per matched item line, over-delivery refused. Called inside the
     * post transaction, AFTER the stock_entries are written (so we match by item).
     */
    public function applyFulfillment(Voucher $fulfilment): void
    {
        if (! $fulfilment->reference_voucher_id) {
            return; // free-standing delivery — nothing to reconcile
        }
        $order = Voucher::find($fulfilment->reference_voucher_id);
        if (! $order || ! in_array($order->type, Voucher::ORDER_TYPES, true)) {
            return; // reference isn't an order (e.g. a delivery referencing nothing relevant)
        }

        // Lock the order's lines so two concurrent deliveries can't both slip past the
        // over-delivery gate.
        $orderLines = OrderLine::where('voucher_id', $order->id)->lockForUpdate()->get();

        $lineNo = 1;
        // Query (not the relation property) so a re-post on alter reads the freshly
        // written rows, never a stale relation cache.
        foreach ($fulfilment->stockEntries()->get() as $se) {
            $line = $orderLines->firstWhere('stock_item_id', $se->stock_item_id);
            if (! $line) {
                continue; // delivering an item not on the order is allowed, just not tracked
            }

            $already = (float) OrderFulfillment::where('order_line_id', $line->id)->sum('qty');
            $moving = (float) $se->quantity;
            if ($already + $moving > (float) $line->ordered_qty + 1e-6) {
                throw ValidationException::withMessages([
                    'items' => sprintf(
                        'Cannot deliver more than ordered for “%s”: ordered %s, already delivered %s, this note %s.',
                        $line->stockItem?->name ?? 'item',
                        rtrim(rtrim(number_format((float) $line->ordered_qty, 4), '0'), '.'),
                        rtrim(rtrim(number_format($already, 4), '0'), '.'),
                        rtrim(rtrim(number_format($moving, 4), '0'), '.'),
                    ),
                ]);
            }

            OrderFulfillment::create([
                'order_line_id' => $line->id,
                'fulfillment_voucher_id' => $fulfilment->id,
                'qty' => $moving,
                'line_no' => $lineNo++,
            ]);
            $this->recompute($line);
        }
    }

    /**
     * Undo a fulfilment voucher's impact on its order (on alter or cancel): delete its
     * fulfillment rows and re-sum the affected order lines' delivered_qty. Deterministic
     * because delivered_qty is only ever the sum of the fulfillments that remain.
     */
    public function reverseFulfillment(Voucher $fulfilment): void
    {
        $lineIds = OrderFulfillment::where('fulfillment_voucher_id', $fulfilment->id)
            ->pluck('order_line_id')->unique();
        if ($lineIds->isEmpty()) {
            return;
        }
        OrderFulfillment::where('fulfillment_voucher_id', $fulfilment->id)->delete();
        foreach ($lineIds as $lid) {
            $line = OrderLine::find($lid);
            if ($line) {
                $this->recompute($line);
            }
        }
    }

    private function recompute(OrderLine $line): void
    {
        $line->delivered_qty = (float) OrderFulfillment::where('order_line_id', $line->id)->sum('qty');
        $line->save();
    }

    /**
     * Phase 8B — the Orders Outstanding report: every order of the given type(s) that
     * still has pending (undelivered) quantity, with its open lines. A fully-delivered
     * order drops off entirely; the order voucher itself is never deleted (audit trail).
     *
     * @param  array<int,string>  $orderTypes
     * @return array<int,array>
     */
    public function outstanding(array $orderTypes): array
    {
        return Voucher::whereIn('type', $orderTypes)
            ->tap(fn ($q) => ScenarioContext::apply($q, 'vouchers'))
            ->with(['partyLedger', 'orderLines.stockItem.unit'])
            ->orderBy('date')->orderBy('id')->get()
            ->map(function ($v) {
                $lines = $v->orderLines
                    ->filter(fn ($ol) => $ol->pendingQty() > 1e-9)
                    ->map(fn ($ol) => [
                        'item' => $ol->stockItem?->name ?? '—',
                        'unit' => $ol->stockItem?->unit?->symbol ?? '',
                        'ordered' => (float) $ol->ordered_qty,
                        'delivered' => (float) $ol->delivered_qty,
                        'pending' => round($ol->pendingQty(), 4),
                        'rate' => (float) $ol->rate,
                        'pending_value' => round($ol->pendingQty() * (float) $ol->rate, 2),
                    ])->values()->all();

                return [
                    'id' => $v->id,
                    'type' => $v->type,
                    'type_label' => Voucher::TYPES[$v->type]['label'] ?? ucfirst($v->type),
                    'display_number' => $v->displayNumber(),
                    'date_label' => $v->date->format('d-M-Y'),
                    'party' => $v->partyLedger?->name ?? '—',
                    'lines' => $lines,
                    'pending_value' => array_sum(array_column($lines, 'pending_value')),
                ];
            })
            ->filter(fn ($o) => count($o['lines']) > 0)
            ->values()->all();
    }
}
