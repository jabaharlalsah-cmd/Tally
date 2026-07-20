<?php

namespace App\Services;

use App\Models\StockEntry;
use App\Models\StockItem;
use App\Models\Voucher;
use App\Services\TallyImport\BulkMode;
use Carbon\Carbon;

/**
 * The stock-valuation engine for ZeroBook. Mirrors BalanceService's shape: one
 * authoritative place computes item balances, and the ONLY place a cost rate is
 * ever produced — the client never sends a cost.
 *
 * VALUATION: weighted average. On every movement the running (qty, value) pair is
 * folded chronologically; an OUT draws at the average in force at that moment
 * (value/qty), so the average is unchanged by a sale and only shifts on a purchase.
 *
 * TWO RATES, NEVER CONFUSED:
 *   • rate / value  on a stock_entries row = COST (this engine computes it).
 *   • sale_rate / sale_value                = SELLING price (user-entered revenue).
 */
class StockService
{
    /**
     * The item's opening balance from its master columns.
     *
     * @return array{qty: float, rate: float, value: float}
     */
    public function openingBalance(int $stockItemId): array
    {
        $item = StockItem::find($stockItemId);
        if (! $item) {
            return ['qty' => 0.0, 'rate' => 0.0, 'value' => 0.0];
        }

        return [
            'qty' => (float) $item->opening_qty,
            'rate' => (float) $item->opening_rate,
            'value' => (float) $item->opening_value,
        ];
    }

    /**
     * The item's closing balance as of a date: opening folded with every movement
     * dated on/before $asOf, valued weighted-average. For an item with no movement
     * this returns the opening balance UNCHANGED (byte-for-byte the 6A contract).
     *
     * @return array{qty: float, value: float}
     */
    public function closingBalance(int $stockItemId, Carbon $asOf): array
    {
        // Phase 13 — a FIFO/LIFO item is valued from its specific remaining lots (Σ remaining × rate),
        // not the running average. Weighted-average items keep the unchanged fold.
        $method = (string) (StockItem::whereKey($stockItemId)->value('costing_method') ?? 'weighted_average');
        if ($method === 'fifo' || $method === 'lifo') {
            return app(StockLotService::class)->fifoLifoClosing($stockItemId, $asOf);
        }

        $state = $this->fold($stockItemId, $asOf, null, false);

        return ['qty' => $state['qty'], 'value' => $state['value']];
    }

    /**
     * Company-wide OPENING stock value = Σ every item's master opening_value.
     * Period-independent (the stock the business started with) — Phase 6D's P&L
     * "Opening Stock" figure. A company with no stock items returns 0.0, which is
     * exactly what makes the inventory correction term vanish (regression-safe).
     */
    public function totalOpeningValue(): float
    {
        return (float) StockItem::sum('opening_value');
    }

    /**
     * Company-wide CLOSING stock value as of a date = Σ every item's
     * closingBalance($id, $asOf)['value'] — Phase 6D's P&L "Closing Stock" and the
     * Balance-Sheet "Stock-in-Hand" figure. A no-item company returns 0.0. This
     * loops the existing per-item weighted-average fold; for a very large catalogue
     * that is a documented future optimisation, not a correctness concern.
     */
    public function totalClosingValue(Carbon $asOf): float
    {
        $total = 0.0;
        foreach (StockItem::pluck('id') as $id) {
            $total += $this->closingBalance((int) $id, $asOf)['value'];
        }

        return $total;
    }

    /**
     * The weighted-average COST rate an OUT (sale) should draw at: the average in
     * force from every movement dated on/before $asOf, EXCLUDING the voucher being
     * posted itself (so a sale draws at the pre-voucher average, and re-posting an
     * altered voucher never counts its own old rows).
     *
     * Called inside the post transaction, after the item rows are locked. If the
     * item has no priced stock (running qty <= 0), falls back to the last known
     * average, then the opening rate — never a divide-by-zero.
     *
     * $lock MUST be true on the post path. The item-master lockForUpdate in
     * persistItems() serialises concurrent writers, but under InnoDB's default
     * REPEATABLE READ the transaction's consistent-read snapshot is pinned at its
     * first non-locking read (Voucher::nextNumber's SELECT MAX, well before the
     * lock). A plain SELECT of stock_entries would therefore read that stale
     * snapshot and MISS a purchase that committed while we waited for the lock —
     * costing the sale at a stale average. A locking (FOR UPDATE) read is a
     * *current* read: it bypasses the snapshot and sees the latest committed rows.
     */
    public function weightedAverageRate(int $stockItemId, Carbon $asOf, ?int $excludeVoucherId = null, bool $lock = false): float
    {
        $state = $this->fold($stockItemId, $asOf, $excludeVoucherId, $lock);

        return $state['qty'] > 1e-9 ? $state['value'] / $state['qty'] : $state['last_avg'];
    }

    /**
     * Fold opening + chronological movements into a running (qty, value, last_avg).
     * The single shared kernel behind closingBalance() and weightedAverageRate().
     * $lock=true issues a FOR UPDATE current read (post path only); the report path
     * (closingBalance) reads the snapshot and takes no locks.
     *
     * @return array{qty: float, value: float, last_avg: float}
     */
    private function fold(int $stockItemId, Carbon $asOf, ?int $excludeVoucherId, bool $lock = false): array
    {
        $item = StockItem::find($stockItemId);
        if (! $item) {
            return ['qty' => 0.0, 'value' => 0.0, 'last_avg' => 0.0];
        }

        $qty = (float) $item->opening_qty;
        $value = (float) $item->opening_value;
        $lastAvg = $qty > 1e-9 ? $value / $qty : (float) $item->opening_rate;

        // Ordered by (voucher date, stock_entries id) so movement is strictly
        // chronological and deterministic within a day.
        $rows = StockEntry::query()
            ->join('vouchers', 'vouchers.id', '=', 'stock_entries.voucher_id')
            ->where('stock_entries.stock_item_id', $stockItemId)
            ->whereDate('vouchers.date', '<=', $asOf->toDateString())
            // Phase 15C — the stock-valuation choke point: a provisional movement affects the running
            // weighted-average only when its scenario is in view (default = real books only).
            ->tap(fn ($q) => \App\Support\ScenarioContext::apply($q, 'vouchers'))
            ->when($excludeVoucherId, fn ($q) => $q->where('stock_entries.voucher_id', '!=', $excludeVoucherId))
            ->orderBy('vouchers.date')
            ->orderBy('stock_entries.id')
            // The FOR-UPDATE current read defeats the REPEATABLE-READ snapshot so a
            // concurrent committer's purchase is seen. During a bulk import there is
            // no other committer and every prior movement is this transaction's own
            // (always visible to a plain read), so the lock is skipped as overhead.
            ->when($lock && ! BulkMode::isActive(), fn ($q) => $q->lockForUpdate())
            ->get(['stock_entries.direction', 'stock_entries.movement_type', 'stock_entries.quantity', 'stock_entries.rate', 'stock_entries.value']);

        foreach ($rows as $r) {
            // An inter-godown TRANSFER is value-neutral at the item level (its OUT and
            // IN cancel), so it must NOT participate in the item's running value or
            // average — otherwise a later rate change makes the frozen IN and the
            // live-average OUT diverge and corrupt the total. It still moves godown
            // quantity, which godownQuantity() (not this fold) accounts for.
            if ($r->movement_type === 'transfer') {
                continue;
            }
            $rQty = (float) $r->quantity;
            if ($r->direction === 'in') {
                $qty += $rQty;
                $value += (float) $r->value;
                if ($qty > 1e-9) {
                    $lastAvg = $value / $qty;
                }
            } else {
                // OUT draws at the average in force now; that leaves the average
                // unchanged and reduces value proportionally to qty.
                $avg = $qty > 1e-9 ? $value / $qty : $lastAvg;
                $lastAvg = $avg;
                $qty -= $rQty;
                $value -= $rQty * $avg;
            }
        }

        return ['qty' => $qty, 'value' => $value, 'last_avg' => $lastAvg];
    }

    /**
     * Persist one stock_entries row per item line, inside the caller's post
     * transaction (the SAME transaction as the money entries). This is the only
     * path that writes stock movement.
     *
     *   IN  (purchase): the entered rate IS the cost → rate/value carry it,
     *                   sale_rate/sale_value stay null.
     *   OUT (sale):     cost = weighted-average as of the voucher date (excluding
     *                   this voucher) → rate/value; the user's selling figures →
     *                   sale_rate/sale_value. The cost is NEVER taken from $items.
     *
     * $items: list of ['stock_item_id','godown_id'?,'qty','rate','amount','direction'].
     */
    public function persistItems(Voucher $voucher, array $items): void
    {
        if (empty($items)) {
            return;
        }

        $this->lockItems(collect($items)->pluck('stock_item_id')->all());

        // Phase 12C-1 → 13 — the stock-lot layer. Resolved ONCE per voucher: null for every ungrouped
        // company / non-inter-company voucher, in which case the inter-company hooks below cost one
        // in-memory branch (the CA-firm guarantee); FIFO/LIFO items add their own lot path per row.
        $lots = app(\App\Services\StockLotService::class);
        $lotSource = $lots->enabledForVoucher($voucher);
        // One group lookup per VOUCHER for the OUT gate too (app() returns a fresh
        // InterCompanyService per call, so gating inside depleteOnOut would re-query
        // the group per ROW — the CA-firm zero-cost promise is per-voucher).
        // lotSource true implies grouped; otherwise resolve the group ONCE here.
        $icEnabled = $lotSource || app(\App\Services\InterCompanyService::class)->enabled();

        // Phase 13 — the per-item costing method drives the OUT cost derivation + IN lot writing.
        // Preloaded once so the loop makes no per-row master query.
        $methodMap = StockItem::whereIn('id', collect($items)->pluck('stock_item_id')->map(fn ($i) => (int) $i)->unique()->all())
            ->pluck('costing_method', 'id')->all();

        $lineNo = 1;
        foreach ($items as $it) {
            $itemId = (int) $it['stock_item_id'];
            $dir = ($it['direction'] ?? 'out') === 'in' ? 'in' : 'out';
            $qty = (float) $it['qty'];
            $godownId = ! empty($it['godown_id']) ? (int) $it['godown_id'] : null;
            $method = (string) ($methodMap[$itemId] ?? 'weighted_average');
            $isLotCosted = $method === 'fifo' || $method === 'lifo';

            if ($dir === 'in') {
                if (in_array($voucher->type, ['credit_note', 'rejection_in'], true)) {
                    // Phase 8A/8B — a Sales Return (Credit Note) or a customer Rejection
                    // In brings stock back IN. Its cost is the ORIGINAL sale's locked
                    // cost (looked up from the referenced sale / delivery), NOT the
                    // client's rate and NOT a made-up figure — so the return does not
                    // distort the running weighted average. sale_rate/value carry the
                    // credited-back (refund) amount, kept separate from cost. For a
                    // Rejection In the sale_* figures are informational (no ledger side).
                    $rate = $this->salesReturnCost($voucher, $itemId);
                    $value = round($qty * $rate, 2);
                    $saleRate = (float) $it['rate'];
                    $saleValue = round((float) $it['amount'], 2);
                } else {
                    // Purchase: the entered rate IS the cost.
                    $rate = (float) $it['rate'];
                    $value = round((float) $it['amount'], 2);
                    $saleRate = null;
                    $saleValue = null;
                }
            } else {
                // Sales / Debit Note (purchase return): cost is computed server-side,
                // authoritatively, with a LOCKING read (true) so it sees purchases
                // committed while we waited for the item lock — not a stale
                // REPEATABLE-READ snapshot. The selling / debited figures are the
                // user's entered rate.
                if ($isLotCosted) {
                    // Phase 13 — FIFO/LIFO: cost comes from the specific lots this OUT depletes,
                    // written onto the row by costRegularOut() AFTER it is created (below). Placeholder.
                    $rate = 0.0;
                    $value = 0.0;
                } else {
                    $rate = $this->weightedAverageRate($itemId, $voucher->date, $voucher->id, true);
                    $value = round($qty * $rate, 2);
                }
                $saleRate = (float) $it['rate'];
                $saleValue = round((float) $it['amount'], 2);
            }

            $entry = StockEntry::create([
                'voucher_id' => $voucher->id,
                'stock_item_id' => $itemId,
                'godown_id' => $godownId,
                'direction' => $dir,
                'movement_type' => Voucher::movementTypeFor($voucher->type),
                'quantity' => $qty,
                'rate' => $rate,
                'value' => $value,
                'sale_rate' => $saleRate,
                'sale_value' => $saleValue,
                'line_no' => $lineNo++,
            ]);

            // Phase 12C-1 → 13 — the lot layer, riding this same transaction:
            //   • inter-company IN  → provenance lot (weighted-average items; cost already final);
            //   • FIFO/LIFO IN      → costing lot (writeLotForRegular);
            //   • FIFO/LIFO OUT     → deplete lots + write the derived cost onto this row;
            //   • weighted-avg OUT  → provenance depletion only (a no-op without lots).
            if ($dir === 'in') {
                if ($lotSource) {
                    $lots->writeLotFor($voucher, $entry);
                } elseif ($isLotCosted) {
                    $lots->writeLotForRegular($voucher, $entry, $method);
                }
            } elseif ($isLotCosted) {
                $lots->costRegularOut($voucher, $entry, $method);
            } elseif ($icEnabled) {
                $lots->depleteOnOut($voucher, $entry, preGated: true);
            }
        }
    }

    /**
     * The cost rate a Credit Note's (sales-return) IN row should carry. When the Note
     * references the original sale, it is that sale's ORIGINAL locked OUT cost — so
     * the stock comes back at exactly what it left at and the running average is
     * undistorted. Without a reference (a free-standing credit), it falls back to the
     * current weighted average (the documented fallback). Never the client's rate.
     */
    private function salesReturnCost(Voucher $voucher, int $itemId): float
    {
        if ($voucher->reference_voucher_id) {
            $original = StockEntry::query()
                ->where('voucher_id', $voucher->reference_voucher_id)
                ->where('stock_item_id', $itemId)
                ->where('direction', 'out')
                ->orderBy('id')
                ->value('rate');
            if ($original !== null) {
                return (float) $original;
            }
        }

        return $this->weightedAverageRate($itemId, $voucher->date, $voucher->id, true);
    }

    /**
     * Serialise concurrent posts touching the same item(s): lock every distinct item
     * master row up front, in id order (deadlock-safe), so weighted-average reads are
     * consistent and two movements of one item cannot interleave. The single lock
     * gate reused by every stock-writing path (item invoices AND Phase 6C vouchers).
     */
    private function lockItems(array $stockItemIds): void
    {
        // A single-threaded import inside one transaction has no concurrent writer to
        // serialise against, so the item-master lock is skipped (its only purpose is
        // to make two live posters take turns). Correctness of the fold is unchanged.
        if (BulkMode::isActive()) {
            return;
        }
        $ids = collect($stockItemIds)->map(fn ($i) => (int) $i)->filter()->unique()->sort()->values()->all();
        if ($ids) {
            StockItem::whereIn('id', $ids)->lockForUpdate()->get();
        }
    }

    /**
     * The running quantity of an item AT a specific godown as of a date. Weighted
     * average is item-level (value is never split per godown), but *quantity* is
     * tracked per godown so transfers and physical stock-takes reconcile the right
     * location. Opening stock counts only at the item's opening godown.
     *
     * $lock issues a FOR UPDATE current read (post path) — same discipline as fold().
     */
    public function godownQuantity(int $stockItemId, ?int $godownId, Carbon $asOf, ?int $excludeVoucherId = null, bool $lock = false): float
    {
        $item = StockItem::find($stockItemId);
        if (! $item) {
            return 0.0;
        }

        $qty = ((int) $item->opening_godown_id === (int) $godownId) ? (float) $item->opening_qty : 0.0;

        $rows = StockEntry::query()
            ->join('vouchers', 'vouchers.id', '=', 'stock_entries.voucher_id')
            ->where('stock_entries.stock_item_id', $stockItemId)
            ->where('stock_entries.godown_id', $godownId)
            ->whereDate('vouchers.date', '<=', $asOf->toDateString())
            // Phase 15C — per-godown running quantity respects the scenario selection (default real).
            ->tap(fn ($q) => \App\Support\ScenarioContext::apply($q, 'vouchers'))
            ->when($excludeVoucherId, fn ($q) => $q->where('stock_entries.voucher_id', '!=', $excludeVoucherId))
            ->when($lock && ! BulkMode::isActive(), fn ($q) => $q->lockForUpdate())
            ->get(['stock_entries.direction', 'stock_entries.quantity']);

        foreach ($rows as $r) {
            $qty += $r->direction === 'in' ? (float) $r->quantity : -(float) $r->quantity;
        }

        return $qty;
    }

    /**
     * Stock Journal — TRANSFER. The same item moves from one godown to another, so
     * the weighted-average cost is computed ONCE (locked, current read, excluding
     * this voucher) and BOTH rows carry it: an OUT from the source and an IN to the
     * destination at that identical rate. Net effect on the item's total qty and
     * value is exactly zero — nothing left the business, only the location changed.
     */
    public function persistTransfer(Voucher $voucher, int $stockItemId, ?int $fromGodownId, ?int $toGodownId, float $qty): void
    {
        $this->lockItems([$stockItemId]);
        $rate = $this->weightedAverageRate($stockItemId, $voucher->date, $voucher->id, true);
        $value = round($qty * $rate, 2);

        StockEntry::create([
            'voucher_id' => $voucher->id, 'stock_item_id' => $stockItemId, 'godown_id' => $fromGodownId,
            'direction' => 'out', 'movement_type' => 'transfer', 'quantity' => $qty, 'rate' => $rate, 'value' => $value,
            'sale_rate' => null, 'sale_value' => null, 'line_no' => 1,
        ]);
        $inLeg = StockEntry::create([
            'voucher_id' => $voucher->id, 'stock_item_id' => $stockItemId, 'godown_id' => $toGodownId,
            'direction' => 'in', 'movement_type' => 'transfer', 'quantity' => $qty, 'rate' => $rate, 'value' => $value,
            'sale_rate' => null, 'sale_value' => null, 'line_no' => 2,
        ]);

        // Phase 12C-1 → 13 — a transfer MOVES lot allocation with the goods: remaining qty at the
        // source godown splits into child lots at the destination (inherited received_date keeps the
        // FIFO order original), so a later sale from the destination still depletes the oldest lot.
        // Runs for inter-company items AND general FIFO/LIFO items.
        $method = (string) (StockItem::whereKey($stockItemId)->value('costing_method') ?? 'weighted_average');
        if (app(\App\Services\InterCompanyService::class)->enabled() || in_array($method, ['fifo', 'lifo'], true)) {
            app(\App\Services\StockLotService::class)
                ->transferLots($inLeg, $stockItemId, $fromGodownId, $toGodownId, $qty, (string) $voucher->date);
        }
    }

    /**
     * Stock Journal — CONSUMPTION / ISSUE (internal use, wastage). A single OUT row
     * costed by the SAME locked weighted-average a sale uses. No revenue, no ledger
     * line — a pure quantity/value reduction.
     */
    public function persistConsumption(Voucher $voucher, int $stockItemId, ?int $godownId, float $qty): void
    {
        $this->lockItems([$stockItemId]);
        $method = (string) (StockItem::whereKey($stockItemId)->value('costing_method') ?? 'weighted_average');
        $isLotCosted = in_array($method, ['fifo', 'lifo'], true);
        // FIFO/LIFO: cost comes from the lots depleted (set below). Weighted-average: the running average.
        $rate = $isLotCosted ? 0.0 : $this->weightedAverageRate($stockItemId, $voucher->date, $voucher->id, true);

        $entry = StockEntry::create([
            'voucher_id' => $voucher->id, 'stock_item_id' => $stockItemId, 'godown_id' => $godownId,
            'direction' => 'out', 'movement_type' => 'consumption', 'quantity' => $qty, 'rate' => $rate, 'value' => round($qty * $rate, 2),
            'sale_rate' => null, 'sale_value' => null, 'line_no' => 1,
        ]);

        // Phase 12C-1 → 13 — consumed units leave the books: FIFO/LIFO items deplete lots + take the
        // derived cost onto the row; weighted-average items just deplete inter-company provenance lots.
        if ($isLotCosted) {
            app(\App\Services\StockLotService::class)->costRegularOut($voucher, $entry, $method);
        } else {
            app(\App\Services\StockLotService::class)->depleteOnOut($voucher, $entry);
        }
    }

    /**
     * Physical Stock — stock-take reconciliation. book = the godown's running qty as
     * of the voucher date; variance = counted − book. An excess posts an IN and a
     * shortage an OUT, BOTH valued at the item-level weighted-average rate, so a
     * stock-take correction never disturbs the average. Zero variance posts nothing.
     *
     * @return array{book: float, counted: float, variance: float, rate: float, posted: bool}
     */
    public function persistPhysicalStock(Voucher $voucher, int $stockItemId, ?int $godownId, float $countedQty): array
    {
        $this->lockItems([$stockItemId]);
        $method = (string) (StockItem::whereKey($stockItemId)->value('costing_method') ?? 'weighted_average');
        $isLotCosted = in_array($method, ['fifo', 'lifo'], true);
        $book = $this->godownQuantity($stockItemId, $godownId, $voucher->date, $voucher->id, true);
        $rate = $this->weightedAverageRate($stockItemId, $voucher->date, $voucher->id, true);
        $variance = round($countedQty - $book, 4);

        if (abs($variance) < 1e-9) {
            return ['book' => $book, 'counted' => $countedQty, 'variance' => 0.0, 'rate' => $rate, 'posted' => false];
        }

        $dir = $variance > 0 ? 'in' : 'out';
        $moveQty = abs($variance);
        // FIFO/LIFO shortage costs from the lots depleted (set below); an excess (or any weighted-average
        // correction) carries the reconciliation rate. value = rate × qty always holds.
        $entryRate = ($isLotCosted && $dir === 'out') ? 0.0 : $rate;
        $entry = StockEntry::create([
            'voucher_id' => $voucher->id, 'stock_item_id' => $stockItemId, 'godown_id' => $godownId,
            'direction' => $dir, 'movement_type' => 'physical', 'quantity' => $moveQty, 'rate' => $entryRate, 'value' => round($moveQty * $entryRate, 2),
            'sale_rate' => null, 'sale_value' => null, 'line_no' => 1,
        ]);

        // Phase 12C-1 → 13 — a SHORTAGE removes units (FIFO/LIFO items deplete lots + take the derived
        // cost; weighted-average items deplete inter-company provenance lots). An EXCESS of a FIFO/LIFO
        // item is of unknowable origin — it is admitted as a new lot at the reconciliation rate so it
        // can be depleted later; for a weighted-average item no lot is written (12C-1 behaviour).
        if ($dir === 'out') {
            if ($isLotCosted) {
                app(\App\Services\StockLotService::class)->costRegularOut($voucher, $entry, $method);
                $rate = (float) $entry->rate;
            } else {
                app(\App\Services\StockLotService::class)->depleteOnOut($voucher, $entry);
            }
        } elseif ($isLotCosted) {
            app(\App\Services\StockLotService::class)->writeLotForRegular($voucher, $entry, $method);
        }

        return ['book' => $book, 'counted' => $countedQty, 'variance' => $variance, 'rate' => $rate, 'posted' => true];
    }
}
